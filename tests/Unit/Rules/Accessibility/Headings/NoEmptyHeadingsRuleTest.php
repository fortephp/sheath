<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Headings\NoEmptyHeadingsRule;

describe('NoEmptyHeadingsRule', function (): void {
    it('handles conditional presentation-role conflicts without crashing', function (): void {
        $this->getRuleTester()->run(new NoEmptyHeadingsRule, [
            'invalid' => [
                [
                    'code' => '<h2 role="none" @if($x) aria-label="X" @endif></h2>',
                    'errors' => 1,
                ],
                [
                    'code' => '<div hidden>@push("x")<h2></h2>@endpush</div>',
                    'errors' => 1,
                ],
            ],
            'valid' => [
                '<div hidden>@if($x)<h2></h2>@endif</div>',
            ],
        ]);
    });

    it('ignores headings with an unconflicted presentational role on every conditional path', function (): void {
        $this->getRuleTester()->run(new NoEmptyHeadingsRule, [
            'valid' => [
                '<h2 @if($x) role="none" @else role="presentation" @endif></h2>',
                '<h2 @if($x) hidden @else inert @endif></h2>',
            ],
        ]);
    });
    it('accepts headings populated by Alpine or Livewire on every render path', function (): void {
        $this->getRuleTester()->run(new NoEmptyHeadingsRule, [
            'valid' => [
                '<h1 x-text="title"></h1>',
                '<h2 x-html="headingHtml"></h2>',
                '<h3 @if($rich) x-html="html" @else x-text="text" @endif></h3>',
                '<h4 wire:text="heading"></h4>',
                '<h5 @if($live) wire:text="heading" @else x-text="fallback" @endif></h5>',
            ],
            'invalid' => [[
                'code' => '<h1 x-text=""></h1>',
                'errors' => 1,
            ], [
                'code' => '<h1 @if($ready) x-text="title" @endif></h1>',
                'errors' => 1,
            ], [
                'code' => '<h1 wire:text=""></h1>',
                'errors' => 1,
            ], [
                'code' => '<h1 @if($ready) wire:text="title" @endif></h1>',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for headings with text content', function (): void {
        $this->getRuleTester()->run(new NoEmptyHeadingsRule, [
            'valid' => ['<h1>Title</h1>'],
        ]);
    });

    it('uses effective heading semantics and accessible names', function (): void {
        $this->getRuleTester()->run(new NoEmptyHeadingsRule, [
            'valid' => [
                '<h1 aria-label="Title"></h1>',
                '<h1 role="none"></h1>',
            ],
            'invalid' => [[
                'code' => '<div role="heading" aria-level="2"></div>',
                'errors' => 1,
            ], [
                'code' => '<h2 role="heading" :aria-level="$level"></h2>',
                'errors' => 1,
            ]],
        ]);
    });

    it('ignores headings excluded from the accessibility tree', function (string $code): void {
        $this->getRuleTester()->run(new NoEmptyHeadingsRule, ['valid' => [$code]]);
    })->with([
        'hidden heading' => '<h1 hidden></h1>',
        'inert ancestor' => '<section inert><h2></h2></section>',
        'aria-hidden ancestor' => '<section aria-hidden="true"><h2></h2></section>',
    ]);

    it('requires heading content on every conditional render path', function (): void {
        $this->getRuleTester()->run(new NoEmptyHeadingsRule, [
            'invalid' => [[
                'code' => '<h2>@if($show)Title@endif</h2>',
                'errors' => 1,
            ]],
        ]);
    });

    it('does not count content inside an inert descendant', function (): void {
        $this->getRuleTester()->run(new NoEmptyHeadingsRule, [
            'invalid' => [[
                'code' => '<h2><span inert>Title</span></h2>',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for headings with nested text', function (): void {
        $this->getRuleTester()->run(new NoEmptyHeadingsRule, [
            'valid' => ['<h2><strong>Section title</strong></h2>'],
        ]);
    });

    it('passes for headings with image with alt text', function (): void {
        $this->getRuleTester()->run(new NoEmptyHeadingsRule, [
            'valid' => ['<h1><img src="logo.png" alt="Company Logo"></h1>'],
        ]);
    });

    it('does not treat non-rendering PHP as heading content', function (): void {
        $this->getRuleTester()->run(new NoEmptyHeadingsRule, [
            'invalid' => [[
                'code' => '<h1><?php $heading = null; ?></h1>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for headings with only whitespace', function (): void {
        $this->getRuleTester()->run(new NoEmptyHeadingsRule, [
            'invalid' => [[
                'code' => "<h2> \n\t </h2>",
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for headings with empty nested elements', function (): void {
        $this->getRuleTester()->run(new NoEmptyHeadingsRule, [
            'invalid' => [[
                'code' => '<h2><strong>   </strong></h2>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for headings with image without alt', function (): void {
        $this->getRuleTester()->run(new NoEmptyHeadingsRule, [
            'invalid' => [
                [
                    'code' => '<h1><img src="logo.png"></h1>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for headings with image with empty alt', function (): void {
        $this->getRuleTester()->run(new NoEmptyHeadingsRule, [
            'invalid' => [
                [
                    'code' => '<h1><img src="logo.png" alt=""></h1>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('reports multiple empty headings', function (): void {
        $this->getRuleTester()->run(new NoEmptyHeadingsRule, [
            'invalid' => [
                [
                    'code' => '<h1></h1><h2></h2><h3></h3>',
                    'errors' => 3,
                ],
            ],
        ]);
    });
});
