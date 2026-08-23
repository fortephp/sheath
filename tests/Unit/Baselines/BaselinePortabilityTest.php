<?php

declare(strict_types=1);

use Forte\Sheath\Baselines\Baseline;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;

function portabilityViolation(string $filePath, string $ruleId = 'a11y-alt-text'): Violation
{
    return new Violation(
        ruleId: $ruleId,
        message: 'Images must have an alt attribute',
        severity: Severity::ERROR,
        filePath: $filePath,
        start: new Position(100, 10, 5),
        end: new Position(150, 10, 50),
    );
}

it('records file paths relative to the project root', function (): void {
    $baseline = new Baseline('test.portability-test.json');

    $baseline->addViolation(
        portabilityViolation(base_path('resources/views/home.blade.php')),
        'abc123'
    );

    expect($baseline->getFilePaths())->toBe(['resources/views/home.blade.php']);
});

it('matches a violation discovered by absolute path against a relative entry', function (): void {
    $baseline = new Baseline('test.portability-test.json');
    $absolute = base_path('resources/views/home.blade.php');

    $baseline->addViolation(portabilityViolation($absolute), 'abc123');

    expect($baseline->isBaselined(portabilityViolation($absolute), 'abc123'))->toBeTrue();
});

it('applies a baseline written in a different checkout', function (): void {
    withTempFile(function (string $path): void {
        file_put_contents($path, (string) json_encode([
            'version' => Baseline::VERSION,
            'generated' => '2026-01-01T00:00:00+00:00',
            'violations' => [
                'resources/views/home.blade.php' => [
                    ['ruleId' => 'a11y-alt-text', 'line' => 10, 'message' => 'Images must have an alt attribute', 'hash' => 'abc123'],
                ],
            ],
            'counts' => ['total' => 1, 'byRule' => ['a11y-alt-text' => 1]],
        ]));

        $loaded = Baseline::load($path);

        $local = portabilityViolation(base_path('resources/views/home.blade.php'));
        $result = new LintResult(base_path('resources/views/home.blade.php'), [$local], false);

        $filtered = $loaded->filterResult($result, fn (): string => 'abc123');

        expect($filtered->violations)->toBeEmpty();
    }, suffix: '.portability-test.json');
});

it('carries a version 2 baseline over by rewriting its absolute keys', function (): void {
    withTempFile(function (string $path): void {
        $absolute = str_replace('\\', '/', base_path('resources/views/home.blade.php'));

        file_put_contents($path, (string) json_encode([
            'version' => 2,
            'generated' => '2026-01-01T00:00:00+00:00',
            'violations' => [
                $absolute => [
                    ['ruleId' => 'a11y-alt-text', 'line' => 10, 'message' => 'Images must have an alt attribute', 'hash' => 'abc123'],
                ],
            ],
            'counts' => ['total' => 1, 'byRule' => ['a11y-alt-text' => 1]],
        ]));

        $loaded = Baseline::load($path);

        expect($loaded->getFilePaths())->toBe(['resources/views/home.blade.php'])
            ->and($loaded->isBaselined(
                portabilityViolation(base_path('resources/views/home.blade.php')),
                'abc123'
            ))->toBeTrue();
    }, suffix: '.portability-test.json');
});

it('writes the schema version that records relative paths', function (): void {
    withTempFile(function (string $path): void {
        $baseline = new Baseline($path);
        $baseline->addViolation(portabilityViolation(base_path('resources/views/home.blade.php')), 'abc123');
        $baseline->save();

        $data = json_decode((string) file_get_contents($path), true);

        expect($data['version'])->toBe(3);
    }, suffix: '.portability-test.json');
});

it('leaves a path outside the project root absolute, having no relative form', function (): void {
    $outside = PHP_OS_FAMILY === 'Windows'
        ? 'D:/elsewhere/views/home.blade.php'
        : '/elsewhere/views/home.blade.php';

    $baseline = new Baseline('test.portability-test.json');
    $baseline->addViolation(portabilityViolation($outside), 'abc123');

    expect($baseline->getFilePaths())->toBe([$outside]);
});
