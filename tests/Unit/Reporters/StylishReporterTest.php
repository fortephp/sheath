<?php

declare(strict_types=1);

use Forte\Sheath\Reporters\StylishReporter;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;
use Symfony\Component\Console\Formatter\OutputFormatter;

function renderStylishPlain(string $markup): string
{
    return (new OutputFormatter(false))->format($markup) ?? '';
}

it('returns empty string when no violations', function (): void {
    $reporter = new StylishReporter;
    $result = new LintResult('test.blade.php', []);

    expect($reporter->format($result))->toBe('');
});

it('formats single result with violations', function (): void {
    $reporter = new StylishReporter;

    $violations = [
        new Violation(
            ruleId: 'test-error',
            message: 'This is an error',
            severity: Severity::ERROR,
            filePath: 'test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(10, 1, 10)
        ),
        new Violation(
            ruleId: 'test-warning',
            message: 'This is a warning',
            severity: Severity::WARNING,
            filePath: 'test.blade.php',
            start: new Position(0, 2, 5),
            end: new Position(15, 2, 15)
        ),
    ];

    $result = new LintResult('test.blade.php', $violations);
    $output = $reporter->format($result);

    expect($output)
        ->toContain('test.blade.php')
        ->toContain('✗')
        ->toContain('⚠')
        ->toContain('1:1')
        ->toContain('2:5')
        ->toContain('This is an error')
        ->toContain('This is a warning')
        ->toContain('test-error')
        ->toContain('test-warning')
        ->toContain('1 error, 1 warning');
});

it('formats multiple results with totals', function (): void {
    $reporter = new StylishReporter;

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
            new Violation(
                ruleId: 'rule2',
                message: 'Error 2',
                severity: Severity::ERROR,
                filePath: 'file1.blade.php',
                start: new Position(0, 2, 1),
                end: new Position(5, 2, 5)
            ),
        ]),
        new LintResult('file2.blade.php', [
            new Violation(
                ruleId: 'rule3',
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
        ->toContain('Total: 2 errors, 1 warning in 2 files');
});

it('pluralizes correctly', function (): void {
    $reporter = new StylishReporter;

    $result = new LintResult('test.blade.php', [
        new Violation(
            ruleId: 'rule1',
            message: 'Error',
            severity: Severity::ERROR,
            filePath: 'test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 5)
        ),
    ]);

    $output = $reporter->format($result);

    expect($output)->toContain('1 error, 0 warnings');
});

it('prints long messages in full', function (): void {
    $reporter = new StylishReporter;

    $longMessage = 'Button is missing an explicit type attribute.';

    $result = new LintResult('test.blade.php', [
        new Violation(
            ruleId: 'rule1',
            message: $longMessage,
            severity: Severity::ERROR,
            filePath: 'test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 5)
        ),
    ]);

    expect($reporter->format($result))
        ->toContain($longMessage)
        ->not->toContain('...');
});

it('aligns the rule id past the longest message in the file', function (): void {
    $reporter = new StylishReporter;

    $result = new LintResult('test.blade.php', [
        new Violation(
            ruleId: 'rule-short',
            message: 'Short.',
            severity: Severity::ERROR,
            filePath: 'test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 5)
        ),
        new Violation(
            ruleId: 'rule-long',
            message: 'A considerably longer message than the one above it.',
            severity: Severity::WARNING,
            filePath: 'test.blade.php',
            start: new Position(6, 2, 1),
            end: new Position(9, 2, 4)
        ),
    ]);

    $lines = array_values(array_filter(
        explode("\n", renderStylishPlain($reporter->format($result))),
        static fn (string $line): bool => str_contains($line, 'rule-')
    ));

    expect($lines)->toHaveCount(2)
        ->and(mb_strpos($lines[0], 'rule-short'))->toBe(mb_strpos($lines[1], 'rule-long'));
});

it('handles info severity', function (): void {
    $reporter = new StylishReporter;

    $result = new LintResult('test.blade.php', [
        new Violation(
            ruleId: 'info-rule',
            message: 'This is info',
            severity: Severity::INFO,
            filePath: 'test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 5)
        ),
    ]);

    $output = $reporter->format($result);

    expect($output)->toContain('ℹ');
});

it('colours the report through console markup an undecorated formatter strips', function (): void {
    $reporter = new StylishReporter;

    $result = new LintResult('test.blade.php', [
        new Violation(
            ruleId: 'rule-red',
            message: 'An error',
            severity: Severity::ERROR,
            filePath: 'test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 5)
        ),
    ]);

    $raw = $reporter->format($result);
    $decorated = (new OutputFormatter(true))->format($raw) ?? '';
    $plain = renderStylishPlain($raw);

    expect($decorated)->toContain("\033[")
        ->and($plain)->not->toContain("\033[")
        ->and($plain)->not->toContain('<fg=')
        ->and($plain)->not->toContain('<options=');
});

it('keeps template text out of the markup channel', function (): void {
    $reporter = new StylishReporter;

    $result = new LintResult('test.blade.php', [
        new Violation(
            ruleId: 'rule-1',
            message: 'Move the <info> tag out of <body>',
            severity: Severity::ERROR,
            filePath: 'test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 5)
        ),
    ]);

    expect(renderStylishPlain($reporter->format($result)))
        ->toContain('Move the <info> tag out of <body>');
});

it('starts at the file header and leaves one blank line between file blocks', function (): void {
    $reporter = new StylishReporter;

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

    $plain = renderStylishPlain($reporter->formatMany($results));

    expect($plain)->toStartWith('file1.blade.php')
        ->and($plain)->toContain("  1 error, 0 warnings\n\nfile2.blade.php")
        ->and($plain)->not->toContain("\n\n\n");
});

it('prints totals and the fix hint for an info-only run', function (): void {
    $reporter = new StylishReporter;

    $result = new LintResult('test.blade.php', [
        new Violation(
            ruleId: 'info-rule',
            message: 'This is info',
            severity: Severity::INFO,
            filePath: 'test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 5),
            fix: new Fix(0, 5, 'fixed')
        ),
    ]);

    $plain = renderStylishPlain($reporter->formatMany([$result]));

    expect($plain)
        ->toContain('  0 errors, 0 warnings, 1 info')
        ->toContain('Total: 0 errors, 0 warnings, 1 info in 1 file')
        ->toContain('1 problem can be automatically fixed');
});

it('shows project-relative paths in file headers', function (): void {
    $reporter = new StylishReporter;

    $absolute = base_path('resources/views/pages/home.blade.php');

    $result = new LintResult($absolute, [
        new Violation(
            ruleId: 'rule-1',
            message: 'An error',
            severity: Severity::ERROR,
            filePath: $absolute,
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 5)
        ),
    ]);

    expect(renderStylishPlain($reporter->format($result)))
        ->toStartWith('resources/views/pages/home.blade.php');
});
