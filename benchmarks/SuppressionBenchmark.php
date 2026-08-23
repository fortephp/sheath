<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Sheath\Configuration\Suppressions;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;

require __DIR__.'/bootstrap.php';

/** @return array{Suppressions, list<Violation>} */
function suppressionScenario(int $size): array
{
    $lines = [];
    $violations = [];
    $line = 1;

    for ($index = 0; $index < $size; $index++) {
        $lines[] = '{{-- sheath-disable benchmark-rule --}}';
        $line++;
        $lines[] = '<div></div>';
        $violations[] = new Violation(
            ruleId: 'benchmark-rule',
            message: 'Benchmark finding',
            severity: Severity::WARNING,
            filePath: 'benchmark.blade.php',
            start: new Position($index, $line, 1),
            end: new Position($index + 1, $line, 2),
        );
        $line++;
        $lines[] = '{{-- sheath-enable benchmark-rule --}}';
        $line++;
    }

    return [
        Suppressions::fromDocument(Document::parse(implode("\n", $lines))),
        $violations,
    ];
}

printf("Inline suppression benchmark (median of %d samples)\n", BENCHMARK_SAMPLES);
printf("%8s %14s\n", 'pairs', 'filter (ms)');

foreach ([100, 500, 1_000, 2_000] as $size) {
    $filter = benchmarkMedianMilliseconds(static function () use ($size): callable {
        [$suppressions, $violations] = suppressionScenario($size);

        return static fn () => $suppressions->filter($violations);
    });

    printf("%8d %14.3f\n", $size, $filter);
}
