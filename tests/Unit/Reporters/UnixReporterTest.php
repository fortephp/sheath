<?php

declare(strict_types=1);

use Forte\Sheath\Reporters\UnixReporter;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;

it('formats in grep-friendly format', function (): void {
    $reporter = new UnixReporter;

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

    expect($output)->toBe('test.blade.php:5:10: Test error [test-rule]');
});

it('formats multiple violations with newlines', function (): void {
    $reporter = new UnixReporter;

    $violations = [
        new Violation(
            ruleId: 'error-rule',
            message: 'Error message',
            severity: Severity::ERROR,
            filePath: 'src/test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 5)
        ),
        new Violation(
            ruleId: 'warning-rule',
            message: 'Warning message',
            severity: Severity::WARNING,
            filePath: 'src/test.blade.php',
            start: new Position(0, 2, 3),
            end: new Position(8, 2, 8)
        ),
    ];

    $result = new LintResult('src/test.blade.php', $violations);
    $output = $reporter->format($result);

    $lines = explode("\n", $output);

    expect($lines)->toHaveCount(2)
        ->and($lines[0])->toBe('src/test.blade.php:1:1: Error message [error-rule]')
        ->and($lines[1])->toBe('src/test.blade.php:2:3: Warning message [warning-rule]');
});

it('formats multiple results', function (): void {
    $reporter = new UnixReporter;

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
        ->toContain('file1.blade.php:1:1:')
        ->toContain('file2.blade.php:3:2:')
        ->toContain('Error 1')
        ->toContain('Warning 1');
});
