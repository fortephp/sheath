<?php

declare(strict_types=1);

namespace Forte\Sheath;

use Forte\Parser\Directives\Directives;
use Forte\Parser\ParserOptions;
use Forte\Sheath\Caching\ResultCache;
use Forte\Sheath\Configuration\PackagePresets;
use Forte\Sheath\Console\EditorCommand;
use Forte\Sheath\Console\LintCommand;
use Forte\Sheath\Console\WorkerCommand;
use Forte\Sheath\Files\FileFinder;
use Forte\Sheath\Packages\Dependencies;
use Forte\Sheath\Packages\PackageRequirementEvaluator;
use Forte\Sheath\Packages\PackageRequirementMode;
use Forte\Sheath\Parsing\IgnoredRegionRegistry;
use Forte\Sheath\Reporters\ReporterRegistry;
use Forte\Sheath\Rules\RuleRegistry;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Throwable;

/** @internal */
class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/sheath.php',
            'sheath'
        );

        $this->app->singleton(
            Dependencies::class,
            fn (): Dependencies => Dependencies::fromInstalledVersions(),
        );

        $this->app->singleton(Directives::class, function () {
            $directives = Directives::withDefaults();

            try {
                $directives->syncLaravelDirectives();
            } catch (Throwable) {
            }

            return $directives;
        });

        $this->app->singleton(PackageRequirementEvaluator::class, function () {
            $evaluator = new PackageRequirementEvaluator;

            /** @var Dependencies|null $checker */
            $checker = $this->app->make(Dependencies::class);
            if ($checker !== null) {
                $evaluator->setDependencies($checker);
            }

            return $evaluator;
        });

        $this->app->singleton(RuleRegistry::class, function () {
            $registry = new RuleRegistry;

            $registry->setResolver(fn (string $ruleClass) => $this->app->make($ruleClass));

            $modeValue = config('sheath.packageRequirementMode', 'skip');
            $mode = PackageRequirementMode::SKIP;

            // Published configuration is validated by DefaultConfigFactory.
            // Registry construction happens earlier, so it must not turn an
            // invalid user value into a raw TypeError/InvalidArgumentException.
            if (is_string($modeValue)) {
                try {
                    $mode = PackageRequirementMode::fromString($modeValue);
                } catch (\InvalidArgumentException) {
                    // Keep the neutral bootstrap default until validation emits
                    // the user-facing ConfigurationException.
                }
            }
            $registry->setPackageRequirementMode($mode);

            /** @var PackageRequirementEvaluator $evaluator */
            $evaluator = $this->app->make(PackageRequirementEvaluator::class);
            $registry->setPackageRequirementEvaluator($evaluator);

            $registry->registerBuiltInRules();

            return $registry;
        });

        $this->app->singleton(ReporterRegistry::class, function () {
            $registry = new ReporterRegistry;

            $registry->setResolver(fn (string $reporterClass) => $this->app->make($reporterClass));

            return $registry;
        });
        $this->app->singleton(FileFinder::class);
        $this->app->singleton(ResultCache::class);

        $this->app->singleton(IgnoredRegionRegistry::class, function () {
            $registry = new IgnoredRegionRegistry;
            $registry->setResolver(fn (string $providerClass) => $this->app->make($providerClass));

            return $registry;
        });

        $this->app->singleton(PackagePresets::class, fn () => PackagePresets::shared());

        $this->app->singleton(ParserOptions::class, function () {
            /** @var Directives $directives */
            $directives = $this->app->make(Directives::class);

            return ParserOptions::make()->directives($directives);
        });

        $this->app->singleton(Linter::class, function () {
            /** @var RuleRegistry $registry */
            $registry = $this->app->make(RuleRegistry::class);

            /** @var Dependencies|null $checker */
            $checker = $this->app->make(Dependencies::class);

            /** @var ParserOptions $parserOptions */
            $parserOptions = $this->app->make(ParserOptions::class);

            /** @var IgnoredRegionRegistry $ignoredRegionRegistry */
            $ignoredRegionRegistry = $this->app->make(IgnoredRegionRegistry::class);

            return new Linter($registry, $checker, $parserOptions, $ignoredRegionRegistry);
        });

        $this->app->singleton(Fixer::class);

        $this->app->singleton('sheath', function () {
            /** @var RuleRegistry $ruleRegistry */
            $ruleRegistry = $this->app->make(RuleRegistry::class);
            /** @var ReporterRegistry $reporterRegistry */
            $reporterRegistry = $this->app->make(ReporterRegistry::class);
            /** @var Dependencies|null $checker */
            $checker = $this->app->make(Dependencies::class);
            /** @var ConfigRepository $configRepository */
            $configRepository = $this->app->make(ConfigRepository::class);

            /** @var ParserOptions $parserOptions */
            $parserOptions = $this->app->make(ParserOptions::class);

            /** @var PackagePresets $packagePresets */
            $packagePresets = $this->app->make(PackagePresets::class);

            /** @var IgnoredRegionRegistry $ignoredRegionRegistry */
            $ignoredRegionRegistry = $this->app->make(IgnoredRegionRegistry::class);

            return new SheathManager(
                $ruleRegistry,
                $reporterRegistry,
                $checker,
                $configRepository,
                $parserOptions,
                $packagePresets,
                $ignoredRegionRegistry,
            );
        });

        $this->app->alias('sheath', SheathManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sheath.php' => config_path('sheath.php'),
            ], 'sheath-config');

            $this->commands([
                EditorCommand::class,
                LintCommand::class,
                WorkerCommand::class,
            ]);
        }
    }
}
