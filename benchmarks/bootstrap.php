<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

const BENCHMARK_SAMPLES = 5;

/** @param callable(): callable(): void $prepare */
function benchmarkMedianMilliseconds(callable $prepare): float
{
    $samples = [];

    for ($sample = 0; $sample < BENCHMARK_SAMPLES; $sample++) {
        $operation = $prepare();
        $started = hrtime(true);
        $operation();
        $samples[] = (hrtime(true) - $started) / 1_000_000;
    }

    sort($samples);

    return $samples[intdiv(count($samples), 2)];
}
