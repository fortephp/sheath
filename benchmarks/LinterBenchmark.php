<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Forte\Ast\Document\Document;
use Forte\Sheath\Configuration\DefaultConfigFactory;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\RuleRegistry;

$registry = new RuleRegistry;
$registry->discoverRules(dirname(__DIR__).'/src/Rules');
$config = DefaultConfigFactory::resolve([], $registry);
$linter = new Linter($registry);

$unit = <<<'BLADE'
<article>
    <h2>Title</h2>
    <a href="/articles/1">Read more</a>
    <img src="cover.jpg" alt="Article cover">
    <form method="post">@csrf<button type="submit">Save</button></form>
</article>
BLADE;

printf("End-to-end recommended-preset benchmark (median of %d samples)\n", BENCHMARK_SAMPLES);
printf("%8s %10s %12s %12s\n", 'units', 'bytes', 'parse (ms)', 'lint (ms)');

foreach ([25, 100, 400] as $units) {
    $source = '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        .'<meta name="viewport" content="width=device-width"><title>Benchmark</title></head><body>'
        .str_repeat($unit, $units)
        .'</body></html>';

    $parse = benchmarkMedianMilliseconds(
        static fn (): callable => static fn (): Document => Document::parse($source)
    );
    $lint = benchmarkMedianMilliseconds(
        static fn (): callable => static fn () => $linter->lint($source, 'benchmark.blade.php', $config)
    );

    printf("%8d %10d %12.3f %12.3f\n", $units, strlen($source), $parse, $lint);
}
