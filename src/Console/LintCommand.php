<?php

declare(strict_types=1);

namespace Forte\Sheath\Console;

use Composer\InstalledVersions;
use Forte\Parser\ParserOptions;
use Forte\Sheath\Baselines\Baseline;
use Forte\Sheath\Caching\ResultCache;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Configuration\PackagePresets;
use Forte\Sheath\Console\Concerns\WritesNotices;
use Forte\Sheath\Console\Handlers\BaselineHandler;
use Forte\Sheath\Console\Handlers\ConfigHandler;
use Forte\Sheath\Console\Handlers\OutputHandler;
use Forte\Sheath\Console\Handlers\PathHandler;
use Forte\Sheath\Contracts\ProvidesCacheContext;
use Forte\Sheath\Contracts\SharesCacheContext;
use Forte\Sheath\Exceptions\BaselineException;
use Forte\Sheath\Exceptions\ConfigurationException;
use Forte\Sheath\Exceptions\ReporterNotFoundException;
use Forte\Sheath\Files\FileFinder;
use Forte\Sheath\Files\FileSystem;
use Forte\Sheath\Files\PathResolver;
use Forte\Sheath\Fixer;
use Forte\Sheath\Linter;
use Forte\Sheath\Parallel\Config as ParallelConfig;
use Forte\Sheath\Parallel\ParallelProcessingException;
use Forte\Sheath\Parallel\Runner;
use Forte\Sheath\Parsing\BladeParserOptions;
use Forte\Sheath\Parsing\IgnoredRegionRegistry;
use Forte\Sheath\Reporters\ReporterRegistry;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\SheathManager;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Container\BindingResolutionException;
use InvalidArgumentException;
use OutOfBoundsException;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal */
class LintCommand extends Command
{
    use WritesNotices;

    protected const PACKAGE_NAME = 'fortephp/sheath';

    protected $signature = 'sheath:lint
                            {paths?* : Files, directories, or glob patterns to lint}
                            {--config= : Path to configuration file}
                            {--preset=* : Rule preset(s) to run, in place of the ones the config names}
                            {--format=stylish : Output format (stylish, json, compact, unix, checkstyle, github, agent)}
                            {--output= : Write results to file instead of stdout}
                            {--fix : Automatically fix problems}
                            {--dangerous : Include dangerous fixes when using --fix or --dry-run}
                            {--fix-dangerous : Alias for --dangerous}
                            {--dry-run : Show what would be fixed without writing changes}
                            {--max-warnings=-1 : Exit with error code if warnings exceed threshold}
                            {--ignore-pattern=* : Patterns to exclude}
                            {--no-ignore : Disable ignore patterns}
                            {--print-config : Output the resolved configuration}
                            {--cache : Cache results to skip unchanged files}
                            {--cache-location=.sheath-cache : Custom cache file location}
                            {--stdin : Lint code from standard input}
                            {--stdin-filename= : Filename to use for stdin input}
                            {--rule=* : Override rule configuration (format: rule-id:severity)}
                            {--only=* : Run only specified rules (comma-separated)}
                            {--no-inline-config : Ignore sheath-disable comments in templates}
                            {--stats : Show performance statistics}
                            {--views : Shorthand for resources/views}
                            {--components : Shorthand for resources/views/components}
                            {--emails : Shorthand for resources/views/emails}
                            {--baseline= : Use baseline file to ignore known violations}
                            {--generate-baseline : Generate baseline from current violations}
                            {--update-baseline : Update baseline with current violations}
                            {--ignore-baseline : Run without baseline filtering}
                            {--parallel : Enable parallel processing using multiple CPU cores}
                            {--parallel-if-available : Enable parallel processing when its optional dependencies are installed}
                            {--processes= : Number of parallel processes (default: auto-detect)}';

    protected $description = 'Run the Sheath linter on your Blade files, helping to remove sharp edges.';

    /** @var array<string, int|float> */
    protected array $stats = [
        'filesProcessed' => 0,
        'filesFromCache' => 0,
        'totalTime' => 0.0,
        'lintTime' => 0.0,
    ];

    protected bool $hadIoFailures = false;

    public function __construct(
        protected FileFinder $fileFinder,
        protected ReporterRegistry $reporterRegistry,
        protected RuleRegistry $ruleRegistry,
        protected ResultCache $cache,
        protected IgnoredRegionRegistry $ignoredRegionRegistry,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->resetRunState();
        $startTime = microtime(true);

        $configHandler = new ConfigHandler($this, $this->ruleRegistry);
        $outputHandler = new OutputHandler($this, $this->reporterRegistry);
        $pathHandler = new PathHandler($this->fileFinder);

        try {
            $this->assertOptionCompatibility();
            $this->noticeIgnoredOptions();
            $this->resolveMaxWarnings();
            $format = $this->option('format');
            $outputHandler->validateFormat(is_string($format) ? $format : 'stylish');
            $config = $this->resolveConfig($configHandler);

            if ($this->option('print-config')) {
                $configHandler->print($config);

                return self::SUCCESS;
            }

            $this->warnUnavailableConfiguredRules($config);
            $this->warnEmptyPresetContributions($config);

            if ($this->option('stdin')) {
                $exitCode = $this->lintStdin($config, $outputHandler);
                $this->finishStats($outputHandler, $startTime);

                return $exitCode;
            }

            $files = $this->findConfiguredFiles($pathHandler, $config);

            if (empty($files)) {
                $this->noticeError('No files found to lint.');

                return self::FAILURE;
            }

            $this->enableCache($config);

            $results = $this->shouldUseParallel()
                ? $this->lintFilesParallel($files, $config)
                : $this->lintFiles($files, $config);

            $results = $this->handleBaseline($results, $config);

            if ($this->option('fix') || $this->option('dry-run')) {
                $results = $this->applyFixes($results, $config);
            }

            if ($this->option('cache') && ! $this->cache->save()) {
                $cacheLocation = $this->option('cache-location');
                $path = is_string($cacheLocation) ? $cacheLocation : '.sheath-cache';
                $this->noticeWarning("Could not write cache file: {$path}");
                $this->hadIoFailures = true;
            }

            $this->outputResults($outputHandler, $results);
            $this->finishStats($outputHandler, $startTime);

            return $this->getExitCode($results);
        } catch (BaselineException|ConfigurationException|InvalidArgumentException|ReporterNotFoundException $e) {
            $this->noticeError($e->getMessage());

            return self::FAILURE;
        }
    }

    private function resolveConfig(ConfigHandler $handler): Config
    {
        $configPath = $this->option('config');
        $config = $handler->load(
            is_string($configPath) ? $configPath : null,
            $this->presetOverride()
        );
        $this->ruleRegistry->setPackageRequirementMode($config->getPackageRequirementMode());

        /** @var array<string>|string|null $ruleOption */
        $ruleOption = $this->option('rule');
        /** @var array<string>|string|null $onlyOption */
        $onlyOption = $this->option('only');
        $config = $handler->applyOverrides($config, $ruleOption, $onlyOption);

        if ($this->option('no-inline-config')) {
            $config->setInlineSuppressions(false);
        }

        $this->refreshPackageRuleStats();
        $this->stats['presetRulesUnavailable'] = $this->countUnavailablePresetRules($config);

        return $config;
    }

    /** @return array<string> */
    private function findConfiguredFiles(PathHandler $handler, Config $config): array
    {
        /** @var array<string> $argumentPaths */
        $argumentPaths = $this->argument('paths') ?? [];
        $shortcuts = [
            'views' => (bool) $this->option('views'),
            'components' => (bool) $this->option('components'),
            'emails' => (bool) $this->option('emails'),
        ];
        $paths = $handler->resolvePaths($argumentPaths, $shortcuts, $config);
        $pathsWereNamed = $handler->namedPaths($argumentPaths, $shortcuts) !== [];

        /** @var array<string> $ignorePatternOption */
        $ignorePatternOption = $this->option('ignore-pattern') ?? [];
        $ignorePatterns = $this->option('no-ignore')
            ? []
            : array_merge($config->getIgnore(), $ignorePatternOption);

        return $handler->findFiles($paths, $ignorePatterns, $pathsWereNamed);
    }

    private function enableCache(Config $config): void
    {
        if (! $this->option('cache')) {
            return;
        }

        $cacheLocation = $this->option('cache-location');
        $path = is_string($cacheLocation) ? $cacheLocation : '.sheath-cache';

        $this->cache
            ->setPath(PathResolver::toAbsolutePath($path))
            ->setContext($this->buildCacheContext($config))
            ->enable();
    }

    /**
     * @param  array<LintResult>  $results
     *
     * @throws ReporterNotFoundException
     */
    private function outputResults(OutputHandler $handler, array $results): void
    {
        $format = $this->option('format');
        $output = $this->option('output');

        if (! $handler->output(
            $results,
            is_string($format) ? $format : 'stylish',
            is_string($output) ? $output : null
        )) {
            $this->hadIoFailures = true;
        }
    }

    private function finishStats(OutputHandler $handler, float $startTime): void
    {
        $this->stats['totalTime'] = microtime(true) - $startTime;

        if (! $this->option('stats')) {
            return;
        }

        /** @var array<string, int|float> $stats */
        $stats = $this->stats;
        $handler->showStats($stats);
    }

    /**
     * @param  array<string>  $files
     * @return array<LintResult>
     */
    protected function lintFiles(array $files, Config $config): array
    {
        return $this->lintFilesSequential($files, $config, readCache: true);
    }

    /**
     * @param  array<string>  $files
     * @return array<LintResult>
     */
    protected function lintFilesParallel(array $files, Config $config): array
    {
        $processCount = $this->resolveProcessCount();

        if (! $this->isParallelAvailable()) {
            $this->noticeWarning('Parallel processing not available, falling back to sequential mode.');

            return $this->lintFiles($files, $config);
        }

        $cachedResults = [];
        $filesToProcess = [];
        $useCache = (bool) $this->option('cache');

        foreach ($files as $file) {
            if ($useCache && $this->cache->has($file)) {
                $result = $this->cache->get($file);
                if ($result !== null) {
                    $cachedResults[] = $result;
                    $filesFromCache = $this->stats['filesFromCache'];
                    $this->stats['filesFromCache'] = is_int($filesFromCache) ? $filesFromCache + 1 : 1;

                    continue;
                }
            }
            $filesToProcess[] = $file;
        }

        if (empty($filesToProcess)) {
            $this->incrementFilesProcessed(count($files));

            return $this->orderResultsByFile($files, $cachedResults);
        }

        $parallelConfig = $this->detectParallelConfig($processCount);

        if (! $parallelConfig->shouldRunInParallel(count($filesToProcess))) {
            $this->incrementFilesProcessed(count($cachedResults));
            $sequentialResults = $this->lintFilesSequential($filesToProcess, $config);

            return $this->orderResultsByFile($files, array_merge($cachedResults, $sequentialResults));
        }

        $this->notice(sprintf(
            'Running in parallel with %d processes (%d files, %d from cache)...',
            $parallelConfig->getWorkerCount(count($filesToProcess)),
            count($filesToProcess),
            count($cachedResults)
        ));

        $runner = $this->makeRunner($parallelConfig);

        $progressBar = $this->getOutput()->getErrorStyle()->createProgressBar(count($filesToProcess));
        $progressBar->setFormat(' %current%/%max% [%bar%] %percent:3s%% %elapsed:6s%');
        $progressBar->start();

        $runner->onProgress(function (int $processed, int $total) use ($progressBar): void {
            $progressBar->setProgress($processed);
        });

        try {
            $lintStart = microtime(true);
            $parallelResults = $runner->run($filesToProcess, $config);
            $this->addLintTime(microtime(true) - $lintStart);
            $this->incrementFilesProcessed(count($files));

            $progressBar->finish();
            $this->noticeNewLine();

            $expectedPaths = array_fill_keys($filesToProcess, true);
            $seenPaths = [];
            $lintResults = [];

            foreach ($parallelResults as $result) {
                if (! $result instanceof LintResult) {
                    $this->noticeWarning('Parallel worker returned an invalid result type.');
                    $this->hadIoFailures = true;

                    continue;
                }

                if (! isset($expectedPaths[$result->filePath])) {
                    $this->noticeWarning("Parallel worker returned a result for an unexpected file: {$result->filePath}");
                    $this->hadIoFailures = true;

                    continue;
                }

                if (isset($seenPaths[$result->filePath])) {
                    $this->noticeWarning("Parallel worker returned more than one result for: {$result->filePath}");
                    $this->hadIoFailures = true;

                    continue;
                }

                $seenPaths[$result->filePath] = true;
                $lintResults[] = $result;

                if ($useCache) {
                    $this->cache->put($result->filePath, $result);
                }
            }

            foreach (array_diff_key($expectedPaths, $seenPaths) as $missingPath => $_) {
                $this->noticeWarning("Parallel worker returned no result for: {$missingPath}");
                $this->hadIoFailures = true;
            }

            foreach ($runner->getErrors() as $error) {
                $this->noticeWarning($error);
                $this->hadIoFailures = true;
            }

            // Parallel completion order must not change report order.
            $byPath = [];
            foreach ($lintResults as $result) {
                $byPath[$result->filePath] = $result;
            }

            $ordered = [];
            foreach ($filesToProcess as $file) {
                if (isset($byPath[$file])) {
                    $ordered[] = $byPath[$file];
                    unset($byPath[$file]);
                }
            }

            $lintResults = array_merge($ordered, array_values($byPath));

            return $this->orderResultsByFile($files, array_merge($cachedResults, $lintResults));
        } catch (ParallelProcessingException $e) {
            $progressBar->finish();
            $this->noticeNewLine();

            $this->noticeWarning('Parallel processing failed: '.$e->getMessage());
            $this->noticeWarning('Falling back to sequential mode.');

            $remainingFiles = $e->getFiles() ?: $filesToProcess;
            $this->incrementFilesProcessed(count($cachedResults));
            $sequentialResults = $this->lintFilesSequential($remainingFiles, $config);

            return $this->orderResultsByFile($files, array_merge($cachedResults, $sequentialResults));
        }
    }

    /**
     * @param  array<string>  $files
     * @param  array<LintResult>  $results
     * @return array<LintResult>
     */
    private function orderResultsByFile(array $files, array $results): array
    {
        $byPath = [];
        foreach ($results as $result) {
            $byPath[$result->filePath] = $result;
        }

        $ordered = [];
        foreach ($files as $file) {
            if (! isset($byPath[$file])) {
                continue;
            }

            $ordered[] = $byPath[$file];
            unset($byPath[$file]);
        }

        return array_merge($ordered, array_values($byPath));
    }

    /**
     * @param  array<string>  $files
     * @return array<LintResult>
     */
    private function lintFilesSequential(array $files, Config $config, bool $readCache = false): array
    {
        $linter = $this->makeLinter();
        $results = [];
        $useCache = (bool) $this->option('cache');

        foreach ($files as $file) {
            $this->incrementFilesProcessed();
            $result = null;
            $content = null;

            if ($readCache && $useCache) {
                $content = FileSystem::readFile($file);
                if ($content === null) {
                    $this->noticeWarning("Could not read file: {$file}");
                    $this->hadIoFailures = true;

                    continue;
                }

                if ($this->cache->has($file, $content)) {
                    $result = $this->cache->get($file);

                    if ($result !== null) {
                        $filesFromCache = $this->stats['filesFromCache'];
                        $this->stats['filesFromCache'] = is_int($filesFromCache) ? $filesFromCache + 1 : 1;
                    }
                }
            }

            if ($result !== null) {
                $results[] = $result;

                continue;
            }

            $lintStart = microtime(true);
            $content ??= FileSystem::readFile($file);
            if ($content === null) {
                $this->noticeWarning("Could not read file: {$file}");
                $this->hadIoFailures = true;

                continue;
            }
            $result = $linter->lint($content, $file, $config);
            $this->addLintTime(microtime(true) - $lintStart);

            if ($useCache) {
                $this->cache->put($file, $result, $content);
            }

            $results[] = $result;
        }

        return $results;
    }

    protected function lintStdin(Config $config, OutputHandler $outputHandler): int
    {
        // A BOM would shift first-line offsets and precede a document doctype.
        $content = $this->readStdin();
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        $filenameOption = $this->option('stdin-filename');
        $filename = is_string($filenameOption) ? $filenameOption : 'stdin.blade.php';

        $linter = $this->makeLinter();
        $lintStart = microtime(true);
        $result = $linter->lint($content, $filename, $config);
        $this->incrementFilesProcessed();
        $this->addLintTime(microtime(true) - $lintStart);

        $results = $this->handleBaseline([$result], $config, [$filename => $content]);
        $result = $results[0];

        if ($this->option('fix') && ! $this->option('dry-run')) {
            return $this->fixStdin($linter, $content, $result, $config);
        }

        if ($this->option('dry-run')) {
            return $this->dryRunStdin($linter, $content, $result, $config, $outputHandler);
        }

        $this->outputResults($outputHandler, [$result]);

        return $this->getExitCode([$result]);
    }

    protected function fixStdin(Linter $linter, string $content, LintResult $result, Config $config): int
    {
        $includeDangerous = (bool) $this->option('dangerous') || (bool) $this->option('fix-dangerous');

        [$fixedContent, $fixedResult] = $this->applyFixesUntilStable(
            fixer: new Fixer,
            linter: $linter,
            content: $content,
            result: $result,
            config: $config,
            includeDangerous: $includeDangerous,
        );

        $this->output->write($fixedContent, false, OutputInterface::OUTPUT_RAW);

        return $this->getExitCode([$fixedResult]);
    }

    protected function dryRunStdin(
        Linter $linter,
        string $content,
        LintResult $result,
        Config $config,
        OutputHandler $outputHandler,
    ): int {
        $includeDangerous = (bool) $this->option('dangerous') || (bool) $this->option('fix-dangerous');

        [, $calculatedResult, , $appliedCount, $stalled] = $this->applyFixesUntilStable(
            fixer: new Fixer,
            linter: $linter,
            content: $content,
            result: $result,
            config: $config,
            includeDangerous: $includeDangerous,
        );

        if ($appliedCount > 0) {
            $this->notice("Would fix {$appliedCount} issue(s) in {$result->filePath}");
            $this->notice("Dry run: would fix {$appliedCount} issue(s) in 1 file(s).");
        }

        $dangerousSkipped = $includeDangerous ? 0 : count(array_filter(
            $calculatedResult->violations,
            static fn ($violation) => $violation->hasDangerousFix()
        ));
        $overlapSkipped = $stalled
            ? max(0, $calculatedResult->getFixableCount() - $dangerousSkipped)
            : 0;
        $totalSkipped = $dangerousSkipped + $overlapSkipped;

        if ($totalSkipped > 0) {
            if ($includeDangerous) {
                $this->noticeWarning("Skipped {$totalSkipped} overlapping/conflicting fix(es).");
            } else {
                $this->noticeWarning("Skipped {$totalSkipped} fix(es) that are dangerous or overlapping. Use --dangerous to include dangerous fixes.");
            }
        }

        $this->outputResults($outputHandler, [$result]);

        return $this->getExitCode([$result]);
    }

    /**
     * @param  array<LintResult>  $results
     */
    protected function getExitCode(array $results): int
    {
        if ($this->hadIoFailures) {
            return self::FAILURE;
        }

        $hasErrors = false;
        $warningCount = 0;

        foreach ($results as $result) {
            if ($result->hasParseErrors || $result->hasErrors()) {
                $hasErrors = true;
            }
            $warningCount += $result->getWarningCount();
        }

        if ($hasErrors) {
            return self::FAILURE;
        }

        $maxWarnings = $this->resolveMaxWarnings();
        if ($maxWarnings >= 0 && $warningCount > $maxWarnings) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function assertOptionCompatibility(): void
    {
        if ($this->option('generate-baseline') && $this->option('update-baseline')) {
            throw new InvalidArgumentException(
                'The --generate-baseline and --update-baseline options are mutually exclusive. Use --generate-baseline for a new baseline, --update-baseline to refresh an existing one.'
            );
        }

        $baselineOperation = $this->option('generate-baseline')
            ? '--generate-baseline'
            : ($this->option('update-baseline') ? '--update-baseline' : null);

        if ($baselineOperation !== null && ($this->option('fix') || $this->option('dry-run'))) {
            $flag = $this->option('fix') ? '--fix' : '--dry-run';

            throw new InvalidArgumentException(
                "The {$flag} and {$baselineOperation} options cannot be combined: the baseline would swallow every violation before fixes are calculated. Run --fix first, then run the baseline operation on what remains."
            );
        }
    }

    protected function noticeIgnoredOptions(): void
    {
        $fixing = $this->option('fix') || $this->option('dry-run');

        if (($this->option('dangerous') || $this->option('fix-dangerous')) && ! $fixing) {
            $this->noticeWarning('The --dangerous option has no effect without --fix or --dry-run.');
        }

        if ($this->option('fix') && $this->option('dry-run')) {
            $this->noticeWarning('Both --fix and --dry-run were given; running as a dry run, no files will be written.');
        }

        if (is_string($this->option('processes'))
            && $this->option('processes') !== ''
            && ! $this->parallelModeRequested()) {
            $this->noticeWarning('The --processes option has no effect without --parallel.');
        }

        if (is_string($this->option('stdin-filename')) && ! $this->option('stdin')) {
            $this->noticeWarning('The --stdin-filename option has no effect without --stdin.');
        }

        $cacheLocation = $this->option('cache-location');
        if (is_string($cacheLocation) && $cacheLocation !== '.sheath-cache' && ! $this->option('cache')) {
            $this->noticeWarning('The --cache-location option has no effect without --cache.');
        }

        /** @var array<string> $paths */
        $paths = $this->argument('paths') ?? [];
        if ($this->option('stdin') && $paths !== []) {
            $this->noticeWarning('Path arguments are ignored with --stdin; only standard input is linted.');
        }

        if ($this->option('stdin') && $this->option('cache')) {
            $this->noticeWarning('The --cache option has no effect with --stdin.');
        }

        if ($this->option('stdin') && $this->parallelModeRequested()) {
            $message = is_string($this->option('processes')) && $this->option('processes') !== ''
                ? 'The --parallel and --processes options have no effect with --stdin.'
                : 'The --parallel option has no effect with --stdin.';
            $this->noticeWarning($message);
        }

        /** @var array<string> $ignorePatterns */
        $ignorePatterns = $this->option('ignore-pattern') ?? [];
        $hasDiscoveryOptions = $ignorePatterns !== []
            || $this->option('no-ignore')
            || $this->option('views')
            || $this->option('components')
            || $this->option('emails');

        if ($this->option('stdin') && $hasDiscoveryOptions) {
            $this->noticeWarning('File discovery and ignore options have no effect with --stdin.');
        }
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function resolveMaxWarnings(): int
    {
        return OutputHandler::parseMaxWarnings($this->option('max-warnings'));
    }

    /**
     * @param  array<LintResult>  $results
     * @param  array<string, string>  $preloadedContents
     * @return array<LintResult>
     *
     * @throws BaselineException
     */
    protected function handleBaseline(array $results, Config $config, array $preloadedContents = []): array
    {
        $generating = (bool) $this->option('generate-baseline');
        $updating = (bool) $this->option('update-baseline');
        $applying = ! $this->option('ignore-baseline') && $this->shouldUseBaseline();

        if (! $generating && ! $updating && ! $applying) {
            return $results;
        }

        $baselinePath = $this->getBaselinePath();
        $handler = new BaselineHandler($this, $baselinePath, $config->getBaselineLineTolerance());

        $filesWithViolations = [];
        foreach ($results as $result) {
            if ($result->violations !== []) {
                $filesWithViolations[$result->filePath] = true;
            }
        }

        foreach (array_intersect_key($preloadedContents, $filesWithViolations) as $file => $content) {
            $handler->preloadFileContent($file, $content);
            unset($filesWithViolations[$file]);
        }
        $handler->preloadFileContents(array_keys($filesWithViolations));

        if ($generating) {
            $baselineResults = $handler->generateBaseline($results);
            if ($baselineResults === null) {
                $this->hadIoFailures = true;

                return $results;
            }

            return $baselineResults;
        }

        if ($updating) {
            $baselineResults = $handler->updateBaseline($results);
            if ($baselineResults === null) {
                $this->hadIoFailures = true;

                return $results;
            }

            return $baselineResults;
        }

        if ($applying) {
            return $handler->applyBaseline($results);
        }

        return $results;
    }

    protected function shouldUseBaseline(): bool
    {
        return $this->option('baseline') !== null || $this->baselinePathExists();
    }

    protected function baselinePathExists(): bool
    {
        return file_exists($this->getBaselinePath());
    }

    protected function getBaselinePath(): string
    {
        $path = $this->option('baseline');

        if (! is_string($path) || $path === '') {
            return base_path(Baseline::DEFAULT_PATH);
        }

        return PathResolver::toAbsolutePath($path);
    }

    /**
     * @param  array<LintResult>  $results
     * @return array<LintResult>
     */
    protected function applyFixes(array $results, Config $config): array
    {
        $fixer = new Fixer;
        $linter = $this->makeLinter();
        $isDryRun = (bool) $this->option('dry-run');
        $includeDangerous = (bool) $this->option('dangerous') || (bool) $this->option('fix-dangerous');
        $totalFixed = 0;
        $totalDangerousSkipped = 0;
        $totalOverlapSkipped = 0;
        $filesFixed = 0;
        $updatedResults = [];
        $useCache = (bool) $this->option('cache');

        foreach ($results as $result) {
            $updatedResult = $result;
            $fixes = $result->getFixes();

            if (empty($fixes)) {
                $updatedResults[] = $updatedResult;

                continue;
            }

            $content = FileSystem::readFile($result->filePath);
            if ($content === null) {
                $this->noticeWarning("Could not read file: {$result->filePath}");
                $this->hadIoFailures = true;
                $updatedResults[] = $updatedResult;

                continue;
            }

            if ($result->sourceHash === null || ! hash_equals($result->sourceHash, hash('xxh128', $content))) {
                [, $updatedResult] = $this->lintCurrentContent($linter, $content, $result->filePath, $config);
                $fixes = $updatedResult->getFixes();

                if ($fixes === []) {
                    $updatedResults[] = $updatedResult;

                    continue;
                }
            }

            $preFixResult = $updatedResult;
            $calculatedResult = $updatedResult;

            $stalled = false;

            if ($isDryRun) {
                [, $calculatedResult, , $appliedCount, $stalled] = $this->applyFixesUntilStable(
                    fixer: $fixer,
                    linter: $linter,
                    content: $content,
                    result: $updatedResult,
                    config: $config,
                    includeDangerous: $includeDangerous,
                );

                if ($appliedCount > 0) {
                    $totalFixed += $appliedCount;
                    $filesFixed++;
                    $this->notice("Would fix {$appliedCount} issue(s) in {$result->filePath}");
                }
            } else {
                [$updatedContent, $updatedResult, $rawUpdatedResult, $appliedCount, $stalled] = $this->applyFixesUntilStable(
                    fixer: $fixer,
                    linter: $linter,
                    content: $content,
                    result: $updatedResult,
                    config: $config,
                    includeDangerous: $includeDangerous,
                );
                $calculatedResult = $updatedResult;

                if ($appliedCount > 0) {
                    if (! FileSystem::writeFileAtomicallyIfUnchanged($result->filePath, $content, $updatedContent)) {
                        $currentContent = FileSystem::readFile($result->filePath);
                        if ($currentContent !== null && ! hash_equals($content, $currentContent)) {
                            $this->noticeWarning("File changed while fixes were being calculated; not writing: {$result->filePath}");
                            [, $preFixResult] = $this->lintCurrentContent($linter, $currentContent, $result->filePath, $config);
                        } else {
                            $this->noticeWarning("Could not write to file: {$result->filePath}");
                        }
                        $this->hadIoFailures = true;
                        $updatedResults[] = $preFixResult;

                        continue;
                    }

                    $totalFixed += $appliedCount;
                    $filesFixed++;

                    if ($useCache && $rawUpdatedResult !== null) {
                        $this->cache->put($result->filePath, $rawUpdatedResult, $updatedContent);
                    }
                }
            }

            $dangerousSkipped = $includeDangerous ? 0 : count(array_filter(
                $calculatedResult->violations,
                static fn ($violation) => $violation->hasDangerousFix()
            ));
            $totalDangerousSkipped += $dangerousSkipped;

            if ($stalled) {
                $totalOverlapSkipped += max(0, $calculatedResult->getFixableCount() - $dangerousSkipped);
            }

            $updatedResults[] = $updatedResult;
        }

        if ($totalFixed > 0) {
            if ($isDryRun) {
                $this->notice("Dry run: would fix {$totalFixed} issue(s) in {$filesFixed} file(s).");
            } else {
                $this->notice("Fixed {$totalFixed} issue(s) in {$filesFixed} file(s).");
            }
        }

        $totalSkipped = $totalDangerousSkipped + $totalOverlapSkipped;

        if ($totalSkipped > 0) {
            if ($includeDangerous) {
                $this->noticeWarning("Skipped {$totalSkipped} overlapping/conflicting fix(es).");
            } else {
                $this->noticeWarning("Skipped {$totalSkipped} fix(es) that are dangerous or overlapping. Use --dangerous to include dangerous fixes.");
            }
        }

        return $updatedResults;
    }

    /**
     * @return array{string, LintResult, ?LintResult, int, bool}
     */
    private function applyFixesUntilStable(
        Fixer $fixer,
        Linter $linter,
        string $content,
        LintResult $result,
        Config $config,
        bool $includeDangerous,
        int $maxPasses = SheathManager::MAX_FIX_PASSES,
    ): array {
        $currentContent = $content;
        $currentResult = $result;
        $currentRawResult = null;
        $totalApplied = 0;
        $seenContent = [hash('xxh128', $currentContent) => true];

        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $fixes = $currentResult->getFixes();
            if ($fixes === []) {
                return [$currentContent, $currentResult, $currentRawResult, $totalApplied, false];
            }

            $fixResult = $fixer->applyFixes($currentContent, $fixes, $includeDangerous);
            if ($fixResult->appliedCount === 0) {
                return [$currentContent, $currentResult, $currentRawResult, $totalApplied, true];
            }

            $currentContent = $fixResult->content;
            $totalApplied += $fixResult->appliedCount;
            [$currentRawResult, $currentResult] = $this->lintCurrentContent(
                $linter,
                $currentContent,
                $result->filePath,
                $config,
            );

            $contentHash = hash('xxh128', $currentContent);
            if (isset($seenContent[$contentHash])) {
                return [$currentContent, $currentResult, $currentRawResult, $totalApplied, true];
            }

            $seenContent[$contentHash] = true;
        }

        return [
            $currentContent,
            $currentResult,
            $currentRawResult,
            $totalApplied,
            $currentResult->getFixableCount() > 0,
        ];
    }

    /**
     * @return array{LintResult, LintResult}
     *
     * @throws BaselineException
     */
    private function lintCurrentContent(
        Linter $linter,
        string $content,
        string $filePath,
        Config $config,
    ): array {
        $rawResult = $linter->lint($content, $filePath, $config);
        $filteredResult = $this->handleBaseline([$rawResult], $config, [$filePath => $content])[0];

        return [$rawResult, $filteredResult];
    }

    protected function noticeOutput(): OutputStyle
    {
        return $this->getOutput();
    }

    protected function makeLinter(): Linter
    {
        /** @var Linter $linter */
        $linter = $this->laravel->make(Linter::class);

        return $linter;
    }

    protected function isParallelAvailable(): bool
    {
        return Runner::isAvailable();
    }

    private function parallelModeRequested(): bool
    {
        return $this->option('parallel') || $this->option('parallel-if-available');
    }

    private function shouldUseParallel(): bool
    {
        return (bool) $this->option('parallel')
            || ((bool) $this->option('parallel-if-available') && $this->isParallelAvailable());
    }

    protected function detectParallelConfig(?int $processCount): ParallelConfig
    {
        return ParallelConfig::detect($processCount);
    }

    protected function makeRunner(ParallelConfig $parallelConfig): Runner
    {
        return new Runner(
            LintResult::class,
            'sheath:worker',
            $parallelConfig,
        );
    }

    protected function readStdin(): string
    {
        $content = '';
        while (! feof(STDIN)) {
            $chunk = fread(STDIN, 8192);
            if ($chunk === false) {
                break;
            }

            $content .= $chunk;
        }

        return $content;
    }

    protected function resolveProcessCount(): ?int
    {
        $processesOption = $this->option('processes');
        if (! is_string($processesOption) || $processesOption === '') {
            return null;
        }

        if (! ctype_digit($processesOption) || (int) $processesOption < 1) {
            throw new InvalidArgumentException('The --processes option must be a positive integer.');
        }

        return (int) $processesOption;
    }

    protected function incrementFilesProcessed(int $count = 1): void
    {
        $filesProcessed = $this->stats['filesProcessed'];
        $this->stats['filesProcessed'] = (is_int($filesProcessed) ? $filesProcessed : 0) + $count;
    }

    protected function addLintTime(float $seconds): void
    {
        $lintTime = $this->stats['lintTime'];
        $this->stats['lintTime'] = (is_float($lintTime) ? $lintTime : 0.0) + $seconds;
    }

    protected function resetRunState(): void
    {
        $this->stats = [
            'filesProcessed' => 0,
            'filesFromCache' => 0,
            'packageRulesSkipped' => 0,
            'packageRulesDisabled' => 0,
            'presetRulesUnavailable' => 0,
            'totalTime' => 0.0,
            'lintTime' => 0.0,
        ];
        $this->hadIoFailures = false;
    }

    /**
     * @return array<string>
     */
    protected function presetOverride(): array
    {
        /** @var array<mixed>|string|null $option */
        $option = $this->option('preset');

        $names = [];

        foreach (is_array($option) ? $option : (array) $option as $value) {
            if (! is_string($value)) {
                continue;
            }

            foreach (explode(',', $value) as $name) {
                if (trim($name) !== '') {
                    $names[] = trim($name);
                }
            }
        }

        return $names;
    }

    protected function refreshPackageRuleStats(): void
    {
        $this->stats['packageRulesSkipped'] = count($this->ruleRegistry->getSkippedDueToPackages());
        $this->stats['packageRulesDisabled'] = count($this->ruleRegistry->getDisabledDueToPackages());
    }

    protected function warnEmptyPresetContributions(Config $config): void
    {
        foreach ($config->getPresetContributions() as $presetName => $note) {
            if ($note['contributed'] > 0) {
                continue;
            }

            $this->noticeWarning(sprintf(
                "Preset '%s' contributed no rules to this run%s",
                $presetName,
                $this->describeEmptyContribution($note)
            ));
        }
    }

    /**
     * @param  array{declared: int, contributed: int, skippedDueToPackages: array<string>, unknown: array<string>}  $note
     */
    private function describeEmptyContribution(array $note): string
    {
        $gated = $note['skippedDueToPackages'];
        $unknown = $note['unknown'];

        if ($gated === [] && $unknown === []) {
            return ': it declares no rules.';
        }

        $parts = [];

        if ($gated !== []) {
            $missing = $this->missingPackagesFor($gated);
            $parts[] = sprintf(
                '%d rule(s) skipped due to package requirements%s',
                count($gated),
                $missing !== [] ? ' (missing: '.implode(', ', $missing).')' : ''
            );
        }

        if ($unknown !== []) {
            $parts[] = sprintf('%d rule(s) not registered', count($unknown));
        }

        return ': '.implode('; ', $parts).'.';
    }

    /**
     * @param  array<string>  $ruleIds
     * @return array<string>
     */
    private function missingPackagesFor(array $ruleIds): array
    {
        $missing = [];

        foreach ($ruleIds as $ruleId) {
            $result = $this->ruleRegistry->getPackageRequirementResultForRuleId($ruleId);

            if ($result === null) {
                continue;
            }

            foreach ($result->unmetRequirements as $requirement) {
                $missing[$requirement] = true;
            }
        }

        return array_keys($missing);
    }

    protected function countUnavailablePresetRules(Config $config): int
    {
        $count = 0;

        foreach ($config->getPresetContributions() as $presetName => $note) {
            $count += count($note['skippedDueToPackages']);

            foreach (array_keys(PackagePresets::shared()->declared((string) $presetName) ?? []) as $ruleId) {
                if ($this->ruleRegistry->isDisabledDueToPackages($ruleId)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    protected function warnUnavailableConfiguredRules(Config $config): void
    {
        foreach (array_keys($config->getRules()) as $ruleId) {
            if ($this->ruleRegistry->isSkippedDueToPackages($ruleId)) {
                $this->noticeWarning(sprintf(
                    "Rule '%s' skipped due to package requirements: %s",
                    $ruleId,
                    $this->ruleRegistry->getPackageRequirementResultForRuleId($ruleId)?->getDescription()
                        ?? 'Package requirements unavailable'
                ));

                continue;
            }

            if ($this->ruleRegistry->isDisabledDueToPackages($ruleId)) {
                $this->noticeWarning(sprintf(
                    "Rule '%s' disabled due to package requirements: %s",
                    $ruleId,
                    $this->ruleRegistry->getPackageRequirementResultForRuleId($ruleId)?->getDescription()
                        ?? 'Package requirements unavailable'
                ));
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildCacheContext(Config $config): array
    {
        return [
            'config' => $config->toArray(),
            'packageRulesDisabled' => $this->ruleRegistry->getDisabledDueToPackages(),
            'packageRulesSkipped' => $this->ruleRegistry->getSkippedDueToPackages(),
            'rules' => $this->ruleRegistry->getAllKnownRules(),
            'ruleSources' => $this->ruleSourceContexts(),
            'ruleDependencies' => $this->ruleCacheContexts($config),
            'parser' => $this->parserCacheContext(),
            'ignoredRegionProviders' => $this->ignoredRegionProviderCacheContexts(),
            'dependencies' => $this->installedPackageContexts(),
            'version' => $this->getPackageVersion(),
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ReflectionException|BindingResolutionException
     */
    private function parserCacheContext(): array
    {
        $options = BladeParserOptions::normalize($this->laravel->make(ParserOptions::class));
        $directives = $options->getDirectives();
        $directiveMetadata = $directives->getDirectiveMetadata();
        ksort($directiveMetadata);

        $componentPrefixes = $options->getComponentManager()->getPrefixes();
        sort($componentPrefixes);

        $extensions = [];
        $sourceFileHashes = [];
        foreach ($options->getExtensionRegistry()->all() as $extension) {
            $class = $extension::class;

            $extensions[$extension->id()] = [
                'class' => $class,
                'version' => $extension->version(),
                'options' => $extension->getOptions(),
                'source' => $this->classSourceContext($class, $sourceFileHashes),
            ];
        }
        ksort($extensions);

        return [
            'acceptAllDirectives' => $directives->acceptsAllDirectives(),
            'directives' => $directiveMetadata,
            'componentPrefixes' => $componentPrefixes,
            'depthLimits' => $options->getDepthLimits(),
            'extensions' => $extensions,
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     *
     * @throws ReflectionException
     */
    private function ignoredRegionProviderCacheContexts(): array
    {
        $contexts = [];
        $sourceFileHashes = [];

        foreach ($this->ignoredRegionRegistry->all() as $id => $provider) {
            $class = $provider::class;
            $contexts[$id] = [
                'class' => $class,
                'source' => $this->classSourceContext($class, $sourceFileHashes),
                'context' => $provider->cacheContext(),
            ];
        }

        ksort($contexts);

        return $contexts;
    }

    /**
     * @return array<string, string>
     *
     * @throws ReflectionException
     */
    private function ruleSourceContexts(): array
    {
        $contexts = [];
        $fileHashes = [];

        foreach ($this->ruleRegistry->getAllKnownRules() as $ruleId => $ruleClass) {
            $contexts[$ruleId] = $this->classSourceContext($ruleClass, $fileHashes);
        }

        return $contexts;
    }

    /**
     * @param  class-string  $class
     * @param  array<string, string>  $fileHashes
     *
     * @throws ReflectionException
     */
    private function classSourceContext(string $class, array &$fileHashes): string
    {
        $reflection = new ReflectionClass($class);

        $files = [];
        $seen = [];
        $this->collectClassSourceFiles($reflection, $files, $seen);

        if ($files === []) {
            return 'internal';
        }

        sort($files);
        $parts = [];

        foreach ($files as $file) {
            if (! isset($fileHashes[$file])) {
                $hash = hash_file('xxh128', $file);
                $fileHashes[$file] = is_string($hash) ? $hash : 'unreadable';
            }

            $parts[] = $file.'@'.$fileHashes[$file];
        }

        return hash('xxh128', implode("\0", $parts));
    }

    /**
     * @param  array<string>  $files
     * @param  array<string, true>  $seen
     * @param  ReflectionClass<object>  $reflection
     */
    private function collectClassSourceFiles(ReflectionClass $reflection, array &$files, array &$seen): void
    {
        if (isset($seen[$reflection->getName()])) {
            return;
        }

        $seen[$reflection->getName()] = true;
        $file = $reflection->getFileName();

        if (is_string($file) && is_file($file)) {
            $files[] = $file;
        }

        foreach ($reflection->getTraits() as $trait) {
            $this->collectClassSourceFiles($trait, $files, $seen);
        }

        $parent = $reflection->getParentClass();
        if ($parent !== false) {
            $this->collectClassSourceFiles($parent, $files, $seen);
        }

        foreach ($reflection->getInterfaces() as $interface) {
            $this->collectClassSourceFiles($interface, $files, $seen);
        }
    }

    /** @return array<string, string> */
    private function installedPackageContexts(): array
    {
        if (! class_exists(InstalledVersions::class)) {
            return [];
        }

        $contexts = [];

        foreach (InstalledVersions::getInstalledPackages() as $package) {
            try {
                $version = InstalledVersions::getPrettyVersion($package)
                    ?? InstalledVersions::getVersion($package)
                    ?? 'unknown';
                $reference = InstalledVersions::getReference($package);
            } catch (OutOfBoundsException) {
                continue;
            }

            $contexts[$package] = is_string($reference) && $reference !== ''
                ? $version.'@'.$reference
                : $version;
        }

        ksort($contexts);

        return $contexts;
    }

    /**
     * @return array<string, array<string, mixed>|string>
     */
    private function ruleCacheContexts(Config $config): array
    {
        $contexts = [];
        $sharedContexts = [];

        foreach (array_keys($config->getRules()) as $ruleId) {
            $rule = $this->ruleRegistry->find($ruleId);

            if (! $rule instanceof ProvidesCacheContext) {
                continue;
            }

            $severity = $config->getRuleSeverity($ruleId) ?? $rule->getDefaultSeverity();

            if (! $severity->shouldReport()) {
                continue;
            }

            $options = $config->getRuleOptions($ruleId);
            if ($rule instanceof SharesCacheContext) {
                $group = $rule->cacheContextGroup($options);
                if ($group === '') {
                    throw new InvalidArgumentException("Rule [{$ruleId}] returned an empty shared cache context group.");
                }

                $contexts[$ruleId] = $sharedContexts[$group] ??= $rule->cacheContext($options);

                continue;
            }

            $contexts[$ruleId] = $rule->cacheContext($options);
        }

        return $contexts;
    }

    protected function getPackageVersion(): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return 'unknown';
        }

        try {
            if (! InstalledVersions::isInstalled(self::PACKAGE_NAME)) {
                return 'unknown';
            }

            $version = InstalledVersions::getPrettyVersion(self::PACKAGE_NAME)
                ?? InstalledVersions::getVersion(self::PACKAGE_NAME);
            $reference = InstalledVersions::getReference(self::PACKAGE_NAME);
        } catch (OutOfBoundsException) {
            return 'unknown';
        }

        if (! is_string($version) || $version === '') {
            return 'unknown';
        }

        return is_string($reference) && $reference !== ''
            ? $version.'@'.$reference
            : $version;
    }
}
