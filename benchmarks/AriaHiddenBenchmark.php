<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Accessibility\Aria\NoAriaHiddenOnFocusableRule;
use Forte\Sheath\Rules\RuleRegistry;

$rule = new NoAriaHiddenOnFocusableRule;
$registry = new RuleRegistry;
$registry->register($rule);
$config = Config::make();
$config->setRule($rule->getId(), $rule->getDefaultSeverity()->value);
$linter = new Linter($registry);

printf("Nested aria-hidden benchmark (median of %d samples)\n", BENCHMARK_SAMPLES);
printf("%8s %12s %12s\n", 'depth', 'plain (ms)', 'hidden (ms)');

foreach ([100, 250, 500] as $depth) {
    $plainSource = str_repeat('<div>', $depth).str_repeat('</div>', $depth);
    $hiddenSource = str_repeat('<div aria-hidden="true">', $depth).str_repeat('</div>', $depth);

    $plain = benchmarkMedianMilliseconds(
        static fn (): callable => static fn () => $linter->lint($plainSource, 'plain.blade.php', $config)
    );
    $hidden = benchmarkMedianMilliseconds(
        static fn (): callable => static fn () => $linter->lint($hiddenSource, 'hidden.blade.php', $config)
    );

    printf("%8d %12.3f %12.3f\n", $depth, $plain, $hidden);
}
