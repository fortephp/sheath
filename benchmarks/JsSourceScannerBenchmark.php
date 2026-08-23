<?php

declare(strict_types=1);

use Forte\Sheath\Parsing\JsSourceScanner;

require __DIR__.'/bootstrap.php';

/** @return array{string, list<array{int, int}>, list<int>} */
function scannerScenario(int $size): array
{
    $source = '';
    $spans = [];
    $positions = [];

    for ($index = 0; $index < $size; $index++) {
        $source .= "const value{$index} = ";
        $start = strlen($source);
        $source .= str_repeat('X', 12);
        $spans[] = [$start, strlen($source)];
        $positions[] = $start;
        $source .= ";\n";
    }

    return [$source, $spans, $positions];
}

printf("JavaScript position benchmark (median of %d samples)\n", BENCHMARK_SAMPLES);
printf("%8s %12s\n", 'positions', 'scan (ms)');

foreach ([100, 500, 1_000, 2_000] as $size) {
    $scan = benchmarkMedianMilliseconds(static function () use ($size): callable {
        [$source, $spans, $positions] = scannerScenario($size);

        return static fn () => JsSourceScanner::classifyPositions($source, 0, $positions, $spans);
    });

    printf("%8d %12.3f\n", $size, $scan);
}
