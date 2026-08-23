<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Blade\Directives\SwitchStructureRule;
use Forte\Sheath\Rules\RuleRegistry;

describe('SwitchStructureRule', function (): void {
    it('passes for a well-formed switch', function (): void {
        $this->getRuleTester()->run(new SwitchStructureRule, [
            'valid' => [
                "@switch(\$type)\n    @case(1)\n        one\n        @break\n    @case(2)\n        two\n        @break\n    @default\n        other\n@endswitch",
                '@switch($type)@case(1)one@break@endswitch',
            ],
        ]);
    });

    it('passes when only whitespace separates @switch from the first @case', function (): void {
        $this->getRuleTester()->run(new SwitchStructureRule, [
            'valid' => [
                "@switch(\$type)\n\n\t   \n@case(1)\none\n@endswitch",
            ],
        ]);
    });

    it('passes for a Blade comment between @switch and @case', function (): void {
        $this->getRuleTester()->run(new SwitchStructureRule, [
            'valid' => [
                "@switch(\$type)\n{{-- pick a branch --}}\n@case(1)\none\n@endswitch",
            ],
        ]);
    });

    it('passes for switches nested in other constructs', function (): void {
        $this->getRuleTester()->run(new SwitchStructureRule, [
            'valid' => [
                "@if(\$show)\n@switch(\$type)\n@case(1)\nx\n@break\n@endswitch\n@endif",
                "<div>\n@switch(\$type)\n@case(1)\nx\n@endswitch\n</div>",
            ],
        ]);
    });

    it('never flags case markers inside @verbatim', function (): void {
        $this->getRuleTester()->run(new SwitchStructureRule, [
            'valid' => [
                "@verbatim\n@case(1)\n@default\n@endverbatim",
            ],
        ]);
    });

    it('never flags escaped @@case or @@default', function (): void {
        $this->getRuleTester()->run(new SwitchStructureRule, [
            'valid' => [
                "<p>Use @@case(1) to open a branch.</p>\n<p>Use @@default for the fallback.</p>",
            ],
        ]);
    });

    it('fails for markup between @switch and the first @case', function (): void {
        $this->getRuleTester()->run(new SwitchStructureRule, [
            'invalid' => [
                [
                    'code' => "@switch(\$type)\n<div>leading</div>\n@case(1)\none\n@break\n@endswitch",
                    'errors' => [
                        [
                            'line' => 2,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('fails for an echo between @switch and the first @case', function (): void {
        $this->getRuleTester()->run(new SwitchStructureRule, [
            'invalid' => [
                [
                    'code' => "@switch(\$type)\n{{ \$x }}\n@case(1)\none\n@endswitch",
                    'errors' => [
                        ['line' => 2],
                    ],
                ],
            ],
        ]);
    });

    it('fails for text between @switch and the first @case', function (): void {
        $this->getRuleTester()->run(new SwitchStructureRule, [
            'invalid' => [
                [
                    'code' => "@switch(\$type)\nhello\n@case(1)\none\n@endswitch",
                    'errors' => [
                        ['line' => 2],
                    ],
                ],
            ],
        ]);
    });

    it('fails when @default is the first branch', function (): void {
        $this->getRuleTester()->run(new SwitchStructureRule, [
            'invalid' => [
                [
                    'code' => "@switch(\$type)\n@default\nother\n@endswitch",
                    'errors' => [
                        [
                            'line' => 2,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('fails for a switch with no @case at all', function (): void {
        $this->getRuleTester()->run(new SwitchStructureRule, [
            'invalid' => [
                [
                    'code' => "@switch(\$type)\n@endswitch",
                    'errors' => [
                        [
                            'line' => 1,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('reports only the first offending node per switch', function (): void {
        $this->getRuleTester()->run(new SwitchStructureRule, [
            'invalid' => [
                [
                    'code' => "@switch(\$type)\n<p>a</p>\n<p>b</p>\n@case(1)\none\n@endswitch",
                    'errors' => [
                        ['line' => 2],
                    ],
                ],
            ],
        ]);
    });

    it('fails for @case outside any switch', function (): void {
        $this->getRuleTester()->run(new SwitchStructureRule, [
            'invalid' => [
                [
                    'code' => "@case(1)\ntext",
                    'errors' => [
                        [
                            'line' => 1,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('fails for @default outside any switch', function (): void {
        $this->getRuleTester()->run(new SwitchStructureRule, [
            'invalid' => [
                [
                    'code' => "<div>x</div>\n@default\ny",
                    'errors' => [
                        [
                            'line' => 2,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('fails for @case inside a non-switch block', function (): void {
        $this->getRuleTester()->run(new SwitchStructureRule, [
            'invalid' => [
                [
                    'code' => "@foreach(\$items as \$item)\n@case(1)\n@endforeach",
                    'errors' => [
                        ['line' => 2],
                    ],
                ],
            ],
        ]);
    });

    it('checks each switch independently', function (): void {
        $this->getRuleTester()->run(new SwitchStructureRule, [
            'invalid' => [
                [
                    'code' => "@switch(\$a)\n@case(1)\nx\n@endswitch\n@switch(\$b)\n<p>bad</p>\n@case(2)\ny\n@endswitch",
                    'errors' => [
                        ['line' => 6],
                    ],
                ],
            ],
        ]);
    });

    it('keeps long unicode excerpts valid utf-8', function (): void {
        $rule = new SwitchStructureRule;
        $registry = new RuleRegistry;
        $registry->register($rule);
        $result = (new Linter($registry))->lint(
            "@switch(\$value)\n".str_repeat('é', 24)."\n@case(1)x@endswitch",
            'test.blade.php',
            Config::make()->setRule($rule->getId(), 'error'),
        );

        expect($result->violations)->toHaveCount(1)
            ->and(mb_check_encoding($result->violations[0]->message, 'UTF-8'))->toBeTrue()
            ->and($result->violations[0]->message)->not->toContain("\u{FFFD}");
    });
});
