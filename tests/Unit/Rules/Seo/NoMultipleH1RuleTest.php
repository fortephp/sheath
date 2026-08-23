<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Seo\NoMultipleH1Rule;

describe('NoMultipleH1Rule', function (): void {
    it('passes for documents with single h1', function (): void {
        $this->getRuleTester()->run(new NoMultipleH1Rule, [
            'valid' => [
                '<h1>Main Title</h1>',
                '<h1>Title</h1><h2>Subtitle</h2><h3>Section</h3>',
                '<header><h1>Page Title</h1></header><main><h2>Content</h2></main>',
            ],
        ]);
    });

    it('passes for documents with no h1', function (): void {
        $this->getRuleTester()->run(new NoMultipleH1Rule, [
            'valid' => [
                '<h2>Subtitle</h2><h3>Section</h3>',
                '<div><p>Content without headings</p></div>',
            ],
        ]);
    });

    it('fails for documents with multiple h1 elements', function (): void {
        $this->getRuleTester()->run(new NoMultipleH1Rule, [
            'invalid' => [
                [
                    'code' => '<h1>First</h1><h1>Second</h1>',
                    'errors' => 1,
                ],
                [
                    'code' => '<h1>One</h1><div><h1>Two</h1></div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('detects three or more h1 elements', function (): void {
        $this->getRuleTester()->run(new NoMultipleH1Rule, [
            'invalid' => [
                [
                    'code' => '<h1>One</h1><h1>Two</h1><h1>Three</h1>',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('works with blade syntax', function (): void {
        $this->getRuleTester()->run(new NoMultipleH1Rule, [
            'valid' => [
                '<h1>{{ $title }}</h1>',
            ],
            'invalid' => [
                [
                    'code' => '<h1>{{ $title1 }}</h1><h1>{{ $title2 }}</h1>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('reports an h1 that a Blade loop may repeat', function (string $code): void {
        $this->getRuleTester()->run(new NoMultipleH1Rule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'foreach' => '@foreach($items as $item)<h1>{{ $item->name }}</h1>@endforeach',
        'forelse body' => '@forelse($items as $item)<h1>Item</h1>@empty<p>Empty</p>@endforelse',
        'for' => '@for($i = 0; $i < 2; $i++)<h1>Item</h1>@endfor',
        'while' => '@while($item)<h1>Item</h1>@endwhile',
    ]);

    it('does not treat the forelse empty arm as repeating', function (): void {
        $this->getRuleTester()->run(new NoMultipleH1Rule, [
            'valid' => [
                '@forelse($items as $item)<p>Item</p>@empty<h1>No items</h1>@endforelse',
            ],
        ]);
    });

    it('does not count headings inside inert templates', function (): void {
        $this->getRuleTester()->run(new NoMultipleH1Rule, [
            'valid' => [
                '<h1>Rendered title</h1><template><h1>Cloned later</h1></template>',
                '<template><h1>First template</h1><h1>Second template</h1></template>',
            ],
        ]);
    });

    it('counts headings rendered by Alpine templates', function (): void {
        $this->getRuleTester()->run(new NoMultipleH1Rule, [
            'valid' => [
                '<template x-if="open"><h1>Conditional title</h1></template>',
            ],
            'invalid' => [[
                'code' => '<h1>Title</h1><template x-if="open"><h1>Modal title</h1></template>',
                'errors' => 1,
            ], [
                'code' => '<h1>Title</h1><template x-teleport="body"><h1>Modal title</h1></template>',
                'errors' => 1,
            ], [
                'code' => '<template x-for="item in items"><h1 x-text="item.title"></h1></template>',
                'errors' => 1,
            ]],
        ]);
    });

    it('does not count captured headings at their definition site', function (): void {
        $this->getRuleTester()->run(new NoMultipleH1Rule, [
            'valid' => [
                '@push("x")<h1>Captured</h1>@endpush<h1>Live</h1>',
                '@pushIf($enabled, "x")<h1>Captured</h1>@endPushIf<h1>Live</h1>',
            ],
        ]);
    });

    describe('conditional branches', function (): void {
        it('passes for one h1 per mutually exclusive branch', function (string $code): void {
            $this->getRuleTester()->run(new NoMultipleH1Rule, ['valid' => [$code]]);
        })->with([
            'if/else arms' => '@if($x)<h1>A</h1>@else<h1>B</h1>@endif',
            'if/elseif/else arms' => '@if($x)<h1>A</h1>@elseif($y)<h1>B</h1>@else<h1>C</h1>@endif',
            'switch cases' => '@switch($x)@case(1)<h1>A</h1>@break @default<h1>B</h1>@endswitch',
        ]);

        it('fails for two h1 within one branch', function (): void {
            $this->getRuleTester()->run(new NoMultipleH1Rule, [
                'invalid' => [
                    [
                        'code' => '@if($x)<h1>A</h1><h1>B</h1>@endif',
                        'errors' => 1,
                    ],
                ],
            ]);
        });

        it('fails when switch cases can render together through fallthrough', function (): void {
            $this->getRuleTester()->run(new NoMultipleH1Rule, [
                'invalid' => [[
                    'code' => '@switch($x)@case(1)<h1>A</h1>@case(2)<h1>B</h1>@break @endswitch',
                    'errors' => 1,
                ]],
            ]);
        });

        it('fails when a branch h1 joins an unconditional one', function (): void {
            $this->getRuleTester()->run(new NoMultipleH1Rule, [
                'invalid' => [
                    [
                        'code' => '<h1>Always</h1>@if($x)<h1>Sometimes</h1>@endif',
                        'errors' => 1,
                    ],
                ],
            ]);
        });

        it('handles large mutually exclusive switches', function (): void {
            $cases = '';

            for ($case = 0; $case < 400; $case++) {
                $cases .= "@case({$case})<h1>Case {$case}</h1>@break\n";
            }

            $this->getRuleTester()->run(new NoMultipleH1Rule, [
                'valid' => ["@switch(\$value)\n{$cases}@endswitch"],
            ]);
        });

        it('handles a large elseif chain', function (): void {
            $branches = '@if($layout === 0)<h1>0</h1>';
            for ($branch = 1; $branch < 800; $branch++) {
                $branches .= "@elseif(\$layout === {$branch})<h1>{$branch}</h1>";
            }
            $branches .= '@endif';

            $this->getRuleTester()->run(new NoMultipleH1Rule, ['valid' => [$branches]]);
        });
    });
});
