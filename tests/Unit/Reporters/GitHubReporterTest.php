<?php

declare(strict_types=1);

use Forte\Sheath\Reporters\GitHubReporter;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;

it('returns empty string when no violations', function (): void {
    $reporter = new GitHubReporter;
    $result = new LintResult('test.blade.php', []);

    expect($reporter->format($result))->toBe('');
});

it('formats error as GitHub Actions annotation', function (): void {
    $reporter = new GitHubReporter;

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

    expect($output)->toBe('::error file=test.blade.php,line=5,col=10::Test error [test-rule]');
});

it('formats warning as GitHub Actions annotation', function (): void {
    $reporter = new GitHubReporter;

    $result = new LintResult('test.blade.php', [
        new Violation(
            ruleId: 'test-rule',
            message: 'Test warning',
            severity: Severity::WARNING,
            filePath: 'test.blade.php',
            start: new Position(0, 3, 5),
            end: new Position(15, 3, 15)
        ),
    ]);

    $output = $reporter->format($result);

    expect($output)->toBe('::warning file=test.blade.php,line=3,col=5::Test warning [test-rule]');
});

it('formats info as notice annotation', function (): void {
    $reporter = new GitHubReporter;

    $result = new LintResult('test.blade.php', [
        new Violation(
            ruleId: 'test-rule',
            message: 'Test info',
            severity: Severity::INFO,
            filePath: 'test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 5)
        ),
    ]);

    $output = $reporter->format($result);

    expect($output)->toBe('::notice file=test.blade.php,line=1,col=1::Test info [test-rule]');
});

it('formats multiple violations with newlines', function (): void {
    $reporter = new GitHubReporter;

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
        ->and($lines[0])->toBe('::error file=test.blade.php,line=1,col=1::Error message [error-rule]')
        ->and($lines[1])->toBe('::warning file=test.blade.php,line=2,col=3::Warning message [warning-rule]');
});

it('formats multiple results', function (): void {
    $reporter = new GitHubReporter;

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
        ->toContain('::error file=file1.blade.php')
        ->toContain('::warning file=file2.blade.php')
        ->toContain('Error 1')
        ->toContain('Warning 1');
});

it('follows GitHub Actions annotation format', function (): void {
    $reporter = new GitHubReporter;

    $result = new LintResult('src/components/App.blade.php', [
        new Violation(
            ruleId: 'a11y-alt-text',
            message: 'Images must have alt text',
            severity: Severity::ERROR,
            filePath: 'src/components/App.blade.php',
            start: new Position(0, 42, 13),
            end: new Position(20, 42, 20)
        ),
    ]);

    $output = $reporter->format($result);

    expect($output)->toMatch('/^::(error|warning|notice) file=[^,]+,line=\d+,col=\d+::.+$/');
});

it('emits project-relative paths so annotations attach to PR files', function (): void {
    $reporter = new GitHubReporter;

    $absolute = base_path('resources/views/pages/home.blade.php');

    $result = new LintResult($absolute, [
        new Violation(
            ruleId: 'test-rule',
            message: 'Test error',
            severity: Severity::ERROR,
            filePath: $absolute,
            start: new Position(0, 5, 10),
            end: new Position(20, 5, 20)
        ),
    ]);

    expect($reporter->format($result))
        ->toBe('::error file=resources/views/pages/home.blade.php,line=5,col=10::Test error [test-rule]');
});

it('leaves paths outside the project root absolute', function (): void {
    $reporter = new GitHubReporter;

    $result = new LintResult('/somewhere/else/view.blade.php', [
        new Violation(
            ruleId: 'test-rule',
            message: 'Test error',
            severity: Severity::ERROR,
            filePath: '/somewhere/else/view.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 5)
        ),
    ]);

    expect($reporter->format($result))
        ->toContain('file=/somewhere/else/view.blade.php,line=1');
});
