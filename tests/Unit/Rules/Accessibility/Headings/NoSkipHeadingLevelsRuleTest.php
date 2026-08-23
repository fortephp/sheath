<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Headings\NoSkipHeadingLevelsRule;

describe('NoSkipHeadingLevelsRule', function (): void {
    it('tracks heading levels through switch arms', function (): void {
        $this->getRuleTester()->run(new NoSkipHeadingLevelsRule, [
            'invalid' => [[
                'code' => '<h2>A</h2>@switch($view) @case("short")<h4>B</h4>@break @default<h4>C</h4>@endswitch',
                'errors' => 2,
            ]],
        ]);
    });
    it('does not place captured headings in the definition-site sequence', function (): void {
        $this->getRuleTester()->run(new NoSkipHeadingLevelsRule, [
            'valid' => ['<h1>A</h1>@push("x")<h3>Captured</h3>@endpush<h2>B</h2>'],
        ]);
    });
    it('accepts non-skipping heading sequences', function (): void {
        $this->getRuleTester()->run(new NoSkipHeadingLevelsRule, [
            'valid' => [
                '<h1>Title</h1><h2>Section</h2><h3>Subsection</h3>',
                '<h1>Title</h1><h2>Section 1</h2><h3>Sub</h3><h2>Section 2</h2>',
                '<h1>Title</h1>',
                '<div>No headings here</div>',
            ],
        ]);
    });

    it('fails when skipping from h1 to h3', function (): void {
        $this->getRuleTester()->run(new NoSkipHeadingLevelsRule, [
            'invalid' => [
                [
                    'code' => '<h1>Title</h1><h3>Skipped h2</h3>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('uses source order for multiple headings on the same line', function (): void {
        $this->getRuleTester()->run(new NoSkipHeadingLevelsRule, [
            'invalid' => [[
                'code' => '<h2>A</h2><h4>B</h4><h3>C</h3>',
                'errors' => 1,
            ]],
        ]);
    });

    it('reports multiple violations', function (): void {
        $this->getRuleTester()->run(new NoSkipHeadingLevelsRule, [
            'invalid' => [
                [
                    'code' => '<h1>Title</h1><h3>Skip 1</h3><h6>Skip 2</h6>',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('checks heading sequences on each conditional render path', function (): void {
        $this->getRuleTester()->run(new NoSkipHeadingLevelsRule, [
            'invalid' => [[
                'code' => '<h1>A</h1>@if($compact)<h2>B</h2>@else<h3>C</h3>@endif',
                'errors' => 1,
            ]],
        ]);
    });

    it('checks transitions into repeated loop iterations', function (): void {
        $this->getRuleTester()->run(new NoSkipHeadingLevelsRule, [
            'invalid' => [[
                'code' => '<h2>Pre</h2>@foreach($items as $item)<h3>X</h3><h1>Y</h1>@endforeach',
                'errors' => 1,
            ]],
        ]);
    });

    it('excludes exhaustive conditional presentational-role paths from the sequence', function (): void {
        $this->getRuleTester()->run(new NoSkipHeadingLevelsRule, [
            'valid' => [
                '<h2>A</h2><h4 @if($x) role="none" @else role="presentation" @endif>B</h4>',
                "<h2>A</h2><h4 role=\"none\" tabindex=\"\x0B1\">B</h4>",
            ],
            'invalid' => [[
                'code' => '<h2>A</h2><div role="heading" @if($x) aria-level="4" @else aria-level="4" @endif>B</div>',
                'errors' => 1,
            ], [
                'code' => '<h2>A</h2><h4 role="none" tabindex="1junk">B</h4>',
                'errors' => 1,
            ]],
        ]);
    });

    it('uses effective heading roles and excludes template contents', function (string $code): void {
        $this->getRuleTester()->run(new NoSkipHeadingLevelsRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'ARIA integer syntax' => '<h1>A</h1><div role="heading" aria-level=" +03 ">C</div>',
        'presentational native heading' => '<h1>A</h1><h2 role="none">decor</h2><h3>C</h3>',
        'inert template heading' => '<h1>A</h1><template><h2>T</h2></template><h3>C</h3>',
        'accessibility-hidden heading' => '<h1>A</h1><h2 aria-hidden="true">T</h2><h3>C</h3>',
    ]);

    it('tracks reactive visibility and Alpine template repetitions', function (): void {
        $this->getRuleTester()->run(new NoSkipHeadingLevelsRule, [
            'valid' => [
                '<h1>A</h1><h2 x-show="open">B</h2><h3 wire:show="open">C</h3>',
                '<h1>A</h1><template x-if="open"><div><h2>B</h2><h3>C</h3></div></template>',
            ],
            'invalid' => [[
                'code' => '<h1>A</h1><h2 x-show="open">B</h2><h3>C</h3>',
                'errors' => 1,
            ], [
                'code' => '<h1>A</h1><template x-if="open"><h2>B</h2></template><h3>C</h3>',
                'errors' => 1,
            ], [
                'code' => '<h2>A</h2><template x-for="item in items"><div><h3>B</h3><h1>C</h1></div></template>',
                'errors' => 1,
            ]],
        ]);
    });
});
