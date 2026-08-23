<?php

declare(strict_types=1);

namespace Forte\Sheath;

use Forte\Parser\ParserOptions;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Configuration\DefaultConfigFactory;
use Forte\Sheath\Configuration\PackagePresets;
use Forte\Sheath\Contracts\IgnoredRegionProvider;
use Forte\Sheath\Contracts\Reporter;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Exceptions\ReporterNotFoundException;
use Forte\Sheath\Files\FileSystem;
use Forte\Sheath\Packages\Dependencies;
use Forte\Sheath\Parsing\IgnoredRegionRegistry;
use Forte\Sheath\Reporters\ReporterRegistry;
use Forte\Sheath\Results\FixResult;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Rules\RuleRegistry;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use RuntimeException;

class SheathManager
{
    public const MAX_FIX_PASSES = 10;

    protected PackagePresets $packagePresets;

    protected IgnoredRegionRegistry $ignoredRegionRegistry;

    public function __construct(
        protected RuleRegistry $ruleRegistry,
        protected ReporterRegistry $reporterRegistry,
        protected ?Dependencies $dependencies = null,
        protected ?ConfigRepository $configRepository = null,
        protected ?ParserOptions $parserOptions = null,
        ?PackagePresets $packagePresets = null,
        ?IgnoredRegionRegistry $ignoredRegionRegistry = null,
    ) {
        $this->packagePresets = $packagePresets ?? PackagePresets::shared();
        $this->ignoredRegionRegistry = $ignoredRegionRegistry ?? new IgnoredRegionRegistry;
    }

    public function lint(string $content, string $filePath = 'stdin.blade.php', ?Config $config = null): LintResult
    {
        $config ??= $this->getDefaultConfig();
        $this->ruleRegistry->setPackageRequirementMode($config->getPackageRequirementMode());

        return $this->makeLinter()->lint($content, $filePath, $config);
    }

    public function lintFile(string $filePath, ?Config $config = null): LintResult
    {
        $content = FileSystem::readFile($filePath);
        if ($content === null) {
            throw new RuntimeException("Could not read file: {$filePath}");
        }

        return $this->lint($content, $filePath, $config);
    }

    public function fix(
        string $content,
        string $filePath = 'stdin.blade.php',
        ?Config $config = null,
        bool $includeDangerous = false,
        int $maxPasses = self::MAX_FIX_PASSES,
    ): FixResult {
        $config ??= $this->getDefaultConfig();

        $fixer = new Fixer;
        $linter = $this->makeLinter();
        $current = $content;
        $applied = 0;
        $lastResult = null;
        $exhaustedPassLimit = true;
        $seenContent = [hash('xxh128', $current) => true];

        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $fixes = $linter->lint($current, $filePath, $config)->getFixes();

            if ($fixes === []) {
                $lastResult = null;
                $exhaustedPassLimit = false;
                break;
            }

            $lastResult = $fixer->applyFixes($current, $fixes, $includeDangerous);

            if ($lastResult->appliedCount === 0) {
                $exhaustedPassLimit = false;
                break;
            }

            $current = $lastResult->content;
            $applied += $lastResult->appliedCount;

            $contentHash = hash('xxh128', $current);
            if (isset($seenContent[$contentHash])) {
                break;
            }

            $seenContent[$contentHash] = true;
        }

        if ($exhaustedPassLimit) {
            $remainingFixes = $linter->lint($current, $filePath, $config)->getFixes();
            $remaining = $fixer->applyFixes($current, $remainingFixes, $includeDangerous);

            return new FixResult(
                content: $current,
                appliedCount: $applied,
                skippedCount: count($remainingFixes),
                hasOverlaps: $remaining->hasOverlaps,
                overlappingFixes: $remaining->overlappingFixes,
            );
        }

        return new FixResult(
            content: $current,
            appliedCount: $applied,
            skippedCount: $lastResult->skippedCount ?? 0,
            hasOverlaps: $lastResult->hasOverlaps ?? false,
            overlappingFixes: $lastResult->overlappingFixes ?? [],
        );
    }

    public function getRuleRegistry(): RuleRegistry
    {
        return $this->ruleRegistry;
    }

    public function getReporterRegistry(): ReporterRegistry
    {
        return $this->reporterRegistry;
    }

    public function getRule(string $ruleId): Rule
    {
        return $this->ruleRegistry->get($ruleId);
    }

    /**
     * @return array<string>
     */
    public function getRules(): array
    {
        return $this->ruleRegistry->all();
    }

    /**
     * @throws ReporterNotFoundException
     */
    public function getReporter(string $name): Reporter
    {
        return $this->reporterRegistry->get($name);
    }

    /**
     * @return array<string>
     */
    public function getReporters(): array
    {
        return $this->reporterRegistry->getAvailableReporters();
    }

    /**
     * @param  class-string<Rule>  $ruleClass
     */
    public function registerRule(string $ruleClass): static
    {
        $this->ruleRegistry->register($ruleClass);

        return $this;
    }

    /**
     * @param  array<class-string<Rule>>  $ruleClasses
     */
    public function registerRules(array $ruleClasses): static
    {
        $this->ruleRegistry->registerMany($ruleClasses);

        return $this;
    }

    /**
     * @param  string  $path  Absolute path to the rules directory
     * @param  string  $namespace  Base namespace for the rules
     */
    public function discoverRules(string $path, string $namespace): static
    {
        $this->ruleRegistry->discoverRules($path, $namespace);

        return $this;
    }

    /**
     * @param  class-string<Reporter>|Reporter  $reporter
     */
    public function registerReporter(string $name, string|Reporter $reporter): static
    {
        $this->reporterRegistry->register($name, $reporter);

        return $this;
    }

    /**
     * @param  array<string, string|array{0: string, 1: array<string, mixed>}>  $rules  ruleId => severity (or [severity, options])
     */
    public function registerPreset(string $name, array $rules): static
    {
        $this->packagePresets->add($name, $rules);

        return $this;
    }

    public function getPackagePresets(): PackagePresets
    {
        return $this->packagePresets;
    }

    public function getIgnoredRegionRegistry(): IgnoredRegionRegistry
    {
        return $this->ignoredRegionRegistry;
    }

    /** @param class-string<IgnoredRegionProvider>|IgnoredRegionProvider $provider */
    public function registerIgnoredRegionProvider(string|IgnoredRegionProvider $provider): static
    {
        $this->ignoredRegionRegistry->register($provider);

        return $this;
    }

    public function hasRule(string $ruleId): bool
    {
        return $this->ruleRegistry->has($ruleId);
    }

    public function getDefaultConfig(): Config
    {
        return DefaultConfigFactory::resolve(
            $this->configRepository?->get('sheath'),
            $this->ruleRegistry,
            packagePresets: $this->packagePresets,
        );
    }

    private function makeLinter(): Linter
    {
        return new Linter(
            $this->ruleRegistry,
            $this->dependencies,
            $this->parserOptions,
            $this->ignoredRegionRegistry,
        );
    }
}
