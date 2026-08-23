<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Php\NoLogicInViewsRule;

describe('NoLogicInViewsRule', function (): void {
    it('passes for simple @php blocks with variable assignments', function (): void {
        $this->getRuleTester()->run(new NoLogicInViewsRule, [
            'valid' => [
                '@php $active = true; @endphp',
                '@php $name = "John"; @endphp',
                '@php $total = $price; @endphp',
            ],
        ]);
    });

    it('passes for simple conditions', function (): void {
        $this->getRuleTester()->run(new NoLogicInViewsRule, [
            'valid' => [
                '@if($isAdmin) Admin @endif',
                '@if($user->isActive()) Active @endif',
                '@if($count > 0 && $isEnabled) Has items @endif',
            ],
        ]);
    });

    it('does not count logical-operator text inside condition string literals', function (string $code): void {
        $this->getRuleTester()->run(new NoLogicInViewsRule, ['valid' => [$code]]);
    })->with([
        'symbol operators' => '@if($label === "a && b || c" && $enabled && $visible) yes @endif',
        'word operators' => '@if($label === "a and b or c" and $enabled and $visible) yes @endif',
    ]);

    it('passes for @php blocks with literal class map assignments', function (): void {
        $classMap = <<<'BLADE'
@php
    $variants = [
        'normal' => 'bg-slate-950/5 text-slate-950 hover:bg-slate-950/10',
        'success' => 'bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/20',
        'danger' => 'bg-red-500/10 text-red-600 hover:bg-red-500/20',
    ];
    $sizes = [
        'sm' => 'px-2 py-0.5 text-xs -tracking-tight',
        'lg' => 'px-4 py-1.5 text-sm -mt-px',
    ];
    $ring = 'ring-1 ring-slate-950/10 dark:ring-white/10';
@endphp
BLADE;

        $this->getRuleTester()->run(new NoLogicInViewsRule, [
            'valid' => [$classMap],
        ]);
    });

    it('passes for @php blocks with logic keywords inside strings and comments', function (): void {
        $this->getRuleTester()->run(new NoLogicInViewsRule, [
            'valid' => [
                '@php $label = \'Search for articles\'; @endphp',
                "@php\n// Build flat page list for prev/next navigation\n\$page = \$pages->first();\n@endphp",
                "@php\n/* switch to the while layout */\n\$layout = 'wide';\n@endphp",
            ],
        ]);
    });

    it('passes for arithmetic characters inside interpolated strings', function (): void {
        $this->getRuleTester()->run(new NoLogicInViewsRule, [
            'valid' => [
                '@php $sum = "{$a} + {$b} - {$c} * {$d}"; @endphp',
            ],
        ]);
    });

    it('fails for @php blocks with foreach', function (): void {
        $this->getRuleTester()->run(new NoLogicInViewsRule, [
            'invalid' => [
                [
                    'code' => '@php foreach($items as $item) { $total += $item; } @endphp',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for @php blocks with for loops', function (): void {
        $this->getRuleTester()->run(new NoLogicInViewsRule, [
            'invalid' => [
                [
                    'code' => '@php for($i = 0; $i < 10; $i++) { echo $i; } @endphp',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for @php blocks with while loops', function (): void {
        $this->getRuleTester()->run(new NoLogicInViewsRule, [
            'invalid' => [
                [
                    'code' => '@php while($count > 0) { $count--; } @endphp',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for @php blocks with function definitions', function (): void {
        $this->getRuleTester()->run(new NoLogicInViewsRule, [
            'invalid' => [
                [
                    'code' => '@php function helper() { return true; } @endphp',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for @php blocks with other complexity keywords', function (): void {
        $this->getRuleTester()->run(new NoLogicInViewsRule, [
            'invalid' => [
                [
                    'code' => '@php switch ($type) { default: break; } @endphp',
                    'errors' => 1,
                ],
                [
                    'code' => '@php do { $i++; } while ($i < 3); @endphp',
                    'errors' => 1,
                ],
                [
                    'code' => '@php class Helper {} @endphp',
                    'errors' => 1,
                ],
                [
                    'code' => '@php trait Formats {} @endphp',
                    'errors' => 1,
                ],
                [
                    'code' => '@php interface Renderable {} @endphp',
                    'errors' => 1,
                ],
                [
                    'code' => '@php try { risky(); } catch (Throwable $e) { report($e); } @endphp',
                    'errors' => 1,
                ],
                [
                    'code' => '@php throw new RuntimeException(\'nope\'); @endphp',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for @php blocks with real arithmetic', function (): void {
        $this->getRuleTester()->run(new NoLogicInViewsRule, [
            'invalid' => [
                [
                    'code' => '@php $total = $a * $b + $c / $d - $e; @endphp',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('reports the real keyword when strings also contain keyword text', function (): void {
        $mixed = <<<'BLADE'
@php
    $title = 'for your reference';
    while ($items->isNotEmpty()) { $items->pop(); }
@endphp
BLADE;

        $this->getRuleTester()->run(new NoLogicInViewsRule, [
            'invalid' => [
                [
                    'code' => $mixed,
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for conditions with too many operators', function (): void {
        $this->getRuleTester()->run(new NoLogicInViewsRule, [
            'invalid' => [
                [
                    'code' => '@if($a && $b && $c && $d) complex @endif',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for conditions using or keywords', function (): void {
        $this->getRuleTester()->run(new NoLogicInViewsRule, [
            'invalid' => [
                [
                    'code' => '@if($a or $b or $c or $d) complex @endif',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('checks intermediate elseif conditions', function (): void {
        $this->getRuleTester()->run(new NoLogicInViewsRule, [
            'invalid' => [[
                'code' => "@if(\$simple) one\n@elseif(\$a && \$b && \$c && \$d) two\n@endif",
                'errors' => [[

                    'line' => 2,
                ]],
            ]],
        ]);
    });

    it('respects maxOperators option', function (): void {
        $rule = new NoLogicInViewsRule;
        $rule->setOptions(['maxOperators' => 3]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '@if($a && $b && $c && $d) allowed with higher max @endif',
            ],
        ]);
    });

    it('can disable php block checking', function (): void {
        $rule = new NoLogicInViewsRule;
        $rule->setOptions(['checkPhpBlocks' => false]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '@php foreach($items as $item) { echo $item; } @endphp',
            ],
        ]);
    });

    it('can disable condition checking', function (): void {
        $rule = new NoLogicInViewsRule;
        $rule->setOptions(['checkConditions' => false]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '@if($a && $b && $c && $d) allowed @endif',
            ],
        ]);
    });

    it('does not mistake members named after keywords for the keywords', function (string $code): void {
        $this->getRuleTester()->run(new NoLogicInViewsRule, ['valid' => [$code]]);
    })->with([
        'attribute bag class method' => '@php $classes = $attributes->class(["btn"]); @endphp',
        'class constant in array key' => '@php $map = [Foo::class => "foo"]; @endphp',
        'builder for method' => '@php $q = $factory->for($user); @endphp',
        'variable named class' => '@php $class = "btn"; @endphp',
    ]);

    it('still flags real keyword statements in @php blocks', function (string $code): void {
        $this->getRuleTester()->run(new NoLogicInViewsRule, [
            'invalid' => [
                [
                    'code' => $code,
                    'errors' => 1,
                ],
            ],
        ]);
    })->with([
        'class declaration' => '@php class Foo { } @endphp',
        'foreach loop' => '@php foreach ($items as $item) { echo $item; } @endphp',
    ]);
});
