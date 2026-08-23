<?php

declare(strict_types=1);

use Forte\Sheath\Baselines\Baseline;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;

require __DIR__.'/bootstrap.php';

/** @return array{Baseline, LintResult, callable(Violation): string} */
function baselineScenario(int $size): array
{
    $baseline = new Baseline('benchmark.json');
    $current = [];
    $hashes = new SplObjectStorage;

    for ($i = 1; $i <= $size; $i++) {
        $recorded = benchmarkViolation($i, "recorded-{$i}");
        $baseline->addViolation($recorded, "recorded-hash-{$i}");

        $violation = benchmarkViolation($i, "current-{$i}");
        $current[] = $violation;
        $hashes[$violation] = "current-hash-{$i}";
    }

    return [
        $baseline,
        new LintResult('resources/views/benchmark.blade.php', $current),
        static fn (Violation $violation): string => $hashes[$violation],
    ];
}

function benchmarkViolation(int $line, string $message): Violation
{
    return new Violation(
        ruleId: 'benchmark-rule',
        message: $message,
        severity: Severity::WARNING,
        filePath: 'resources/views/benchmark.blade.php',
        start: new Position($line, $line, 1),
        end: new Position($line, $line, 2),
    );
}

printf("Baseline matching benchmark (median of %d samples)\n", BENCHMARK_SAMPLES);
printf("%8s %12s %12s\n", 'entries', 'filter (ms)', 'prune (ms)');

foreach ([100, 500, 1_000, 2_000] as $size) {
    $filter = benchmarkMedianMilliseconds(static function () use ($size): callable {
        [$baseline, $result, $hashGenerator] = baselineScenario($size);

        return static fn () => $baseline->filterResult($result, $hashGenerator);
    });

    $prune = benchmarkMedianMilliseconds(static function () use ($size): callable {
        [$baseline, $result, $hashGenerator] = baselineScenario($size);

        return static fn () => $baseline->pruneFixed([$result], $hashGenerator);
    });

    printf("%8d %12.3f %12.3f\n", $size, $filter, $prune);
}
