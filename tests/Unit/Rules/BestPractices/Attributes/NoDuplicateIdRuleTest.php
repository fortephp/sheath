<?php

declare(strict_types=1);

use Forte\Sheath\Rules\BestPractices\Attributes\NoDuplicateIdRule;

describe('NoDuplicateIdRule', function (): void {
    it('passes for elements with unique ids', function (): void {
        $this->getRuleTester()->run(new NoDuplicateIdRule, [
            'valid' => [
                '<div id="one"></div><div id="two"></div>',
                '<div id="container"><span id="item"></span></div>',
                '<header id="header"></header><main id="main"></main><footer id="footer"></footer>',
            ],
        ]);
    });

    it('passes for elements without ids', function (): void {
        $this->getRuleTester()->run(new NoDuplicateIdRule, [
            'valid' => [
                '<div><span><p>Content</p></span></div>',
                '<div class="container"></div>',
            ],
        ]);
    });

    it('fails for duplicate ids', function (): void {
        $this->getRuleTester()->run(new NoDuplicateIdRule, [
            'invalid' => [
                [
                    'code' => '<div id="main"></div><div id="main"></div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<span id="item"></span><p id="item"></p>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('detects multiple different duplicate ids', function (): void {
        $this->getRuleTester()->run(new NoDuplicateIdRule, [
            'invalid' => [
                [
                    'code' => '<div id="a"></div><div id="a"></div><span id="b"></span><span id="b"></span>',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('detects triple or more duplicates', function (): void {
        $this->getRuleTester()->run(new NoDuplicateIdRule, [
            'invalid' => [
                [
                    'code' => '<div id="x"></div><div id="x"></div><div id="x"></div>',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('works with nested elements', function (): void {
        $this->getRuleTester()->run(new NoDuplicateIdRule, [
            'valid' => [
                '<div id="parent"><div id="child"></div></div>',
            ],
            'invalid' => [
                [
                    'code' => '<div id="same"><div id="same"></div></div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('works with blade syntax', function (): void {
        $this->getRuleTester()->run(new NoDuplicateIdRule, [
            'valid' => [
                '<div id="{{ $id1 }}"></div><div id="{{ $id2 }}"></div>',
            ],
            'invalid' => [
                [
                    'code' => '<div id="static"></div><div id="static"></div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('compares id values as exact strings', function (): void {
        $this->getRuleTester()->run(new NoDuplicateIdRule, [
            'valid' => [
                '<div id=""></div><div id=""></div>',
                '<div id="x"></div><div id=" x "></div>',
            ],
            'invalid' => [[
                'code' => '<div id=" "></div><div id=" "></div>',
                'errors' => 1,
            ]],
        ]);
    });

    it('ignores later duplicate id attributes when comparing element identities', function (): void {
        $this->getRuleTester()->run(new NoDuplicateIdRule, [
            'valid' => [
                '<div id="a" @if($x) id="b" @endif></div><span id="b"></span>',
            ],
        ]);
    });

    it('does not compare captured ids with the live document at the definition site', function (): void {
        $this->getRuleTester()->run(new NoDuplicateIdRule, [
            'valid' => [
                '@push("x")<div id="same"></div>@endpush<span id="same"></span>',
                '@pushIf($enabled, "x")<div id="same"></div>@endPushIf<span id="same"></span>',
            ],
        ]);
    });

    it('scopes duplicate ids to each HTML tree', function (): void {
        $this->getRuleTester()->run(new NoDuplicateIdRule, [
            'valid' => [
                '<template><div id="same"></div></template><span id="same"></span>',
                '<template><div id="same"></div></template><template><span id="same"></span></template>',
                '@foreach($items as $item)<template><div id="same"></div></template>@endforeach',
            ],
            'invalid' => [[
                'code' => '<template><div id="same"></div><span id="same"></span></template>',
                'errors' => 1,
            ]],
        ]);
    });

    it('reports static ids that a Blade loop may repeat', function (string $code): void {
        $this->getRuleTester()->run(new NoDuplicateIdRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'foreach' => '@foreach($items as $item)<div id="item"></div>@endforeach',
        'forelse body' => '@forelse($items as $item)<div id="item"></div>@empty<p>Empty</p>@endforelse',
        'for' => '@for($i = 0; $i < 2; $i++)<div id="item"></div>@endfor',
        'while' => '@while($item)<div id="item"></div>@endwhile',
        'nested loop' => '@foreach($groups as $group)@foreach($group as $item)<div id="item"></div>@endforeach @endforeach',
    ]);

    it('does not report dynamic ids or the non-repeating forelse empty arm', function (): void {
        $this->getRuleTester()->run(new NoDuplicateIdRule, [
            'valid' => [
                '@foreach($items as $item)<div id="item-{{ $item->id }}"></div>@endforeach',
                '@forelse($items as $item)<div></div>@empty<div id="empty"></div>@endforelse',
            ],
        ]);
    });

    it('reports static ids cloned by Alpine x-for while accepting reactive ids', function (): void {
        $this->getRuleTester()->run(new NoDuplicateIdRule, [
            'valid' => [
                '<template x-for="item in items"><div :id="`item-${item.id}`"></div></template>',
                '<template x-for="item in items"><div x-bind:id="item.id"></div></template>',
                '<template x-if="open"><div id="modal"></div></template>',
            ],
            'invalid' => [[
                'code' => '<template x-for="item in items"><div id="item"></div></template>',
                'errors' => 1,
            ], [
                'code' => '<div id="modal"></div><template x-if="open"><div id="modal"></div></template>',
                'errors' => 1,
            ], [
                'code' => '<div id="modal"></div><template x-teleport="body"><div id="modal"></div></template>',
                'errors' => 1,
            ]],
        ]);
    });

    it('does not report the same looped element twice when it also conflicts syntactically', function (): void {
        $this->getRuleTester()->run(new NoDuplicateIdRule, [
            'invalid' => [[
                'code' => '@foreach($items as $item)<div id="item"></div><span id="item"></span>@endforeach',
                'errors' => 2,
            ]],
        ]);
    });

    describe('conditional branches', function (): void {
        it('passes for the same id on mutually exclusive branches', function (string $code): void {
            $this->getRuleTester()->run(new NoDuplicateIdRule, ['valid' => [$code]]);
        })->with([
            'if/else arms' => '@if($x)<div id="modal">A</div>@else<div id="modal">B</div>@endif',
            'if/elseif/else arms' => '@if($x)<div id="modal">A</div>@elseif($y)<div id="modal">B</div>@else<div id="modal">C</div>@endif',
            'switch cases' => '@switch($x)@case(1)<div id="modal">A</div>@break @case(2)<div id="modal">B</div>@break @default<div id="modal">C</div>@endswitch',
            'switch branches separated by an intervening break' => '@switch($x)@case(1)<div id="modal">A</div>@case(2)shared @break @default<div id="modal">B</div>@endswitch',
            'nested markup inside arms' => '@if($x)<section><div id="modal">A</div></section>@else<aside><div id="modal">B</div></aside>@endif',
        ]);

        it('fails for duplicate ids within one branch', function (): void {
            $this->getRuleTester()->run(new NoDuplicateIdRule, [
                'invalid' => [
                    [
                        'code' => '@if($x)<div id="modal">A</div><div id="modal">B</div>@endif',
                        'errors' => 1,
                    ],
                ],
            ]);
        });

        it('fails when duplicate ids can render through switch fallthrough', function (): void {
            $this->getRuleTester()->run(new NoDuplicateIdRule, [
                'invalid' => [[
                    'code' => '@switch($x)@case(1)<div id="modal">A</div>@case(2)<div id="modal">B</div>@break @endswitch',
                    'errors' => 1,
                ]],
            ]);
        });

        it('does not treat a conditional break as guaranteed', function (): void {
            $this->getRuleTester()->run(new NoDuplicateIdRule, [
                'invalid' => [[
                    'code' => '@switch($x)@case(1)<div id="modal">A</div>@break($stop) @case(2)<div id="modal">B</div>@endswitch',
                    'errors' => 1,
                ]],
            ]);
        });

        it('fails when a branch id repeats an unconditional one', function (): void {
            $this->getRuleTester()->run(new NoDuplicateIdRule, [
                'invalid' => [
                    [
                        'code' => '<div id="modal">Always</div>@if($x)<div id="modal">Sometimes</div>@endif',
                        'errors' => 1,
                    ],
                ],
            ]);
        });
    });

    it('handles large documents with unique IDs', function (): void {
        $source = '<main>'.implode('', array_map(
            static fn (int $index): string => "<section><div id=\"item-{$index}\"></div><span></span></section>",
            range(1, 800),
        )).'</main>';

        $this->getRuleTester()->run(new NoDuplicateIdRule, ['valid' => [$source]]);
    });
});
