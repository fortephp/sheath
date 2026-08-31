<?php

declare(strict_types=1);

namespace Forte\Sheath\Console;

use Forte\Sheath\Baselines\Baseline;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Console\Handlers\ConfigHandler;
use Forte\Sheath\Packages\Dependencies;
use Forte\Sheath\Packages\InstalledPackageVersion;
use Forte\Sheath\Parallel\Config as ParallelConfig;
use Forte\Sheath\Parallel\Runner;
use Forte\Sheath\Reporters\JsonReporter;
use Forte\Sheath\Rules\RuleRegistry;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * @internal
 */
class SupportCommand extends Command
{
    protected $signature = 'sheath:support
                            {--config= : Path to configuration file}
                            {--json : Output the report as JSON}';

    protected $description = 'Print environment details to include in Sheath bug reports.';

    private const DEFAULT_CACHE_PATH = '.sheath-cache';

    /** @var list<string> */
    private const REPORTED_PACKAGES = [
        'fortephp/sheath',
        'fortephp/forte',
        'laravel/framework',
        'livewire/livewire',
        'livewire/flux',
        'laravel/folio',
        'fidry/cpu-core-counter',
    ];

    /** @var list<string> */
    private const PARALLEL_PACKAGES = [
        'react/event-loop',
        'react/child-process',
        'clue/ndjson-react',
    ];

    public function __construct(
        private readonly RuleRegistry $ruleRegistry,
        private readonly Dependencies $dependencies,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = $this->buildReport();

        if ($this->option('json')) {
            $this->getOutput()->writeln(
                (string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                OutputInterface::OUTPUT_RAW,
            );

            return self::SUCCESS;
        }

        $this->renderText($report);

        return self::SUCCESS;
    }

    /**
     * @return array{
     *     sheath: array<string, mixed>,
     *     environment: array<string, mixed>,
     *     project: array<string, mixed>,
     *     packages: array<string, string|null>
     * }
     */
    private function buildReport(): array
    {
        return [
            'sheath' => $this->describeSheath(),
            'environment' => $this->describeEnvironment(),
            'project' => $this->describeProject(),
            'packages' => $this->describePackages(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeSheath(): array
    {
        $missing = array_values(array_filter(
            self::PARALLEL_PACKAGES,
            fn (string $package): bool => ! $this->dependencies->has($package),
        ));

        if (! function_exists('proc_open')) {
            $missing[] = 'proc_open';
        }

        return [
            'version' => InstalledPackageVersion::describe('fortephp/sheath'),
            'editorProtocolVersion' => EditorCommand::PROTOCOL_VERSION,
            'reportSchemaVersion' => JsonReporter::SCHEMA_VERSION,
            'parallel' => [
                'available' => Runner::isAvailable(),
                'processes' => ParallelConfig::detectCpuCores(),
                'missing' => $missing,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeEnvironment(): array
    {
        return [
            'php' => [
                'version' => PHP_VERSION,
                'binary' => PHP_BINARY,
                'sapi' => PHP_SAPI,
            ],
            'os' => [
                'family' => PHP_OS_FAMILY,
                'description' => trim(php_uname('s').' '.php_uname('r').' '.php_uname('m')),
            ],
            'laravel' => $this->laravel->version(),
            'forte' => $this->dependencies->version('fortephp/forte'),
            'mbstring' => extension_loaded('mbstring'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describeProject(): array
    {
        $configOption = $this->option('config');
        $configPath = is_string($configOption) && $configOption !== '' ? $configOption : null;
        $published = file_exists(config_path('sheath.php'));

        $config = null;
        $error = null;

        try {
            $handler = new ConfigHandler($this, $this->ruleRegistry);
            $config = $handler->applyOverrides($handler->load($configPath), null, null);
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }

        $baselinePath = base_path(Baseline::DEFAULT_PATH);
        $cachePath = base_path(self::DEFAULT_CACHE_PATH);

        return [
            'basePath' => base_path(),
            'configuration' => [
                'source' => $configPath ?? 'config/sheath.php',
                'published' => $configPath === null ? $published : null,
                'error' => $error,
            ],
            'presets' => $config?->getPreset(),
            'paths' => $config?->getPaths(),
            'rules' => $config instanceof Config ? [
                'enabled' => $this->countEnabledRules($config),
                'known' => count($this->ruleRegistry->getAllKnownRules()),
            ] : null,
            'packageRequirementMode' => $config?->getPackageRequirementMode()->value,
            'baseline' => [
                'path' => $baselinePath,
                'exists' => file_exists($baselinePath),
            ],
            'cache' => [
                'path' => $cachePath,
                'exists' => file_exists($cachePath),
            ],
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function describePackages(): array
    {
        $packages = [];

        foreach ([...self::REPORTED_PACKAGES, ...self::PARALLEL_PACKAGES] as $package) {
            $packages[$package] = $this->dependencies->version($package);
        }

        return $packages;
    }

    private function countEnabledRules(Config $config): int
    {
        $enabled = 0;

        foreach (array_keys($config->getRules()) as $ruleId) {
            if ($config->getRuleSeverity($ruleId)?->shouldReport() ?? false) {
                $enabled++;
            }
        }

        return $enabled;
    }

    /**
     * @param  array{
     *     sheath: array<string, mixed>,
     *     environment: array<string, mixed>,
     *     project: array<string, mixed>,
     *     packages: array<string, string|null>
     * }  $report
     */
    private function renderText(array $report): void
    {
        $sheath = $report['sheath'];
        $environment = $report['environment'];
        $project = $report['project'];

        /** @var array{available: bool, processes: int, missing: list<string>} $parallel */
        $parallel = $sheath['parallel'];
        /** @var array{version: string, binary: string} $php */
        $php = $environment['php'];
        /** @var array{description: string} $os */
        $os = $environment['os'];
        /** @var array{source: string, published: bool|null, error: string|null} $configuration */
        $configuration = $project['configuration'];
        /** @var array{path: string, exists: bool} $baseline */
        $baseline = $project['baseline'];
        /** @var array{path: string, exists: bool} $cache */
        $cache = $project['cache'];
        /** @var array{enabled: int, known: int}|null $rules */
        $rules = $project['rules'];

        $this->section('Sheath', [
            'Version' => $this->string($sheath['version']),
            'Editor protocol' => $this->string($sheath['editorProtocolVersion']),
            'Report schema' => $this->string($sheath['reportSchemaVersion']),
            'Parallel linting' => $parallel['available']
                ? sprintf('available (%d processes)', $parallel['processes'])
                : 'unavailable (missing '.implode(', ', $parallel['missing']).')',
        ]);

        $this->section('Environment', [
            'PHP' => sprintf('%s (%s)', $php['version'], $php['binary']),
            'Operating system' => $os['description'],
            'Laravel' => $this->string($environment['laravel']),
            'Forte' => $this->string($environment['forte'] ?? 'not installed'),
            'mbstring extension' => $environment['mbstring'] === true ? 'loaded' : 'missing',
        ]);

        $projectRows = [
            'Base path' => $this->string($project['basePath']),
            'Configuration' => match ($configuration['published']) {
                true => $configuration['source'].' (published)',
                false => 'package defaults ('.$configuration['source'].' not published)',
                null => $configuration['source'].' (--config)',
            },
        ];

        if ($configuration['error'] !== null) {
            $projectRows['Configuration error'] = $configuration['error'];
        } else {
            $projectRows['Presets'] = $this->list($project['presets']);
            $projectRows['Paths'] = $this->list($project['paths']);
            $projectRows['Rules'] = $rules !== null
                ? sprintf('%d enabled of %d known', $rules['enabled'], $rules['known'])
                : 'unknown';
            $projectRows['Package requirement mode'] = $this->string($project['packageRequirementMode']);
        }

        $projectRows['Baseline'] = $this->fileStatus($baseline);
        $projectRows['Cache'] = $this->fileStatus($cache);

        $this->section('Project', $projectRows);

        $packageRows = [];
        foreach ($report['packages'] as $package => $version) {
            $packageRows[$package] = $version ?? 'not installed';
        }

        $this->section('Packages', $packageRows);
        $this->newLine();
    }

    /**
     * @param  array<string, string>  $rows
     */
    private function section(string $title, array $rows): void
    {
        $this->newLine();
        $this->components->twoColumnDetail('  <fg=green;options=bold>'.$title.'</>');

        foreach ($rows as $label => $value) {
            $this->components->twoColumnDetail('    '.$label, $value);
        }
    }

    /**
     * @param  array{path: string, exists: bool}  $file
     */
    private function fileStatus(array $file): string
    {
        return $file['path'].($file['exists'] ? ' (found)' : ' (not found)');
    }

    private function list(mixed $values): string
    {
        if (! is_array($values) || $values === []) {
            return 'none';
        }

        return implode(', ', array_map($this->string(...), $values));
    }

    private function string(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'yes' : 'no',
            $value === null => 'unknown',
            default => (string) json_encode($value),
        };
    }
}
