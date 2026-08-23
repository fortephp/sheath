<?php

declare(strict_types=1);

use Forte\Sheath\Reporters\CheckstyleReporter;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;

it('returns empty string when no violations', function (): void {
    $reporter = new CheckstyleReporter;
    $result = new LintResult('test.blade.php', []);

    expect($reporter->format($result))->toBe('');
});

it('formats single result as XML', function (): void {
    $reporter = new CheckstyleReporter;

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

    expect($output)
        ->toContain('<file name="test.blade.php">')
        ->toContain('</file>')
        ->toContain('<error line="5" column="10" severity="error" message="Test error" source="test-rule"/>')
        ->not->toContain('<?xml');
});

it('formats multiple results with XML declaration', function (): void {
    $reporter = new CheckstyleReporter;

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
        ->toContain('<?xml version="1.0" encoding="UTF-8"?>')
        ->toContain('<checkstyle version="1.0.0">')
        ->toContain('</checkstyle>')
        ->toContain('<file name="file1.blade.php">')
        ->toContain('<file name="file2.blade.php">');
});

it('escapes XML special characters', function (): void {
    $reporter = new CheckstyleReporter;

    $result = new LintResult('test.blade.php', [
        new Violation(
            ruleId: 'test<rule>',
            message: 'Error with & and " quotes',
            severity: Severity::ERROR,
            filePath: 'test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 5)
        ),
    ]);

    $output = $reporter->format($result);

    expect($output)
        ->toContain('&amp;')
        ->toContain('&quot;')
        ->toContain('&lt;')
        ->toContain('&gt;')
        ->not->toContain('Error with & and " quotes')
        ->not->toContain('test<rule>');
});

it('produces valid XML', function (): void {
    $reporter = new CheckstyleReporter;

    $results = [
        new LintResult('test.blade.php', [
            new Violation(
                ruleId: 'test-rule',
                message: 'Test error',
                severity: Severity::ERROR,
                filePath: 'test.blade.php',
                start: new Position(0, 1, 1),
                end: new Position(5, 1, 5)
            ),
        ]),
    ];

    $output = $reporter->formatMany($results);

    $xml = @simplexml_load_string($output);

    expect($xml)->not->toBeFalse()
        ->and($xml->getName())->toBe('checkstyle');
});

it('includes all severity levels', function (): void {
    $reporter = new CheckstyleReporter;

    $result = new LintResult('test.blade.php', [
        new Violation(
            ruleId: 'error-rule',
            message: 'Error',
            severity: Severity::ERROR,
            filePath: 'test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 5)
        ),
        new Violation(
            ruleId: 'warning-rule',
            message: 'Warning',
            severity: Severity::WARNING,
            filePath: 'test.blade.php',
            start: new Position(0, 2, 1),
            end: new Position(5, 2, 5)
        ),
        new Violation(
            ruleId: 'info-rule',
            message: 'Info',
            severity: Severity::INFO,
            filePath: 'test.blade.php',
            start: new Position(0, 3, 1),
            end: new Position(5, 3, 5)
        ),
    ]);

    $output = $reporter->format($result);

    expect($output)
        ->toContain('severity="error"')
        ->toContain('severity="warning"')
        ->toContain('severity="info"');
});

it('skips files with no violations in formatMany', function (): void {
    $reporter = new CheckstyleReporter;

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
        new LintResult('file2.blade.php', []),
    ];

    $output = $reporter->formatMany($results);

    expect($output)
        ->toContain('file1.blade.php')
        ->not->toContain('file2.blade.php');
});
