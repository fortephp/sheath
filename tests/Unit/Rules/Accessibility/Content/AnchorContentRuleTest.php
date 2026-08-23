<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Content\AnchorContentRule;

describe('AnchorContentRule', function (): void {
    it('passes for anchors with text content', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'valid' => [
                '<a href="/home">Home</a>',
                '<a href="/about">About Us</a>',
                '<a href="#">Click here</a>',
            ],
        ]);
    });

    it('does not require link content from href-less placeholder anchors', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'valid' => [
                '<a id="section"></a>',
                '<a name="legacy-target"></a>',
                '<a></a>',
            ],
        ]);
    });

    it('correlates link applicability with names on the same attribute path', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'valid' => [
                '<a @if($linked) href="/" aria-label="Home" @endif></a>',
                '<a @if($hidden) href="/" aria-hidden="true" @else href="/" aria-label="Home" @endif></a>',
            ],
        ]);
    });

    it('ignores links outside the accessibility tree', function (string $code): void {
        $this->getRuleTester()->run(new AnchorContentRule, ['valid' => [$code]]);
    })->with([
        'hidden link' => '<a href="/" hidden></a>',
        'aria-hidden link' => '<a href="/" aria-hidden="true"></a>',
        'inert ancestor' => '<section inert><a href="/"></a></section>',
    ]);

    it('passes for anchors with aria-label', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'valid' => [
                '<a href="/home" aria-label="Go to homepage"></a>',
                '<a href="#" aria-label="Menu toggle"><i class="icon-menu"></i></a>',
            ],
        ]);
    });

    it('passes for anchors with aria-labelledby', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'valid' => [
                '<span id="nav-home">Home</span><a href="/home" aria-labelledby="nav-home"></a>',
                '@if($show)<span id="nav-home">Home</span><a href="/home" aria-labelledby="nav-home"></a>@endif',
            ],
        ]);
    });

    it('does not use a captured label at its definition site', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'invalid' => [[
                'code' => '<!doctype html><html><body>@push("labels")<span id="nav-home">Home</span>@endpush<a href="/home" aria-labelledby="nav-home"></a></body></html>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for unresolved or empty aria-labelledby references in a complete document', function (string $label): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'invalid' => [[
                'code' => "<!doctype html><html><body>{$label}<a href=\"/home\" aria-labelledby=\"nav-home\"></a></body></html>",
                'errors' => 1,
            ]],
        ]);
    })->with([
        'missing target' => '',
        'empty target' => '<span id="nav-home"></span>',
    ]);

    it('passes for anchors with title attribute', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'valid' => [
                '<a href="/home" title="Go home"></a>',
            ],
        ]);
    });

    it('resolves aria-labelledby against exact untrimmed HTML ids', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'invalid' => [[
                'code' => '<html><span id=" foo ">Name</span><a href="/" aria-labelledby="foo"></a></html>',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for anchors with img with alt text', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'valid' => [
                '<a href="/"><img src="logo.png" alt="Company Logo"></a>',
            ],
        ]);
    });

    it('passes when a child SVG contributes the accessible name', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'valid' => [
                '<a href="/"><svg aria-label="Home"></svg></a>',
                '<span id="home-label">Home</span><a href="/"><svg aria-labelledby="home-label"></svg></a>',
            ],
        ]);
    });

    it('fails for empty anchors', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'invalid' => [
                [
                    'code' => '<a href="/home"></a>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('does not count content inside an inert descendant', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'invalid' => [[
                'code' => '<a href="/"><span inert>Home</span></a>',
                'errors' => 1,
            ]],
        ]);
    });

    it('does not treat non-rendering directives as anchor content', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'invalid' => [[
                'code' => '<a href="/">@csrf</a>',
                'errors' => 1,
            ]],
        ]);
    });

    it('requires accessible content on every conditional render path', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'invalid' => [[
                'code' => '<a href="/">@if($show)Home@endif</a>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for anchors with only whitespace', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'invalid' => [
                [
                    'code' => '<a href="/home">   </a>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for anchors with empty aria-label', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'invalid' => [
                [
                    'code' => '<a href="/home" aria-label=""></a>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for anchors with only icon and no accessible name', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'invalid' => [
                [
                    'code' => '<a href="#"><i class="icon"></i></a>',
                    'errors' => 1,
                ],
                [
                    'code' => '<a href="#"><svg aria-hidden="true" aria-label="Hidden icon"></svg></a>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for anchors with img without alt', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'invalid' => [
                [
                    'code' => '<a href="/"><img src="logo.png"></a>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('passes for anchors whose text is rendered by Alpine or Livewire', function (string $code): void {
        $this->getRuleTester()->run(new AnchorContentRule, ['valid' => [$code]]);
    })->with([
        'x-text' => '<a href="#" x-text="label"></a>',
        'x-html' => '<a href="#" x-html="labelHtml"></a>',
        'wire:text' => '<a href="#" wire:text="label"></a>',
    ]);

    it('rejects empty client-rendered text directives', function (string $code): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'empty x-text' => '<a href="#" x-text=""></a>',
        'whitespace x-html' => '<a href="#" x-html="  "></a>',
        'bare x-text' => '<a href="#" x-text></a>',
        'empty wire:text' => '<a href="#" wire:text=""></a>',
        'bare wire:text' => '<a href="#" wire:text></a>',
    ]);

    it('correlates reactive visibility for link content', function (): void {
        $this->getRuleTester()->run(new AnchorContentRule, [
            'valid' => [
                '<a href="#" x-show="open"><span wire:show="open">Open</span></a>',
                '<span id="label" wire:dirty>Changed</span><a href="#" wire:dirty aria-labelledby="label"></a>',
            ],
            'invalid' => [[
                'code' => '<a href="#"><span x-show="open">Open</span></a>',
                'errors' => 1,
            ], [
                'code' => '<span id="label" wire:offline>Offline</span><a href="#" aria-labelledby="label"></a>',
                'errors' => 1,
            ]],
        ]);
    });
});
