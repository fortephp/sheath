<?php

declare(strict_types=1);

use Forte\Sheath\Reporters\JsonReporter;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;

it('returns the versioned envelope when a single result has no violations', function (): void {
    $reporter = new JsonReporter;
    $result = new LintResult('test.blade.php', []);

    $data = json_decode($reporter->format($result), true);

    expect($data['schemaVersion'])->toBe(JsonReporter::SCHEMA_VERSION)
        ->and($data['results'])->toHaveCount(1)
        ->and($data['results'][0]['filePath'])->toBe('test.blade.php')
        ->and($data['results'][0]['violations'])->toBe([])
        ->and($data['summary']['files'])->toBe(0);
});

it('formats single result as JSON', function (): void {
    $reporter = new JsonReporter;

    $violations = [
        new Violation(
            ruleId: 'test-rule',
            message: 'Test error',
            severity: Severity::ERROR,
            filePath: 'test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(10, 1, 10)
        ),
        new Violation(
            ruleId: 'test-warning',
            message: 'Test warning',
            severity: Severity::WARNING,
            filePath: 'test.blade.php',
            start: new Position(0, 2, 5),
            end: new Position(15, 2, 15)
        ),
    ];

    $result = new LintResult('test.blade.php', $violations);
    $output = $reporter->format($result);

    $data = json_decode($output, true);

    $single = $data['results'][0];

    expect($data['schemaVersion'])->toBe(JsonReporter::SCHEMA_VERSION)
        ->and($single['filePath'])->toBe('test.blade.php')
        ->and($single['errorCount'])->toBe(1)
        ->and($single['warningCount'])->toBe(1)
        ->and($single['infoCount'])->toBe(0)
        ->and($single['fixableCount'])->toBe(0)
        ->and($single['violations'])->toHaveCount(2)
        ->and($single['violations'][0]['ruleId'])->toBe('test-rule')
        ->and($single['violations'][0]['message'])->toBe('Test error')
        ->and($single['violations'][0]['severity'])->toBe('error')
        ->and($single['violations'][0]['line'])->toBe(1)
        ->and($single['violations'][0]['column'])->toBe(1)
        ->and($single['violations'][1]['ruleId'])->toBe('test-warning')
        ->and($single['violations'][1]['severity'])->toBe('warning');
});

it('formats multiple results as JSON', function (): void {
    $reporter = new JsonReporter;

    $results = [
        new LintResult('file1.blade.php', [
            new Violation(
                ruleId: 'rule1',
                message: 'Error 1',
                severity: Severity::ERROR,
                filePath: 'file1.blade.php',
                start: new Position(0, 1, 1),
                end: new Position(5, 1, 5)
            ),
        ]),
        new LintResult('file2.blade.php', [
            new Violation(
                ruleId: 'rule2',
                message: 'Warning 1',
                severity: Severity::WARNING,
                filePath: 'file2.blade.php',
                start: new Position(0, 3, 2),
                end: new Position(8, 3, 8)
            ),
        ]),
    ];

    $output = $reporter->formatMany($results);
    $data = json_decode($output, true);

    expect($data)->toBeArray()
        ->and($data['schemaVersion'])->toBe(JsonReporter::SCHEMA_VERSION)
        ->and($data['results'])->toHaveCount(2)
        ->and($data['results'][0]['filePath'])->toBe('file1.blade.php')
        ->and($data['results'][1]['filePath'])->toBe('file2.blade.php')
        ->and($data['summary']['files'])->toBe(2)
        ->and($data['summary']['errors'])->toBe(1)
        ->and($data['summary']['warnings'])->toBe(1);
});

it('produces valid JSON', function (): void {
    $reporter = new JsonReporter;

    $result = new LintResult('test.blade.php', [
        new Violation(
            ruleId: 'test',
            message: 'Test "quoted" message',
            severity: Severity::ERROR,
            filePath: 'test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 5)
        ),
    ]);

    $output = $reporter->format($result);

    expect(json_decode($output))->not->toBeNull();
});

it('base64 encodes non-UTF-8 fix replacements without losing bytes', function (): void {
    $replacement = "class=\"before\xFFafter\"";
    $result = new LintResult('legacy.blade.php', [
        new Violation(
            ruleId: 'test',
            message: 'Test fix',
            severity: Severity::ERROR,
            filePath: 'legacy.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 6),
            fix: new Fix(0, 5, $replacement),
        ),
    ]);

    $data = json_decode((new JsonReporter)->format($result), true, flags: JSON_THROW_ON_ERROR);
    $fix = $data['results'][0]['violations'][0]['fix'];

    expect($fix['replacementEncoding'])->toBe('base64')
        ->and(base64_decode($fix['replacement'], true))->toBe($replacement);
});

it('leaves UTF-8 fix replacements directly usable', function (): void {
    $replacement = 'class="café"';
    $result = new LintResult('valid.blade.php', [
        new Violation(
            ruleId: 'test',
            message: 'Test fix',
            severity: Severity::ERROR,
            filePath: 'valid.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 6),
            fix: new Fix(0, 5, $replacement),
        ),
    ]);

    $data = json_decode((new JsonReporter)->format($result), true, flags: JSON_THROW_ON_ERROR);
    $fix = $data['results'][0]['violations'][0]['fix'];

    expect($fix['replacement'])->toBe($replacement)
        ->and($fix)->not->toHaveKey('replacementEncoding');
});
