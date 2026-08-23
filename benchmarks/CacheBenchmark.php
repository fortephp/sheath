<?php

declare(strict_types=1);

use Forte\Sheath\Caching\ResultCache;
use Forte\Sheath\Results\LintResult;

require __DIR__.'/bootstrap.php';

const CACHE_WRITES_PER_SAMPLE = 400;

printf("Result cache write benchmark (%d writes, median of %d samples)\n", CACHE_WRITES_PER_SAMPLE, BENCHMARK_SAMPLES);
printf("%10s %13s %13s %13s\n", 'source', 'inferred (ms)', 'known (ms)', 'embedded (ms)');

foreach ([16, 128, 512] as $kilobytes) {
    $source = str_repeat('x', $kilobytes * 1024);
    $file = tempnam(sys_get_temp_dir(), 'sheath-cache-benchmark-');

    if ($file === false) {
        throw new RuntimeException('Could not create the benchmark file.');
    }

    $cacheFile = $file.'.cache';
    file_put_contents($file, $source);
    $result = new LintResult($file, []);

    try {
        $inferred = benchmarkMedianMilliseconds(static function () use ($cacheFile, $file, $result): callable {
            $cache = (new ResultCache($cacheFile))->enable();

            return static function () use ($cache, $file, $result): void {
                for ($write = 0; $write < CACHE_WRITES_PER_SAMPLE; $write++) {
                    $cache->put($file, $result);
                }
            };
        });

        $known = benchmarkMedianMilliseconds(static function () use ($cacheFile, $file, $result, $source): callable {
            $cache = (new ResultCache($cacheFile))->enable();

            return static function () use ($cache, $file, $result, $source): void {
                for ($write = 0; $write < CACHE_WRITES_PER_SAMPLE; $write++) {
                    $cache->put($file, $result, $source);
                }
            };
        });

        $hashedResult = new LintResult($file, [], sourceHash: hash('xxh128', $source));
        $embedded = benchmarkMedianMilliseconds(static function () use ($cacheFile, $file, $hashedResult): callable {
            $cache = (new ResultCache($cacheFile))->enable();

            return static function () use ($cache, $file, $hashedResult): void {
                for ($write = 0; $write < CACHE_WRITES_PER_SAMPLE; $write++) {
                    $cache->put($file, $hashedResult);
                }
            };
        });

        printf("%7d KiB %13.3f %13.3f %13.3f\n", $kilobytes, $inferred, $known, $embedded);
    } finally {
        @unlink($file);
        @unlink($cacheFile);
    }
}
