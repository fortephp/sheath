<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Extensions\AbstractTreeExtension;
use Forte\Parser\Directives\Directives;
use Forte\Parser\Extension\TreeContext;
use Forte\Parser\NodeKindRegistry;
use Forte\Parser\ParserOptions;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Caching\ResultCache;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Configuration\PackagePresets;
use Forte\Sheath\Console\LintCommand;
use Forte\Sheath\Contracts\IgnoredRegionProvider;
use Forte\Sheath\Contracts\ProvidesCacheContext;
use Forte\Sheath\Contracts\SharesCacheContext;
use Forte\Sheath\Files\FileFinder;
use Forte\Sheath\Packages\Dependencies;
use Forte\Sheath\Packages\PackageRequirementEvaluator;
use Forte\Sheath\Parallel\Config as ParallelConfig;
use Forte\Sheath\Parallel\Contracts\WorkerConfig;
use Forte\Sheath\Parallel\Runner;
use Forte\Sheath\Parsing\IgnoredRegionRegistry;
use Forte\Sheath\Reporters\ReporterRegistry;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Tests\Fixtures\Cache\MutableDependencyRule;
use Forte\Sheath\Tests\Fixtures\Cache\MutableRule;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandIgnoredRegionProvider implements IgnoredRegionProvider
{
    public static string $state = 'v1';

    public function id(): string
    {
        return 'command-cache-ignored-region';
    }

    public function regions(string $source, string $filePath): iterable
    {
        return [];
    }

    public function cacheContext(): string
    {
        return self::$state;
    }
}

final class CommandCacheParserExtension extends AbstractTreeExtension
{
    public static string $extensionVersion = '1.0.0';

    public function id(): string
    {
        return 'command-cache-parser-extension';
    }

    public function version(): string
    {
        return self::$extensionVersion;
    }

    protected function registerKinds(NodeKindRegistry $registry): void {}

    public function canHandle(TreeContext $ctx): bool
    {
        return false;
    }

    protected function doHandle(TreeContext $ctx): int
    {
        return 0;
    }
}

#[RequiresPackage('acme/not-installed')]
class CommandGatedProbeRule extends AbstractRule
{
    public function getId(): string
    {
        return 'command-gated-probe';
    }

    public function getDescription(): string
    {
        return 'Rule used to exercise the empty-preset warning on the command.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void {}
}

function gateCommandProbeRule(): void
{
    $registry = app(RuleRegistry::class);
    $registry->setPackageRequirementEvaluator(new PackageRequirementEvaluator(
        Dependencies::fromData(
            ['require' => ['laravel/framework' => '^11.0']],
            ['packages' => [['name' => 'laravel/framework', 'version' => 'v11.0.0']]]
        )
    ));
    $registry->register(CommandGatedProbeRule::class);
}

class CacheContextProbeRule extends AbstractRule implements ProvidesCacheContext
{
    public static int $queries = 0;

    public static function reset(): void
    {
        self::$queries = 0;
    }

    public function getId(): string
    {
        return 'cache-context-probe';
    }

    public function getDescription(): string
    {
        return 'Rule used to exercise cache-context invalidation.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void {}

    public function cacheContext(array $options): array|string
    {
        self::$queries++;

        return $options;
    }
}

final class SharedCacheContextState
{
    public static int $queries = 0;
}

abstract class SharedCacheContextProbeRule extends AbstractRule implements SharesCacheContext
{
    public function getDescription(): string
    {
        return 'Rule used to exercise shared cache contexts.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void {}

    public function cacheContext(array $options): array|string
    {
        SharedCacheContextState::$queries++;

        return ['shared' => true];
    }

    public function cacheContextGroup(array $options): string
    {
        return 'command-shared-cache-context';
    }
}

final class FirstSharedCacheContextProbeRule extends SharedCacheContextProbeRule
{
    public function getId(): string
    {
        return 'first-shared-cache-context-probe';
    }
}

final class SecondSharedCacheContextProbeRule extends SharedCacheContextProbeRule
{
    public function getId(): string
    {
        return 'second-shared-cache-context-probe';
    }
}

final class EmptySharedCacheContextProbeRule extends SharedCacheContextProbeRule
{
    public function getId(): string
    {
        return 'empty-shared-cache-context-probe';
    }

    public function cacheContextGroup(array $options): string
    {
        return '';
    }
}

class FakeRunner extends Runner
{
    /**
     * @param  callable(array<string>, WorkerConfig): array<LintResult>|null  $callback
     * @param  array<string>  $errors
     */
    public function __construct(
        private readonly ?Closure $callback = null,
        private readonly array $errors = [],
    ) {
        parent::__construct(LintResult::class, 'sheath:worker', ParallelConfig::create(2));
    }

    public function run(array $files, WorkerConfig $config): array
    {
        if ($this->callback !== null) {
            return ($this->callback)($files, $config);
        }

        return array_map(
            fn (string $file): LintResult => new LintResult($file, []),
            $files
        );
    }

    public function getErrors(): array
    {
        return $this->errors;
    }
}

class EmptyHitResultCache extends ResultCache
{
    public function has(string $filePath, ?string $sourceContent = null): bool
    {
        return true;
    }

    public function get(string $filePath): ?LintResult
    {
        return null;
    }
}

class AlternatingHitResultCache extends ResultCache
{
    /** @var array<string> */
    public array $requestedFiles = [];

    public function has(string $filePath, ?string $sourceContent = null): bool
    {
        $this->requestedFiles[] = $filePath;
        preg_match('/file-(\d+)\.blade\.php$/', $filePath, $matches);

        return isset($matches[1]) && (int) $matches[1] % 2 === 0;
    }

    public function get(string $filePath): ?LintResult
    {
        return new LintResult($filePath, []);
    }
}

class ConcurrentSaveProbeRule extends AbstractRule
{
    public static ?string $path = null;

    public function getId(): string
    {
        return 'concurrent-save-probe';
    }

    public function getDescription(): string
    {
        return 'Simulates an editor save while a fix is being recalculated.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        if (self::$path !== null
            && str_contains($document->source(), '<script>')
            && ! str_contains($document->source(), 'type=')) {
            file_put_contents(self::$path, 'EXTERNAL SAVE');
            self::$path = null;
        }
    }
}

class TestableLintCommand extends LintCommand
{
    public bool $parallelAvailable = true;

    public ?ParallelConfig $parallelConfig = null;

    public ?Runner $runner = null;

    public string $stdinContent = '';

    /** @var (Closure(array<string>): void)|null */
    public ?Closure $onBeforeLint = null;

    /** @var (Closure(array<LintResult>): void)|null */
    public ?Closure $onBeforeFix = null;

    protected function lintFiles(array $files, Config $config): array
    {
        if ($this->onBeforeLint !== null) {
            ($this->onBeforeLint)($files);
        }

        return parent::lintFiles($files, $config);
    }

    protected function applyFixes(array $results, Config $config): array
    {
        if ($this->onBeforeFix !== null) {
            ($this->onBeforeFix)($results);
        }

        return parent::applyFixes($results, $config);
    }

    protected function isParallelAvailable(): bool
    {
        return $this->parallelAvailable;
    }

    protected function detectParallelConfig(?int $processCount): ParallelConfig
    {
        return $this->parallelConfig ?? ParallelConfig::create(max(2, $processCount ?? 2));
    }

    protected function makeRunner(ParallelConfig $parallelConfig): Runner
    {
        return $this->runner ?? parent::makeRunner($parallelConfig);
    }

    protected function readStdin(): string
    {
        return $this->stdinContent;
    }

    /** @return array<string, mixed> */
    public function cacheContext(Config $config): array
    {
        return $this->buildCacheContext($config);
    }
}

/** @return array{command: TestableLintCommand, cachePath: string} */
function makeLintAuditCommand(?ResultCache $cache = null): array
{
    $cachePath = tempnam(sys_get_temp_dir(), 'sheath-command-cache-');
    if ($cachePath === false) {
        throw new RuntimeException('Failed to allocate temp cache path.');
    }

    $command = new TestableLintCommand(
        app(FileFinder::class),
        app(ReporterRegistry::class),
        app(RuleRegistry::class),
        $cache ?? new ResultCache($cachePath),
        app(IgnoredRegionRegistry::class),
    );
    $command->setLaravel(app());

    return ['command' => $command, 'cachePath' => $cachePath];
}

describe('LintCommand', function (): void {
    afterEach(function (): void {
        PackagePresets::reset();

        if (isset($this->sandbox) && $this->sandbox instanceof TestViewSandbox) {
            $this->sandbox->cleanup();
        }

        if (isset($this->cachePath) && is_string($this->cachePath) && file_exists($this->cachePath)) {
            unlink($this->cachePath);
        }

        if (isset($this->baselinePath) && is_string($this->baselinePath) && file_exists($this->baselinePath)) {
            unlink($this->baselinePath);
        }

        ConcurrentSaveProbeRule::$path = null;
    });

    describe('empty preset contributions', function (): void {
        it('includes the contribution notes in --print-config output', function (): void {
            gateCommandProbeRule();
            PackagePresets::register('acmegated', ['command-gated-probe' => 'warning']);
            config()->set('sheath', ['preset' => ['empty', 'acmegated']]);

            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();

            $tester = new CommandTester($command);
            $status = $tester->execute(['--print-config' => true]);
            $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            expect($status)->toBe(0)
                ->and($decoded['presetContributions']['acmegated']['skippedDueToPackages'] ?? null)
                ->toBe(['command-gated-probe']);
        });

        it('prints configuration values containing console tags verbatim', function (): void {
            config()->set('sheath', [
                'preset' => ['empty'],
                'rules' => [
                    'security-no-raw-echo' => [
                        'warning',
                        ['allowed' => ['<info>literal HTML</info>']],
                    ],
                ],
            ]);

            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $tester = new CommandTester($command);

            $status = $tester->execute(['--print-config' => true]);
            $decoded = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

            expect($status)->toBe(0)
                ->and($decoded['rules']['security-no-raw-echo'][1]['allowed'])
                ->toBe(['<info>literal HTML</info>']);
        });
    });

    describe('rule cache context', function (): void {
        afterEach(function (): void {
            CacheContextProbeRule::reset();
        });

        it('does not query a rule whose severity is off', function (): void {
            $this->sandbox = TestViewSandbox::make('command-audit-');
            $this->sandbox->bladeFiles(1, '<div>ok</div>');

            app(RuleRegistry::class)->register(CacheContextProbeRule::class);
            CacheContextProbeRule::reset();

            config()->set('sheath', [
                'preset' => ['empty'],
                'rules' => ['cache-context-probe' => 'off'],
            ]);

            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $tester = new CommandTester($command);

            $tester->execute([
                'paths' => [$this->sandbox->root],
                '--cache' => true,
                '--cache-location' => $this->cachePath,
            ]);

            expect(CacheContextProbeRule::$queries)->toBe(0);
        });

        it('queries a shared cache context once per command context build', function (): void {
            $registry = app(RuleRegistry::class);
            $registry->register(FirstSharedCacheContextProbeRule::class);
            $registry->register(SecondSharedCacheContextProbeRule::class);
            SharedCacheContextState::$queries = 0;

            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $config = Config::make([
                'rules' => [
                    'first-shared-cache-context-probe' => 'warning',
                    'second-shared-cache-context-probe' => 'warning',
                ],
            ]);

            $context = $command->cacheContext($config);

            expect(SharedCacheContextState::$queries)->toBe(1)
                ->and($context['ruleDependencies']['first-shared-cache-context-probe'])
                ->toBe($context['ruleDependencies']['second-shared-cache-context-probe']);
        });

        it('rejects an empty shared cache context group', function (): void {
            app(RuleRegistry::class)->register(EmptySharedCacheContextProbeRule::class);
            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $config = Config::make([
                'rules' => ['empty-shared-cache-context-probe' => 'warning'],
            ]);

            expect(fn (): array => $command->cacheContext($config))
                ->toThrow(InvalidArgumentException::class);
        });

        it('invalidates the cache context when a registered rule source file changes', function (): void {
            $this->sandbox = TestViewSandbox::make('command-cache-source-');
            $rulePath = $this->sandbox->file(
                'MutableRule.php',
                (string) file_get_contents(__DIR__.'/../../Fixtures/cache/MutableRule.php')
            );
            require_once $rulePath;

            app(RuleRegistry::class)->register(MutableRule::class);
            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $config = Config::make(['rules' => ['mutable-cache-probe' => 'warning']]);

            $before = $command->cacheContext($config);
            file_put_contents($rulePath, "\n// implementation changed\n", FILE_APPEND);
            clearstatcache(true, $rulePath);
            $after = $command->cacheContext($config);

            expect($after)->not->toBe($before);
        });

        it('invalidates the cache context when a rule trait or parent source changes', function (): void {
            $this->sandbox = TestViewSandbox::make('command-cache-dependencies-');
            $traitPath = $this->sandbox->file(
                'MutableRuleTrait.php',
                (string) file_get_contents(__DIR__.'/../../Fixtures/cache/MutableRuleTrait.php')
            );
            $basePath = $this->sandbox->file(
                'MutableRuleBase.php',
                (string) file_get_contents(__DIR__.'/../../Fixtures/cache/MutableRuleBase.php')
            );
            $rulePath = $this->sandbox->file(
                'MutableDependencyRule.php',
                (string) file_get_contents(__DIR__.'/../../Fixtures/cache/MutableDependencyRule.php')
            );
            require_once $traitPath;
            require_once $basePath;
            require_once $rulePath;

            app(RuleRegistry::class)->register(MutableDependencyRule::class);
            ['command' => $command] = makeLintAuditCommand();
            $config = Config::make(['rules' => ['mutable-dependency-cache-probe' => 'warning']]);

            $before = $command->cacheContext($config);
            file_put_contents($traitPath, "\n// shared behavior changed\n", FILE_APPEND);
            clearstatcache(true, $traitPath);
            $afterTrait = $command->cacheContext($config);

            file_put_contents($basePath, "\n// inherited behavior changed\n", FILE_APPEND);
            clearstatcache(true, $basePath);
            $afterParent = $command->cacheContext($config);

            expect($afterTrait)->not->toBe($before)
                ->and($afterParent)->not->toBe($afterTrait);
        });

        it('includes installed dependency versions in the cache context', function (): void {
            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();

            $context = $command->cacheContext(Config::make());

            expect($context)->toHaveKey('dependencies')
                ->and($context['dependencies'])->toHaveKey('laravel/framework');
        });

        it('invalidates the cache context when the parser directive registry changes', function (): void {
            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $config = Config::make()->setRules(['blade-no-directive-attribute-collision' => 'warning']);

            $before = $command->cacheContext($config);
            app(Directives::class)->registerDirective('sheathcacheprobe');
            $after = $command->cacheContext($config);

            expect($after)->not->toBe($before);
        });

        it('includes the effective parser depth limits in the cache context', function (): void {
            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $config = Config::make();

            $before = $command->cacheContext($config);
            app(ParserOptions::class)->depthLimits(elements: 64, directives: 32, conditions: 16);
            $after = $command->cacheContext($config);

            expect($before['parser']['depthLimits'])->toBe([
                'elements' => 2048,
                'directives' => 1024,
                'conditions' => 1024,
            ])->and($after['parser']['depthLimits'])->toBe([
                'elements' => 64,
                'directives' => 32,
                'conditions' => 16,
            ])->and($after)->not->toBe($before);
        });

        it('invalidates the cache context when an ignored-region provider context changes', function (): void {
            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $provider = new CommandIgnoredRegionProvider;
            app(IgnoredRegionRegistry::class)->register($provider);
            CommandIgnoredRegionProvider::$state = 'v1';

            $before = $command->cacheContext(Config::make());
            CommandIgnoredRegionProvider::$state = 'v2';
            $after = $command->cacheContext(Config::make());

            expect($after)->not->toBe($before);
        });

        it('includes parser extension version and options in the cache context', function (): void {
            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $extension = (new CommandCacheParserExtension)->configure(['dialect' => 'one']);
            app(ParserOptions::class)->extension($extension);
            CommandCacheParserExtension::$extensionVersion = '1.0.0';

            $before = $command->cacheContext(Config::make());
            $extension->configure(['dialect' => 'two']);
            $afterOptions = $command->cacheContext(Config::make());
            CommandCacheParserExtension::$extensionVersion = '2.0.0';
            $afterVersion = $command->cacheContext(Config::make());

            expect($afterOptions)->not->toBe($before)
                ->and($afterVersion)->not->toBe($afterOptions);
        });
    });

    describe('I/O failures fail the run', function (): void {
        it('exits with failure when a discovered file vanishes before it is read', function (): void {
            $this->sandbox = TestViewSandbox::make('command-audit-');
            $this->sandbox->bladeFiles(2, '<div>ok</div>');

            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $command->onBeforeLint = static function (array $files): void {
                unlink($files[0]);
            };

            $tester = new CommandTester($command);
            $status = $tester->execute([
                'paths' => [$this->sandbox->root],
                '--only' => 'a11y-alt-text',
            ]);

            expect($status)->toBe(1);
        });

        it('keeps read failures off machine-readable stdout', function (): void {
            $this->sandbox = TestViewSandbox::make('command-audit-json-read-failure-');
            $this->sandbox->bladeFiles(2, '<div>ok</div>');

            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $command->onBeforeLint = static function (array $files): void {
                unlink($files[0]);
            };

            $tester = new CommandTester($command);
            $status = $tester->execute([
                'paths' => [$this->sandbox->root],
                '--only' => 'a11y-alt-text',
                '--format' => 'json',
            ], ['capture_stderr_separately' => true]);

            expect($status)->toBe(1)
                ->and(fn () => json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR))->not->toThrow(Throwable::class)
                ->and($tester->getErrorOutput())->not->toBe('');
        });

        it('fails when the result cache cannot be persisted', function (): void {
            $this->sandbox = TestViewSandbox::make('command-audit-cache-write-');
            $this->sandbox->bladeFiles(1, '<div>ok</div>');
            $cachePath = $this->sandbox->path('missing/cache.json');

            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $tester = new CommandTester($command);
            $status = $tester->execute([
                'paths' => [$this->sandbox->root],
                '--only' => 'a11y-alt-text',
                '--cache' => true,
                '--cache-location' => $cachePath,
            ], ['capture_stderr_separately' => true]);

            expect($status)->toBe(1)
                ->and(file_exists($cachePath))->toBeFalse();
        });

        it('exits with failure when a fix cannot be written back', function (): void {
            $this->sandbox = TestViewSandbox::make('command-audit-');
            $path = $this->sandbox->file(
                'test.blade.php',
                '<script type="text/javascript">go()</script>'
            );

            chmod($path, 0444);

            try {
                ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();

                $tester = new CommandTester($command);
                $status = $tester->execute([
                    'paths' => [$this->sandbox->root],
                    '--only' => 'best-practices-no-script-style-type',
                    '--fix' => true,
                ]);

                expect($status)->toBe(1);
            } finally {
                chmod($path, 0666);
            }
        });

        it('re-lints a file that changes after linting before applying fixes', function (): void {
            $this->sandbox = TestViewSandbox::make('command-audit-');
            $original = '<script type="text/javascript">go()</script>';
            $path = $this->sandbox->file('test.blade.php', $original);

            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $command->onBeforeFix = static function () use ($path, $original): void {
                file_put_contents($path, '<!-- saved -->'.$original);
            };

            $tester = new CommandTester($command);
            $status = $tester->execute([
                'paths' => [$this->sandbox->root],
                '--only' => 'best-practices-no-script-style-type',
                '--fix' => true,
            ]);

            expect(file_get_contents($path))->toBe('<!-- saved --><script>go()</script>')
                ->and($status)->toBe(0);
        });

        it('does not overwrite a file saved while fixes are being recalculated', function (): void {
            $this->sandbox = TestViewSandbox::make('command-audit-');
            $path = $this->sandbox->file('test.blade.php', '<script type="text/javascript">go()</script>');

            app(RuleRegistry::class)->register(ConcurrentSaveProbeRule::class);
            ConcurrentSaveProbeRule::$path = $path;
            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();

            $tester = new CommandTester($command);
            $status = $tester->execute([
                'paths' => [$this->sandbox->root],
                '--only' => 'best-practices-no-script-style-type,concurrent-save-probe',
                '--fix' => true,
            ]);

            expect(file_get_contents($path))->toBe('EXTERNAL SAVE')
                ->and($status)->toBe(1);
        });
    });

    describe('stdin BOM handling', function (): void {
        it('strips a leading UTF-8 BOM without shifting reported columns', function (): void {
            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $command->stdinContent = "\xEF\xBB\xBF".'<img src="a.png">';

            $tester = new CommandTester($command);
            $status = $tester->execute([
                '--stdin' => true,
                '--only' => 'a11y-alt-text',
                '--format' => 'unix',
            ]);

            expect($status)->toBe(1)
                ->and($tester->getDisplay())->toContain('stdin.blade.php:1:1:');
        });

        it('does not echo the BOM back through --stdin --fix', function (): void {
            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $command->stdinContent = "\xEF\xBB\xBF".'<a href="https://x.test" target="_blank" rel="opener">go</a>';

            $tester = new CommandTester($command);
            $tester->execute([
                '--stdin' => true,
                '--fix' => true,
                '--only' => 'security-no-target-blank',
            ]);

            expect($tester->getDisplay())
                ->toBe('<a href="https://x.test" target="_blank" rel="opener noopener">go</a>');
        });
    });

    it('re-lints a file when a claimed cache hit has no valid result', function (): void {
        $this->sandbox = TestViewSandbox::make('command-audit-');
        $this->sandbox->bladeFiles(1, '<img src="test.jpg">');

        ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand(
            new EmptyHitResultCache(sys_get_temp_dir().'/unused-sheath-cache')
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([
            'paths' => [$this->sandbox->root],
            '--cache' => true,
            '--only' => 'a11y-alt-text',
        ]);

        expect($status)->toBe(1);
    });

    describe('option precedence and stream separation', function (): void {
        it('lets --dry-run override --fix', function (): void {
            $this->sandbox = TestViewSandbox::make('command-audit-');
            $original = '<script type="text/javascript">go()</script>';
            $path = $this->sandbox->file('test.blade.php', $original);

            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();

            $tester = new CommandTester($command);
            $tester->execute([
                'paths' => [$this->sandbox->root],
                '--only' => 'best-practices-no-script-style-type',
                '--fix' => true,
                '--dry-run' => true,
            ]);

            expect(file_get_contents($path))->toBe($original);
        });

        it('keeps notices off stdout for machine formats', function (): void {
            $this->sandbox = TestViewSandbox::make('command-audit-');
            $this->sandbox->bladeFiles(1, '<div>ok</div>');

            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();

            $tester = new CommandTester($command);
            $tester->execute(
                [
                    'paths' => [$this->sandbox->root],
                    '--only' => 'a11y-alt-text',
                    '--dangerous' => true,
                    '--format' => 'json',
                ],
                ['capture_stderr_separately' => true]
            );

            expect(json_decode($tester->getDisplay(), true))->not->toBeNull()
                ->and($tester->getErrorOutput())->not->toBe('');
        });
    });

    it('reports parallel results in discovery order regardless of completion order', function (): void {
        $this->sandbox = TestViewSandbox::make('command-audit-');
        $this->sandbox->bladeFiles(12, '<img src="test.jpg">');

        ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();

        $received = [];
        $command->runner = new FakeRunner(
            callback: function (array $files, WorkerConfig $config) use (&$received): array {
                $received = $files;

                return array_map(
                    fn (string $file): LintResult => new LintResult($file, []),
                    array_reverse($files)
                );
            },
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([
            'paths' => [$this->sandbox->root],
            '--parallel' => true,
            '--format' => 'json',
        ], ['capture_stderr_separately' => true]);

        $payload = json_decode($tester->getDisplay(), true);
        $reported = array_column($payload['results'] ?? [], 'filePath');
        $expected = array_map(
            basename(...),
            $received
        );

        expect($received)->not->toBe([])
            ->and(array_map(basename(...), $reported))->toBe($expected);
    });

    it('preserves discovery order when parallel results are mixed with cache hits', function (): void {
        $this->sandbox = TestViewSandbox::make('command-audit-');
        $this->sandbox->bladeFiles(20, '<img src="test.jpg" alt="Valid">');
        $cache = new AlternatingHitResultCache(sys_get_temp_dir().'/unused-sheath-cache');

        ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand($cache);
        $command->runner = new FakeRunner(
            callback: fn (array $files, WorkerConfig $config): array => array_map(
                fn (string $file): LintResult => new LintResult($file, []),
                array_reverse($files)
            ),
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([
            'paths' => [$this->sandbox->root],
            '--parallel' => true,
            '--cache' => true,
            '--cache-location' => $this->cachePath,
            '--only' => 'a11y-alt-text',
            '--format' => 'json',
        ], ['capture_stderr_separately' => true]);

        $payload = json_decode($tester->getDisplay(), true);
        $reported = array_column($payload['results'] ?? [], 'filePath');

        expect($status)->toBe(0)
            ->and(array_map(basename(...), $reported))
            ->toBe(array_map(basename(...), $cache->requestedFiles));
    });

    it('fails instead of silently passing when a parallel worker omits a file', function (): void {
        $this->sandbox = TestViewSandbox::make('command-audit-');
        $this->sandbox->bladeFiles(10, '<img src="test.jpg" alt="Valid">');

        ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
        $command->runner = new FakeRunner(
            callback: fn (array $files, WorkerConfig $config): array => array_map(
                fn (string $file): LintResult => new LintResult($file, []),
                array_slice($files, 0, -1)
            ),
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([
            'paths' => [$this->sandbox->root],
            '--parallel' => true,
            '--only' => 'a11y-alt-text',
        ]);

        expect($status)->toBe(1);
    });

    it('fails when parallel workers report errors', function (): void {
        $this->sandbox = TestViewSandbox::make('command-audit-');
        $this->sandbox->bladeFiles(10, '<img src="test.jpg" alt="Valid">');

        ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
        $command->runner = new FakeRunner(
            callback: fn (array $files, WorkerConfig $config): array => array_map(
                fn (string $file): LintResult => new LintResult($file, []),
                $files
            ),
            errors: ['Error processing broken.blade.php: Failed to read file']
        );

        $tester = new CommandTester($command);
        $status = $tester->execute([
            'paths' => [$this->sandbox->root],
            '--parallel' => true,
            '--only' => 'a11y-alt-text',
        ]);

        expect($status)->toBe(1);
    });

    it('supports linting stdin with a custom filename', function (): void {
        ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
        $command->stdinContent = '<img src="test.jpg">';

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--stdin' => true,
            '--stdin-filename' => 'snippet.blade.php',
            '--only' => 'a11y-alt-text',
            '--format' => 'json',
        ]);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        expect($status)->toBe(1)
            ->and($payload['results'][0]['filePath'] ?? null)->toBe('snippet.blade.php');
    });

    it('reports parser depth limits from stdin as structured output', function (): void {
        ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
        $command->stdinContent = str_repeat('<div>', 2048).str_repeat('</div>', 2048);

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--stdin' => true,
            '--format' => 'json',
        ]);
        $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        expect($status)->toBe(1)
            ->and($payload['results'][0]['hasParseErrors'] ?? false)->toBeTrue()
            ->and($payload['results'][0]['violations'][0]['ruleId'] ?? null)->toBe('parse-error');
    });

    it('supports generating and applying a baseline for stdin content', function (): void {
        $this->baselinePath = tempnam(sys_get_temp_dir(), 'sheath-stdin-baseline-');
        if ($this->baselinePath === false) {
            throw new RuntimeException('Failed to allocate temp baseline path.');
        }
        unlink($this->baselinePath);

        ['command' => $generateCommand, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
        $generateCommand->stdinContent = '<img src="test.jpg">';

        $generateTester = new CommandTester($generateCommand);
        $generateStatus = $generateTester->execute([
            '--stdin' => true,
            '--stdin-filename' => 'resources/views/snippet.blade.php',
            '--only' => 'a11y-alt-text',
            '--baseline' => $this->baselinePath,
            '--generate-baseline' => true,
        ]);

        expect($generateStatus)->toBe(0)
            ->and(file_exists($this->baselinePath))->toBeTrue();

        ['command' => $lintCommand, 'cachePath' => $secondCachePath] = makeLintAuditCommand();
        $lintCommand->stdinContent = '<img src="test.jpg">';

        try {
            $lintTester = new CommandTester($lintCommand);
            $lintStatus = $lintTester->execute([
                '--stdin' => true,
                '--stdin-filename' => 'resources/views/snippet.blade.php',
                '--only' => 'a11y-alt-text',
                '--baseline' => $this->baselinePath,
            ]);

            expect($lintStatus)->toBe(0);
        } finally {
            if (file_exists($secondCachePath)) {
                unlink($secondCachePath);
            }
        }
    });

    it('rejects updating a baseline while fixing', function (): void {
        $this->baselinePath = tempnam(sys_get_temp_dir(), 'sheath-update-baseline-');
        if ($this->baselinePath === false) {
            throw new RuntimeException('Failed to allocate temp baseline path.');
        }
        unlink($this->baselinePath);

        ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
        $command->stdinContent = '<a href="https://x.test" target="_blank" rel="opener">go</a>';

        $tester = new CommandTester($command);
        $status = $tester->execute([
            '--stdin' => true,
            '--fix' => true,
            '--only' => 'security-no-target-blank',
            '--baseline' => $this->baselinePath,
            '--update-baseline' => true,
        ]);

        expect($status)->toBe(1);
    });

    describe('--stdin --fix', function (): void {
        it('writes the fixed source to stdout instead of the report', function (): void {
            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $command->stdinContent = '<a href="https://x.test" target="_blank" rel="opener">go</a>';

            $tester = new CommandTester($command);
            $status = $tester->execute([
                '--stdin' => true,
                '--fix' => true,
                '--only' => 'security-no-target-blank',
            ]);

            expect($tester->getDisplay())
                ->toBe('<a href="https://x.test" target="_blank" rel="opener noopener">go</a>')
                ->and($status)->toBe(0);
        });

        it('writes source as raw output without interpreting console tags', function (): void {
            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $command->stdinContent = '<info>literal HTML</info><comment>also literal</comment>';

            $tester = new CommandTester($command);
            $status = $tester->execute([
                '--stdin' => true,
                '--fix' => true,
                '--only' => 'a11y-alt-text',
            ]);

            expect($tester->getDisplay())->toBe($command->stdinContent)
                ->and($status)->toBe(0);
        });

        it('exits non-zero when a finding survives the fix', function (): void {
            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $command->stdinContent = '<img src="a.png">';

            $tester = new CommandTester($command);
            $status = $tester->execute([
                '--stdin' => true,
                '--fix' => true,
                '--only' => 'a11y-alt-text',
            ]);

            expect($tester->getDisplay())->toBe('<img src="a.png">')
                ->and($status)->toBe(1);
        });

        it('holds back dangerous fixes unless asked', function (): void {
            $blade = '<div style="color: red">x</div>';

            $run = function (array $arguments) use ($blade): string {
                ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
                $command->stdinContent = $blade;

                $tester = new CommandTester($command);
                $tester->execute($arguments + [
                    '--stdin' => true,
                    '--fix' => true,
                    '--only' => 'best-practices-no-inline-styles',
                ]);

                return $tester->getDisplay();
            };

            expect($run([]))->toBe($blade)
                ->and($run(['--dangerous' => true]))->toBe('<div>x</div>');
        });

        it('still reports rather than rewriting under --dry-run', function (): void {
            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $command->stdinContent = '<a href="https://x.test" target="_blank" rel="opener">go</a>';

            $tester = new CommandTester($command);
            $tester->execute([
                '--stdin' => true,
                '--dry-run' => true,
                '--only' => 'security-no-target-blank',
                '--format' => 'unix',
            ]);

            expect($tester->getDisplay())->toContain('security-no-target-blank');
        });

        it('preserves unknown role fallbacks during a stdin dry run', function (): void {
            ['command' => $command, 'cachePath' => $this->cachePath] = makeLintAuditCommand();
            $command->stdinContent = '<div role="navigation widget invalid">Content</div>';

            $tester = new CommandTester($command);
            $tester->execute([
                '--stdin' => true,
                '--dry-run' => true,
                '--only' => 'a11y-no-abstract-roles,a11y-no-invalid-role',
                '--format' => 'json',
            ], ['capture_stderr_separately' => true]);
            $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $ruleIds = array_column($payload['results'][0]['violations'] ?? [], 'ruleId');

            expect($ruleIds)->toContain('a11y-no-abstract-roles')
                ->not->toContain('a11y-no-invalid-role');
        });
    });
});
