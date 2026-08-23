<?php

declare(strict_types=1);

use Forte\Sheath\Fixer;
use Forte\Sheath\Results\Fix;

require __DIR__.'/bootstrap.php';

/** @return array{string, list<Fix>} */
function fixerScenario(int $size): array
{
    $content = str_repeat('x ', $size + 1);
    $fixes = [];

    for ($index = 0; $index < $size; $index++) {
        $offset = $index * 2;
        $fixes[] = new Fix($offset, $offset + 1, 'y');
    }

    return [$content, $fixes];
}

printf("Fix application benchmark (median of %d samples)\n", BENCHMARK_SAMPLES);
printf("%8s %13s\n", 'fixes', 'apply (ms)');

foreach ([100, 500, 1_000, 2_000, 4_000] as $size) {
    $apply = benchmarkMedianMilliseconds(static function () use ($size): callable {
        [$content, $fixes] = fixerScenario($size);

        return static fn () => (new Fixer)->applyFixes($content, $fixes);
    });

    printf("%8d %13.3f\n", $size, $apply);
}
