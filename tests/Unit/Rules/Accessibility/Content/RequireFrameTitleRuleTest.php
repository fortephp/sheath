<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Content\RequireFrameTitleRule;

describe('RequireFrameTitleRule', function (): void {
    it('passes for iframe with title', function (): void {
        $this->getRuleTester()->run(new RequireFrameTitleRule, [
            'valid' => [
                '<iframe src="video.html" title="Product demo video"></iframe>',
                '<iframe src="map.html" title="Office location map"></iframe>',
                '<iframe title="Contact form" src="form.html"></iframe>',
            ],
        ]);
    });

    it('uses the first duplicate title attribute on each render path', function (): void {
        $this->getRuleTester()->run(new RequireFrameTitleRule, [
            'valid' => ['<iframe title="ok" @if($x) title="" @endif></iframe>'],
        ]);
    });

    it('passes for hidden iframes', function (): void {
        $this->getRuleTester()->run(new RequireFrameTitleRule, [
            'valid' => [
                '<iframe src="tracking.html" aria-hidden="true"></iframe>',
                '<iframe src="hidden.html" aria-hidden="true"></iframe>',
                '<iframe src="tracking.html" aria-hidden="TRUE"></iframe>',
                '<iframe src="hidden.html" hidden></iframe>',
                '<iframe src="inert.html" inert></iframe>',
                '<div aria-hidden="true"><iframe src="tracking.html"></iframe></div>',
                '<div hidden><iframe src="hidden.html"></iframe></div>',
                '<div inert><iframe src="inert.html"></iframe></div>',
            ],
        ]);
    });

    it('still requires a title when exclusion from the accessibility tree is conditional', function (string $code): void {
        $this->getRuleTester()->run(new RequireFrameTitleRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'bound inert ancestor' => '<div :inert="$inert"><iframe src="frame.html"></iframe></div>',
        'dynamic aria-hidden ancestor' => '<div aria-hidden="{{ $hidden }}"><iframe src="frame.html"></iframe></div>',
        'bound hidden attribute' => '<iframe :hidden="$hidden" src="frame.html"></iframe>',
        'bound inert attribute' => '<iframe :inert="$inert" src="frame.html"></iframe>',
    ]);

    it('correlates exclusion and titles on the same attribute path', function (string $code): void {
        $this->getRuleTester()->run(new RequireFrameTitleRule, ['valid' => [$code]]);
    })->with([
        'aria-hidden branch' => '<iframe @if($decorative) aria-hidden="true" @else title="Map" @endif></iframe>',
        'hidden branch' => '<iframe @if($decorative) hidden @else title="Map" @endif></iframe>',
        'inert branch' => '<iframe @if($decorative) inert @else title="Map" @endif></iframe>',
    ]);

    it('passes for frame with title (legacy)', function (): void {
        $this->getRuleTester()->run(new RequireFrameTitleRule, [
            'valid' => [
                '<frame src="nav.html" title="Navigation menu">',
            ],
        ]);
    });

    it('fails for iframe without title', function (): void {
        $this->getRuleTester()->run(new RequireFrameTitleRule, [
            'invalid' => [
                [
                    'code' => '<iframe src="video.html"></iframe>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for iframe with empty title', function (): void {
        $this->getRuleTester()->run(new RequireFrameTitleRule, [
            'invalid' => [
                [
                    'code' => '<iframe src="video.html" title=""></iframe>',
                    'errors' => 1,
                ],
                [
                    'code' => '<iframe src="video.html" title="   "></iframe>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for frame without title (legacy)', function (): void {
        $this->getRuleTester()->run(new RequireFrameTitleRule, [
            'invalid' => [
                [
                    'code' => '<frame src="nav.html">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('reports multiple iframes without title', function (): void {
        $this->getRuleTester()->run(new RequireFrameTitleRule, [
            'invalid' => [
                [
                    'code' => '<iframe src="a.html"></iframe><iframe src="b.html"></iframe>',
                    'errors' => 2,
                ],
            ],
        ]);
    });
});

describe('accessible-name alternatives', function (): void {
    it('accepts iframe names from ARIA', function (string $code): void {
        $this->getRuleTester()->run(new RequireFrameTitleRule, ['valid' => [$code]]);
    })->with([
        '<iframe aria-label="Map"></iframe>',
        '<span id="map-name">Map</span><iframe aria-labelledby="map-name"></iframe>',
    ]);
});
