<?php

declare(strict_types=1);

use Forte\Sheath\Reporters\CompactReporter;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;

it('formats single violation on one line', function (): void {
    $reporter = new CompactReporter;

    $result = new LintResult('test.blade.php', [
        new Violation(
            ruleId: 'test-rule',
            message: 'Test error',
            severity: Severity::ERROR,
            filePath: 'test.blade.php',
            start: new Position(0, 5, 10),
            end: new Position(20, 5, 20)
        ),
    ]);

    $output = $reporter->format($result);

    expect($output)->toBe('test.blade.php: line 5, col 10, ERROR - Test error (test-rule)');
});

it('formats multiple violations with newlines', function (): void {
    $reporter = new CompactReporter;

    $violations = [
        new Violation(
            ruleId: 'error-rule',
            message: 'Error message',
            severity: Severity::ERROR,
            filePath: 'test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 5)
        ),
        new Violation(
            ruleId: 'warning-rule',
            message: 'Warning message',
            severity: Severity::WARNING,
            filePath: 'test.blade.php',
            start: new Position(0, 2, 3),
            end: new Position(8, 2, 8)
        ),
    ];

    $result = new LintResult('test.blade.php', $violations);
    $output = $reporter->format($result);

    $lines = explode("\n", $output);

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toContain('line 1, col 1, ERROR')
        ->and($lines[0])->toContain('Error message')
        ->and($lines[0])->toContain('error-rule')
        ->and($lines[1])->toContain('line 2, col 3, WARNING')
        ->and($lines[1])->toContain('Warning message')
        ->and($lines[1])->toContain('warning-rule');
});

it('uppercases severity level', function (): void {
    $reporter = new CompactReporter;

    $result = new LintResult('test.blade.php', [
        new Violation(
            ruleId: 'test',
            message: 'Test',
            severity: Severity::INFO,
            filePath: 'test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 5)
        ),
    ]);

    $output = $reporter->format($result);

    expect($output)->toContain('INFO')
        ->not->toContain('info');
});

it('formats multiple results', function (): void {
    $reporter = new CompactReporter;

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

    expect($output)
        ->toContain('file1.blade.php')
        ->toContain('file2.blade.php')
        ->toContain('Error 1')
        ->toContain('Warning 1');
});
