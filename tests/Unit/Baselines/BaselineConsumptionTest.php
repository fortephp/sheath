<?php

declare(strict_types=1);

use Forte\Sheath\Baselines\Baseline;
use Forte\Sheath\Baselines\BaselineGenerator;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;

function violationAt(int $line, string $ruleId = 'a11y-alt-text', string $message = 'Images must have an alt attribute.'): Violation
{
    return new Violation(
        ruleId: $ruleId,
        message: $message,
        severity: Severity::ERROR,
        filePath: 'resources/views/page.blade.php',
        start: new Position($line * 10, $line, 1),
        end: new Position($line * 10 + 5, $line, 6),
    );
}

function driftedHashes(): callable
{
    return fn (Violation $v): string => 'drift-'.$v->getLine();
}

function resultWith(Violation ...$violations): LintResult
{
    return new LintResult('resources/views/page.blade.php', array_values($violations));
}

function referenceFilter(array $entries, array $violations, callable $hashGenerator, int $tolerance): array
{
    $unclaimed = array_values($entries);
    $unmatched = $violations;
    $hashes = array_map($hashGenerator, $violations);

    foreach ([true, false] as $exactOnly) {
        foreach ($unmatched as $position => $violation) {
            foreach ($unclaimed as $index => $entry) {
                if ($violation->ruleId !== $entry['ruleId']) {
                    continue;
                }

                $matches = $hashes[$position] === $entry['hash']
                    || (! $exactOnly
                        && $violation->message === $entry['message']
                        && abs($violation->getLine() - $entry['line']) <= $tolerance);

                if ($matches) {
                    unset($unclaimed[$index], $unmatched[$position]);

                    break;
                }
            }
        }
    }

    return array_values($unmatched);
}

function referencePrune(array $baselinedEntries, array $currentEntries, int $tolerance): array
{
    $unclaimed = $currentEntries;
    $kept = [];

    foreach ($baselinedEntries as $baselined) {
        foreach ($unclaimed as $index => $current) {
            $matches = $current['ruleId'] === $baselined['ruleId']
                && ($current['hash'] === $baselined['hash']
                    || ($current['message'] === $baselined['message']
                        && abs($current['line'] - $baselined['line']) <= $tolerance));

            if ($matches) {
                $kept[] = $baselined;
                unset($unclaimed[$index]);

                break;
            }
        }
    }

    return $kept;
}

describe('a baseline entry excuses one violation', function (): void {
    it('reports violations beyond the number recorded', function (): void {
        $baseline = new Baseline('consumption.baseline-test.json');
        $baseline->addViolation(violationAt(10), 'drift-10');

        $filtered = $baseline->filterResult(
            resultWith(violationAt(10), violationAt(11), violationAt(12)),
            driftedHashes()
        );

        expect(array_map(fn (Violation $v): int => $v->getLine(), $filtered->violations))
            ->toBe([11, 12]);
    });

    it('suppresses exactly as many as it recorded', function (): void {
        $baseline = new Baseline('consumption.baseline-test.json');
        $baseline->addViolation(violationAt(10), 'drift-10');
        $baseline->addViolation(violationAt(11), 'drift-11');

        $filtered = $baseline->filterResult(
            resultWith(violationAt(10), violationAt(11), violationAt(12)),
            driftedHashes()
        );

        expect($filtered->violations)->toHaveCount(1)
            ->and($filtered->violations[0]->getLine())->toBe(12);
    });

    it('suppresses everything when the counts match', function (): void {
        $baseline = new Baseline('consumption.baseline-test.json');
        $baseline->addViolation(violationAt(10), 'drift-10');
        $baseline->addViolation(violationAt(11), 'drift-11');

        $filtered = $baseline->filterResult(
            resultWith(violationAt(10), violationAt(11)),
            driftedHashes()
        );

        expect($filtered->violations)->toBe([]);
    });

    it('keeps entries separate across rules', function (): void {
        $baseline = new Baseline('consumption.baseline-test.json');
        $baseline->addViolation(violationAt(10, 'a11y-alt-text'), 'drift-a');

        $filtered = $baseline->filterResult(
            resultWith(
                violationAt(10, 'a11y-alt-text'),
                violationAt(10, 'best-practices-button-type', 'Buttons need a type.')
            ),
            driftedHashes()
        );

        expect($filtered->violations)->toHaveCount(1)
            ->and($filtered->violations[0]->ruleId)->toBe('best-practices-button-type');
    });

    it('prefers an exact hash match over a drifted one', function (): void {
        $baseline = new Baseline('consumption.baseline-test.json');
        $baseline->addViolation(violationAt(10), 'stable');

        $hashes = fn (Violation $v): string => $v->getLine() === 12 ? 'stable' : 'drift-'.$v->getLine();

        $filtered = $baseline->filterResult(
            resultWith(violationAt(11), violationAt(12)),
            $hashes
        );

        expect($filtered->violations)->toHaveCount(1)
            ->and($filtered->violations[0]->getLine())->toBe(11);
    });

    it('matches the reference consumption algorithm across mixed entries', function (): void {
        for ($seed = 0; $seed < 50; $seed++) {
            $tolerance = $seed % 5;
            $baseline = new Baseline('consumption.baseline-test.json', $tolerance);

            for ($i = 0; $i < 24; $i++) {
                $baseline->addViolation(
                    violationAt(
                        1 + (($i * 17 + $seed * 3) % 80),
                        'rule-'.(($i + $seed) % 4),
                        'message-'.(($i * 3 + $seed) % 6),
                    ),
                    'hash-'.(($i * 7 + $seed) % 11),
                );
            }

            $violations = [];
            $hashes = new SplObjectStorage;

            for ($i = 0; $i < 27; $i++) {
                $violation = violationAt(
                    1 + (($i * 13 + $seed * 5) % 80),
                    'rule-'.(($i * 3 + $seed) % 4),
                    'message-'.(($i * 5 + $seed) % 6),
                );
                $violations[] = $violation;
                $hashes[$violation] = 'hash-'.(($i * 2 + $seed) % 11);
            }

            $hashGenerator = fn (Violation $violation): string => $hashes[$violation];
            $entries = $baseline->getViolationsForFile('resources/views/page.blade.php');
            $result = new LintResult('resources/views/page.blade.php', $violations);

            expect($baseline->filterResult($result, $hashGenerator)->violations)
                ->toBe(referenceFilter($entries, $violations, $hashGenerator, $tolerance));

            $currentEntries = array_map(
                fn (Violation $violation): array => [
                    'ruleId' => $violation->ruleId,
                    'line' => $violation->getLine(),
                    'message' => $violation->message,
                    'hash' => $hashGenerator($violation),
                ],
                $violations,
            );

            $baseline->pruneFixed([$result], $hashGenerator);

            expect($baseline->getViolationsForFile('resources/views/page.blade.php'))
                ->toBe(referencePrune($entries, $currentEntries, $tolerance));
        }
    });
});

describe('the content hash', function (): void {
    it('keeps the full digest rather than a prefix', function (): void {
        expect((new BaselineGenerator)->generateHash(violationAt(10)))->toHaveLength(32);
    });

    it('does not collide across many distinct violations', function (): void {
        $generator = new BaselineGenerator;
        $seen = [];

        foreach (range(1, 5000) as $line) {
            $seen[$generator->generateHash(violationAt($line, 'a11y-alt-text', "Variant {$line}"))] = true;
        }

        expect($seen)->toHaveCount(5000);
    });

    it('still matches a baseline written with the old hash width', function (): void {
        $baseline = new Baseline('legacy.baseline-test.json');
        $baseline->addViolation(violationAt(10), 'deadbeef');

        $filtered = $baseline->filterResult(
            resultWith(violationAt(10)),
            (new BaselineGenerator)->createHashGenerator()
        );

        expect($filtered->violations)->toBe([]);
    });

    it('still reports a new violation against an old-format baseline', function (): void {
        $baseline = new Baseline('legacy.baseline-test.json');
        $baseline->addViolation(violationAt(10), 'deadbeef');

        $filtered = $baseline->filterResult(
            resultWith(violationAt(10), violationAt(11)),
            (new BaselineGenerator)->createHashGenerator()
        );

        expect($filtered->violations)->toHaveCount(1)
            ->and($filtered->violations[0]->getLine())->toBe(11);
    });
});

describe('updating a baseline', function (): void {
    it('grows to cover newly added violations', function (): void {
        $baseline = new Baseline('consumption.baseline-test.json');
        $baseline->addViolation(violationAt(10), 'drift-10');

        $baseline->recordUnbaselined(
            resultWith(violationAt(10), violationAt(11), violationAt(12)),
            driftedHashes()
        );

        expect($baseline->getTotalCount())->toBe(3);
    });

    it('hashes each finding once while recording new entries', function (): void {
        $baseline = new Baseline('consumption.baseline-test.json');
        $calls = 0;

        $baseline->recordUnbaselined(
            resultWith(violationAt(10), violationAt(11)),
            function (Violation $violation) use (&$calls): string {
                $calls++;

                return 'hash-'.$violation->getLine();
            }
        );

        expect($baseline->getTotalCount())->toBe(2)
            ->and($calls)->toBe(2);
    });

    it('shrinks when violations are fixed', function (): void {
        $baseline = new Baseline('consumption.baseline-test.json');
        $baseline->addViolation(violationAt(10), 'drift-10');
        $baseline->addViolation(violationAt(11), 'drift-11');
        $baseline->addViolation(violationAt(12), 'drift-12');

        $baseline->pruneFixed([resultWith(violationAt(10))], driftedHashes());

        expect($baseline->getTotalCount())->toBe(1);
    });

    it('leaves entries for files that were not linted', function (): void {
        $baseline = new Baseline('consumption.baseline-test.json');
        $baseline->addViolation(violationAt(10), 'drift-10');

        $baseline->pruneFixed([], driftedHashes());

        expect($baseline->getTotalCount())->toBe(1);
    });

    it('ends with one entry per current finding', function (): void {
        $baseline = new Baseline('consumption.baseline-test.json');
        $baseline->addViolation(violationAt(10), 'drift-10');
        $baseline->addViolation(violationAt(50), 'drift-50');

        $current = [resultWith(violationAt(10), violationAt(11), violationAt(12))];

        (new BaselineGenerator)->update($baseline, $current);

        expect($baseline->getTotalCount())->toBe(3)
            ->and($baseline->getCountsByRule())->toBe(['a11y-alt-text' => 3]);
    });

    it('reports nothing once the baseline has been updated', function (): void {
        $baseline = new Baseline('consumption.baseline-test.json');
        $baseline->addViolation(violationAt(10), 'drift-10');

        $current = [resultWith(violationAt(10), violationAt(11), violationAt(12))];
        (new BaselineGenerator)->update($baseline, $current);

        $filtered = $baseline->filterResults($current, (new BaselineGenerator)->createHashGenerator());

        expect($filtered[0]->violations)->toBe([]);
    });
});
