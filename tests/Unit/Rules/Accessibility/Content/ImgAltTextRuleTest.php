<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Content\ImgAltTextRule;

describe('ImgAltTextRule', function (): void {
    it('passes for images with alt attribute', function (): void {
        $this->getRuleTester()->run(new ImgAltTextRule, [
            'valid' => [
                '<img src="test.jpg" alt="Test image">',
                '<img alt="" src="test.jpg">',
                '<img src="test.jpg" alt="Description">',
            ],
        ]);
    });

    it('uses the first duplicate alt attribute when non-empty alt text is required', function (): void {
        $rule = new ImgAltTextRule;
        $rule->setOptions(['requireNonEmpty' => true]);

        $this->getRuleTester()->run($rule, [
            'valid' => ['<img alt="good" @if($x) alt="" @endif>'],
        ]);
    });

    it('fails for images without alt attribute', function (): void {
        $this->getRuleTester()->run(new ImgAltTextRule, [
            'invalid' => [
                [
                    'code' => '<img src="test.jpg">',
                    'errors' => 1,
                ],
                [
                    'code' => '<img src="test.jpg" class="photo">',
                    'errors' => 1,
                ],
                [
                    'code' => '<IMG src="test.jpg">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('detects multiple images without alt', function (): void {
        $this->getRuleTester()->run(new ImgAltTextRule, [
            'invalid' => [
                [
                    'code' => '<img src="1.jpg"><img src="2.jpg">',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('works with blade syntax', function (): void {
        $this->getRuleTester()->run(new ImgAltTextRule, [
            'valid' => [
                '<img src="{{ $url }}" alt="{{ $description }}">',
            ],
            'invalid' => [
                [
                    'code' => '<img src="{{ $url }}">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('ignores tag-looking text inside HTML special-text elements', function (): void {
        $this->getRuleTester()->run(new ImgAltTextRule, [
            'valid' => [
                '<script defer>const image = "<img>";</script>',
                '<style scoped>.icon::before { content: "<img>"; }</style>',
                '<textarea><img></textarea>',
                '<title><img></title>',
            ],
        ]);
    });
});

describe('explicit image semantics', function (): void {
    it('requires a non-empty name when empty alt is overridden with an image role', function (): void {
        $this->getRuleTester()->run(new ImgAltTextRule, [
            'invalid' => [[
                'code' => '<img src="chart.png" alt="" role="img">',
                'errors' => 1,
            ]],
        ]);
    });

    it('accepts an ARIA name or an unconflicted decorative role', function (string $code): void {
        $this->getRuleTester()->run(new ImgAltTextRule, ['valid' => [$code]]);
    })->with([
        '<img src="chart.png" alt="" role="img" aria-label="Sales chart">',
        '<span id="chart-name">Sales chart</span><img src="chart.png" alt="" aria-labelledby="chart-name">',
        '<img src="chart.png" alt="" aria-label="Sales chart">',
        '<img src="decoration.png" alt="" role="presentation">',
    ]);

    it('restores native image semantics when a presentational role conflicts', function (string $code): void {
        $this->getRuleTester()->run(new ImgAltTextRule, [
            'invalid' => [['code' => $code, 'errors' => 1]],
        ]);
    })->with([
        '<img src="chart.png" alt="" role="none" tabindex="0">',
        '<img src="chart.png" alt="" role="presentation" contenteditable="true">',
        '<img src="chart.png" alt="" role="none" aria-describedby="description">',
        '<img src="chart.png" alt="" aria-describedby="description">',
        '<img src="chart.png" alt="" role="img" title="Chart">',
    ]);
});
