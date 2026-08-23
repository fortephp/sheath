<?php

declare(strict_types=1);

use Forte\Sheath\Baselines\Baseline;
use Forte\Sheath\Exceptions\BaselineException;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;

it('indexes violations across files and rules', function (): void {
    $baseline = new Baseline('test.baseline-test.json');

    $violations = [
        ['rule' => 'a11y-alt-text', 'file' => 'view1.blade.php'],
        ['rule' => 'a11y-alt-text', 'file' => 'view2.blade.php'],
        ['rule' => 'security-csrf-field', 'file' => 'view1.blade.php'],
        ['rule' => 'best-practices-button-type', 'file' => 'view3.blade.php'],
    ];

    foreach ($violations as $i => $v) {
        $violation = new Violation(
            ruleId: $v['rule'],
            message: 'Test message',
            severity: Severity::ERROR,
            filePath: $v['file'],
            start: new Position(0, 1, 1),
            end: new Position(10, 1, 10)
        );
        $baseline->addViolation($violation, "hash{$i}");
    }

    expect($baseline->isEmpty())->toBeFalse()
        ->and($baseline->getTotalCount())->toBe(4)
        ->and($baseline->getCountsByRule())->toBe([
            'a11y-alt-text' => 2,
            'security-csrf-field' => 1,
            'best-practices-button-type' => 1,
        ])->and($baseline->getFilePaths())->toBe([
            'view1.blade.php',
            'view2.blade.php',
            'view3.blade.php',
        ]);
});

it('checks if a violation is baselined by hash', function (): void {
    $baseline = new Baseline('test.baseline-test.json');

    $violation = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Images must have an alt attribute',
        severity: Severity::ERROR,
        filePath: 'resources/views/test.blade.php',
        start: new Position(100, 10, 5),
        end: new Position(150, 10, 50)
    );

    $baseline->addViolation($violation, 'abc123');

    expect($baseline->isBaselined($violation, 'abc123'))->toBeTrue()
        ->and($baseline->isBaselined($violation, 'different'))->toBeTrue();

    $differentFile = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Images must have an alt attribute',
        severity: Severity::ERROR,
        filePath: 'resources/views/other.blade.php',
        start: new Position(100, 10, 5),
        end: new Position(150, 10, 50)
    );

    expect($baseline->isBaselined($differentFile, 'abc123'))->toBeFalse();
});

it('checks if a violation is baselined with line tolerance', function (): void {
    $baseline = new Baseline('test.baseline-test.json');

    $violation = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Images must have an alt attribute',
        severity: Severity::ERROR,
        filePath: 'resources/views/test.blade.php',
        start: new Position(100, 10, 5),
        end: new Position(150, 10, 50)
    );

    $baseline->addViolation($violation, 'abc123');

    $withinTolerance = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Images must have an alt attribute',
        severity: Severity::ERROR,
        filePath: 'resources/views/test.blade.php',
        start: new Position(100, 13, 5),
        end: new Position(150, 13, 50)
    );

    expect($baseline->isBaselined($withinTolerance, 'different_hash'))->toBeTrue();

    $withinToleranceMinus = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Images must have an alt attribute',
        severity: Severity::ERROR,
        filePath: 'resources/views/test.blade.php',
        start: new Position(100, 7, 5),
        end: new Position(150, 7, 50)
    );

    expect($baseline->isBaselined($withinToleranceMinus, 'different_hash'))
        ->toBeTrue();

    $outsideTolerance = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Images must have an alt attribute',
        severity: Severity::ERROR,
        filePath: 'resources/views/test.blade.php',
        start: new Position(100, 14, 5),
        end: new Position(150, 14, 50)
    );
    expect($baseline->isBaselined($outsideTolerance, 'different_hash'))
        ->toBeFalse();
});

it('does not match nearby violations when the message changes', function (): void {
    $baseline = new Baseline('test.baseline-test.json');

    $violation = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Images must have an alt attribute',
        severity: Severity::ERROR,
        filePath: 'resources/views/test.blade.php',
        start: new Position(100, 10, 5),
        end: new Position(150, 10, 50)
    );

    $baseline->addViolation($violation, 'abc123');

    $differentMessage = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Images must not use empty alt text',
        severity: Severity::ERROR,
        filePath: 'resources/views/test.blade.php',
        start: new Position(100, 12, 5),
        end: new Position(150, 12, 50)
    );

    expect($baseline->isBaselined($differentMessage, 'different_hash'))->toBeFalse();
});

it('does not match violations with different rule IDs', function (): void {
    $baseline = new Baseline('test.baseline-test.json');

    $violation = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Images must have an alt attribute',
        severity: Severity::ERROR,
        filePath: 'resources/views/test.blade.php',
        start: new Position(100, 10, 5),
        end: new Position(150, 10, 50)
    );

    $baseline->addViolation($violation, 'abc123');

    $differentRule = new Violation(
        ruleId: 'security-csrf-field',
        message: 'Forms must include a CSRF token',
        severity: Severity::ERROR,
        filePath: 'resources/views/test.blade.php',
        start: new Position(100, 10, 5),
        end: new Position(150, 10, 50)
    );
    expect($baseline->isBaselined($differentRule, 'abc123'))
        ->toBeFalse();
});

it('saves and loads baseline from file', function (): void {
    withTempFile(function (string $path): void {
        $baseline = new Baseline($path);

        $violation = new Violation(
            ruleId: 'a11y-alt-text',
            message: 'Images must have an alt attribute',
            severity: Severity::ERROR,
            filePath: 'resources/views/test.blade.php',
            start: new Position(100, 10, 5),
            end: new Position(150, 10, 50)
        );

        $baseline->addViolation($violation, 'abc123');
        $baseline->save();

        $loaded = Baseline::load($path);

        expect($loaded->getTotalCount())->toBe(1)
            ->and($loaded->isBaselined($violation, 'abc123'))->toBeTrue()
            ->and($loaded->getCountsByRule())->toBe(['a11y-alt-text' => 1]);
    }, suffix: '.baseline-test.json');
});

it('replaces an existing baseline atomically', function (): void {
    withTempFile(function (string $path): void {
        file_put_contents($path, '{"old":true}');
        $inodeBefore = fileinode($path);

        $baseline = new Baseline($path);
        expect($baseline->save())->toBeTrue();

        clearstatcache(true, $path);
        if (PHP_OS_FAMILY !== 'Windows') {
            expect(fileinode($path))->not->toBe($inodeBefore);
        }

        expect(fn () => json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR))
            ->not->toThrow(Throwable::class);
    }, suffix: '.baseline-test.json');
});

it('returns empty baseline when file does not exist', function (): void {
    $baseline = Baseline::load('non-existent.baseline-test.json');

    expect($baseline->isEmpty())->toBeTrue()
        ->and($baseline->getTotalCount())->toBe(0);
});

it('rejects invalid JSON instead of silently treating a corrupt baseline as empty', function (): void {
    withTempFile(function (string $path): void {
        file_put_contents($path, 'not valid json');

        expect(fn (): Baseline => Baseline::load($path))
            ->toThrow(BaselineException::class);
    }, suffix: '.baseline-test.json');
});

it('rejects baseline entries with a malformed shape', function (mixed $entry): void {
    withTempFile(function (string $path) use ($entry): void {
        file_put_contents($path, json_encode([
            'version' => Baseline::VERSION,
            'violations' => [
                'resources/views/a.blade.php' => [
                    $entry,
                ],
            ],
        ]));

        expect(fn (): Baseline => Baseline::load($path))
            ->toThrow(BaselineException::class);
    }, suffix: '.baseline-test.json');
})->with([
    'non-array entry' => ['not an entry'],
    'missing rule ID' => [['line' => 3, 'message' => 'Missing alt.', 'hash' => 'abc']],
    'blank rule ID' => [['ruleId' => ' ', 'line' => 3, 'message' => 'Missing alt.', 'hash' => 'abc']],
    'non-integer line' => [['ruleId' => 'a11y-alt-text', 'line' => '3', 'message' => 'Missing alt.', 'hash' => 'abc']],
    'non-positive line' => [['ruleId' => 'a11y-alt-text', 'line' => 0, 'message' => 'Missing alt.', 'hash' => 'abc']],
    'non-string message' => [['ruleId' => 'a11y-alt-text', 'line' => 3, 'message' => null, 'hash' => 'abc']],
    'non-string hash' => [['ruleId' => 'a11y-alt-text', 'line' => 3, 'message' => 'Missing alt.', 'hash' => null]],
]);

it('rejects baselines written by a newer unsupported schema', function (): void {
    withTempFile(function (string $path): void {
        file_put_contents($path, json_encode([
            'version' => Baseline::VERSION + 1,
            'violations' => [],
        ]));

        expect(fn (): Baseline => Baseline::load($path))
            ->toThrow(BaselineException::class);
    }, suffix: '.baseline-test.json');
});

it('filters lint results against baseline', function (): void {
    $baseline = new Baseline('test.baseline-test.json');

    $baselinedViolation = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Images must have an alt attribute',
        severity: Severity::ERROR,
        filePath: 'resources/views/test.blade.php',
        start: new Position(100, 10, 5),
        end: new Position(150, 10, 50)
    );

    $baseline->addViolation($baselinedViolation, 'baseline_hash');

    $newViolation = new Violation(
        ruleId: 'security-csrf-field',
        message: 'Forms must include a CSRF token',
        severity: Severity::ERROR,
        filePath: 'resources/views/test.blade.php',
        start: new Position(200, 20, 1),
        end: new Position(250, 20, 50)
    );

    $results = [
        new LintResult('resources/views/test.blade.php', [
            $baselinedViolation,
            $newViolation,
        ]),
    ];

    $hashGenerator = fn (Violation $v) => $v->ruleId === 'a11y-alt-text' ? 'baseline_hash' : 'new_hash';
    $filtered = $baseline->filterResults($results, $hashGenerator);

    expect($filtered)->toHaveCount(1)
        ->and($filtered[0]->violations)->toHaveCount(1)
        ->and($filtered[0]->violations[0]->ruleId)->toBe('security-csrf-field');
});

it('does not add or filter parser diagnostics', function (): void {
    $baseline = new Baseline('test.baseline-test.json');
    $parseError = new Violation(
        ruleId: 'parse-error',
        message: 'Unexpected end of file inside a Blade echo.',
        severity: Severity::ERROR,
        filePath: 'broken.blade.php',
        start: new Position(0, 1, 1),
        end: new Position(11, 1, 12),
    );

    $baseline->addViolation($parseError, 'parse-hash');
    $filtered = $baseline->filterResult(
        new LintResult('broken.blade.php', [$parseError], hasParseErrors: true),
        fn (Violation $violation): string => 'parse-hash',
    );

    expect($baseline->getTotalCount())->toBe(0)
        ->and($baseline->isBaselined($parseError, 'parse-hash'))->toBeFalse()
        ->and($filtered->violations)->toBe([$parseError])
        ->and($filtered->hasParseErrors)->toBeTrue()
        ->and($filtered->hasErrors())->toBeTrue();
});

it('discards parser diagnostics from older baseline files', function (): void {
    withTempFile(function (string $path): void {
        file_put_contents($path, json_encode([
            'version' => Baseline::VERSION,
            'violations' => [
                'broken.blade.php' => [[
                    'ruleId' => 'parse-error',
                    'line' => 1,
                    'message' => 'Unexpected end of file.',
                    'hash' => 'parse-hash',
                ]],
            ],
        ]));

        $baseline = Baseline::load($path);

        expect($baseline->isEmpty())->toBeTrue()
            ->and($baseline->getTotalCount())->toBe(0);
    }, suffix: '.baseline-test.json');
});

it('gets violations for a specific file', function (): void {
    $baseline = new Baseline('test.baseline-test.json');

    $violation1 = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Message 1',
        severity: Severity::ERROR,
        filePath: 'file1.blade.php',
        start: new Position(0, 1, 1),
        end: new Position(10, 1, 10)
    );
    $violation2 = new Violation(
        ruleId: 'security-csrf-field',
        message: 'Message 2',
        severity: Severity::ERROR,
        filePath: 'file2.blade.php',
        start: new Position(0, 1, 1),
        end: new Position(10, 1, 10)
    );

    $baseline->addViolation($violation1, 'hash1');
    $baseline->addViolation($violation2, 'hash2');

    $file1Violations = $baseline->getViolationsForFile('file1.blade.php');
    $file2Violations = $baseline->getViolationsForFile('file2.blade.php');
    $noViolations = $baseline->getViolationsForFile('nonexistent.blade.php');

    expect($file1Violations)->toHaveCount(1)
        ->and($file1Violations[0]['ruleId'])->toBe('a11y-alt-text')
        ->and($file2Violations)->toHaveCount(1)
        ->and($file2Violations[0]['ruleId'])->toBe('security-csrf-field')
        ->and($noViolations)->toBe([]);
});

it('normalizes baseline file paths', function (string $storedPath): void {
    $baseline = new Baseline('test.baseline-test.json');

    $violation = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Images must have an alt attribute',
        severity: Severity::ERROR,
        filePath: $storedPath,
        start: new Position(100, 10, 5),
        end: new Position(150, 10, 50)
    );

    $baseline->addViolation($violation, 'hash123');

    $violations = $baseline->getViolationsForFile('resources/views/test.blade.php');
    expect($violations)->toHaveCount(1);

    $canonicalViolation = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Images must have an alt attribute',
        severity: Severity::ERROR,
        filePath: 'resources/views/test.blade.php',
        start: new Position(100, 10, 5),
        end: new Position(150, 10, 50)
    );

    expect($baseline->isBaselined($canonicalViolation, 'hash123'))->toBeTrue();
})->with([
    'backslashes' => 'resources\\views\\test.blade.php',
    'leading dot segment' => './resources/views/test.blade.php',
]);

it('tracks when baseline was generated', function (): void {
    withTempFile(function (string $path): void {
        $baseline = new Baseline($path);

        expect($baseline->getGeneratedAt())->toBeNull();

        $violation = new Violation(
            ruleId: 'a11y-alt-text',
            message: 'Test',
            severity: Severity::ERROR,
            filePath: 'test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(10, 1, 10)
        );
        $baseline->addViolation($violation, 'hash');
        $baseline->save();

        expect($baseline->getGeneratedAt())->not->toBeNull();

        $loaded = Baseline::load($path);

        expect($loaded->getGeneratedAt())->not->toBeNull();
    }, suffix: '.baseline-test.json');
});

it('prunes fixed violations', function (): void {
    $baseline = new Baseline('test.baseline-test.json');

    for ($i = 1; $i <= 3; $i++) {
        $violation = new Violation(
            ruleId: 'a11y-alt-text',
            message: "Message {$i}",
            severity: Severity::ERROR,
            filePath: 'test.blade.php',
            start: new Position($i * 100, $i * 10, 1),
            end: new Position($i * 100 + 50, $i * 10, 50)
        );

        $baseline->addViolation($violation, "hash{$i}");
    }

    expect($baseline->getTotalCount())
        ->toBe(3);

    $remainingViolation = new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Message 2',
        severity: Severity::ERROR,
        filePath: 'test.blade.php',
        start: new Position(200, 20, 1),
        end: new Position(250, 20, 50)
    );

    $currentResults = [
        new LintResult('test.blade.php', [$remainingViolation]),
    ];

    $hashGenerator = fn (Violation $v) => 'hash2';

    $baseline->pruneFixed($currentResults, $hashGenerator);

    expect($baseline->getTotalCount())->toBe(1)
        ->and($baseline->getCountsByRule())->toBe(['a11y-alt-text' => 1]);
});

it('prunes violations using the message when hash changes within tolerance', function (): void {
    $baseline = new Baseline('test.baseline-test.json');

    $baseline->addViolation(new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Message 1',
        severity: Severity::ERROR,
        filePath: 'test.blade.php',
        start: new Position(100, 10, 1),
        end: new Position(150, 10, 50)
    ), 'hash1');

    $baseline->addViolation(new Violation(
        ruleId: 'a11y-alt-text',
        message: 'Message 2',
        severity: Severity::ERROR,
        filePath: 'test.blade.php',
        start: new Position(200, 20, 1),
        end: new Position(250, 20, 50)
    ), 'hash2');

    $currentResults = [
        new LintResult('test.blade.php', [
            new Violation(
                ruleId: 'a11y-alt-text',
                message: 'Message 2',
                severity: Severity::ERROR,
                filePath: 'test.blade.php',
                start: new Position(220, 22, 1),
                end: new Position(270, 22, 50)
            ),
        ]),
    ];

    $baseline->pruneFixed($currentResults, fn (Violation $v) => 'different-hash');

    expect($baseline->getTotalCount())->toBe(1)
        ->and($baseline->getViolationsForFile('test.blade.php')[0]['message'])->toBe('Message 2');
});

it('honors custom line tolerance across construction and loading', function (): void {
    expect((new Baseline('test.baseline-test.json', 5))->getLineTolerance())->toBe(5)
        ->and(Baseline::load('test.baseline-test.json', 10)->getLineTolerance())->toBe(10);
});

it('loads a baseline written by an older schema version', function (): void {
    withTempFile(function (string $path): void {
        file_put_contents($path, json_encode([
            'version' => 1,
            'generated' => '2020-01-01T00:00:00+00:00',
            'violations' => [
                'resources/views/a.blade.php' => [
                    ['ruleId' => 'a11y-alt-text', 'line' => 3, 'message' => 'Images must have an alt attribute.', 'hash' => 'a1b2c3d4'],
                ],
            ],
            'counts' => ['total' => 1, 'byRule' => ['a11y-alt-text' => 1]],
        ]));

        $baseline = Baseline::load($path);

        expect($baseline->getTotalCount())->toBe(1)
            ->and($baseline->getViolationsForFile('resources/views/a.blade.php'))->toHaveCount(1);
    }, suffix: '.baseline-test.json');
});
