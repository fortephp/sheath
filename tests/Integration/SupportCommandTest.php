<?php

declare(strict_types=1);

use Forte\Sheath\Baselines\Baseline;
use Forte\Sheath\Console\EditorCommand;
use Forte\Sheath\Reporters\JsonReporter;
use Illuminate\Support\Facades\Artisan;

/** @return array<string, mixed> */
function runSupportReport(array $options = []): array
{
    expect(Artisan::call('sheath:support', ['--json' => true, ...$options]))->toBe(0);

    /** @var array<string, mixed> $report */
    $report = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    return $report;
}

it('prints the Sheath, environment, and project details', function (): void {
    $this->artisan('sheath:support')
        ->expectsOutputToContain('Editor protocol')
        ->expectsOutputToContain('Parallel linting')
        ->expectsOutputToContain(PHP_VERSION)
        ->expectsOutputToContain(app()->version())
        ->expectsOutputToContain('package defaults')
        ->expectsOutputToContain('recommended')
        ->expectsOutputToContain('livewire/livewire')
        ->assertSuccessful();
});

it('reports the same details as JSON', function (): void {
    $report = runSupportReport();

    expect($report['sheath']['editorProtocolVersion'])->toBe(EditorCommand::PROTOCOL_VERSION)
        ->and($report['sheath']['reportSchemaVersion'])->toBe(JsonReporter::SCHEMA_VERSION)
        ->and($report['sheath']['version'])->toBeString()
        ->and($report['sheath']['parallel']['available'])->toBeTrue()
        ->and($report['sheath']['parallel']['missing'])->toBe([])
        ->and($report['sheath']['parallel']['processes'])->toBeGreaterThanOrEqual(1)
        ->and($report['environment']['php']['version'])->toBe(PHP_VERSION)
        ->and($report['environment']['php']['binary'])->toBe(PHP_BINARY)
        ->and($report['environment']['laravel'])->toBe(app()->version())
        ->and($report['environment']['forte'])->toBeString()
        ->and($report['environment']['mbstring'])->toBeTrue()
        ->and($report['project']['basePath'])->toBe(base_path())
        ->and($report['project']['configuration'])->toBe([
            'source' => 'config/sheath.php',
            'published' => false,
            'error' => null,
        ])
        ->and($report['project']['presets'])->toContain('recommended')
        ->and($report['project']['paths'])->toBe(['resources/views'])
        ->and($report['project']['rules']['enabled'])->toBeGreaterThan(0)
        ->and($report['project']['rules']['known'])->toBeGreaterThanOrEqual($report['project']['rules']['enabled'])
        ->and($report['project']['packageRequirementMode'])->toBe('skip')
        ->and($report['project']['baseline']['exists'])->toBeFalse()
        ->and($report['project']['cache']['path'])->toBe(base_path('.sheath-cache'))
        ->and($report['project']['cache']['exists'])->toBeBool()
        ->and($report['packages'])->toHaveKeys(['fortephp/sheath', 'laravel/framework', 'livewire/livewire'])
        ->and($report['packages']['laravel/framework'])->toBeString();
});

it('reports configuration problems without failing', function (): void {
    $report = runSupportReport(['--config' => 'missing-sheath-config.php']);

    expect($report['project']['configuration']['source'])->toBe('missing-sheath-config.php')
        ->and($report['project']['configuration']['published'])->toBeNull()
        ->and($report['project']['configuration']['error'])->toContain('missing-sheath-config.php')
        ->and($report['project']['presets'])->toBeNull()
        ->and($report['project']['rules'])->toBeNull();

    $this->artisan('sheath:support', ['--config' => 'missing-sheath-config.php'])
        ->expectsOutputToContain('Configuration error')
        ->expectsOutputToContain('missing-sheath-config.php')
        ->assertSuccessful();
});

it('reads a custom configuration file', function (): void {
    withTempFile(function (string $path): void {
        file_put_contents($path, '<?php return ["preset" => "empty", "rules" => ["a11y-alt-text" => "error"], "paths" => ["resources/custom"]];');

        $report = runSupportReport(['--config' => $path]);

        expect($report['project']['configuration']['error'])->toBeNull()
            ->and($report['project']['presets'])->toBe(['empty'])
            ->and($report['project']['paths'])->toBe(['resources/custom'])
            ->and($report['project']['rules']['enabled'])->toBe(1);
    }, '.php');
});

it('detects a baseline file in the project root', function (): void {
    // Use a private base path so parallel test processes never share the baseline file.
    withTempDir(function (string $root): void {
        $originalBasePath = base_path();
        app()->setBasePath($root);

        try {
            $path = $root.DIRECTORY_SEPARATOR.Baseline::DEFAULT_PATH;
            file_put_contents($path, '{}');

            $report = runSupportReport();

            expect($report['project']['basePath'])->toBe($root)
                ->and($report['project']['baseline'])->toBe(['path' => $path, 'exists' => true]);

            $this->artisan('sheath:support')
                ->expectsOutputToContain(Baseline::DEFAULT_PATH.' (found)')
                ->assertSuccessful();
        } finally {
            app()->setBasePath($originalBasePath);
        }
    });
});
