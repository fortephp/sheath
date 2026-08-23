<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Performance\RequireExplicitSizeRule;

describe('RequireExplicitSizeRule', function (): void {
    it('uses explicit image attributes before a later opaque provider', function (): void {
        $this->getRuleTester()->run(new RequireExplicitSizeRule, [
            'valid' => ['<img {{ $attributes }} src="photo.jpg" width="-1" height="100">'],
            'invalid' => [[
                'code' => '<img src="photo.jpg" width="-1" height="100" {{ $attributes }}>',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for images with width and height', function (): void {
        $this->getRuleTester()->run(new RequireExplicitSizeRule, [
            'valid' => [
                '<img src="photo.jpg" width="800" height="600" alt="Photo">',
                '<img src="logo.png" width="200" height="100" alt="Logo">',
                '<img src="banner.webp" width="1200" height="400" alt="Banner">',
                "<img src=\"spaced.jpg\" width=\"\t100\" height=\" 50\" alt=\"Spaced\">",
            ],
        ]);
    });

    it('passes for images with runtime dimensions', function (): void {
        $this->getRuleTester()->run(new RequireExplicitSizeRule, [
            'valid' => [
                '<img src="photo.jpg" width="{{ $width }}" height="{{ $height }}" alt="Photo">',
                '<img src="photo.jpg" :width="$width" :height="$height" alt="Photo">',
            ],
        ]);
    });

    it('passes for SVG images without dimensions', function (): void {
        $this->getRuleTester()->run(new RequireExplicitSizeRule, [
            'valid' => [
                '<img src="icon.svg" alt="Icon">',
                '<img src="logo.SVG" alt="Logo">',
                '<img src="graphic.Svg" alt="Graphic">',
                '<img src="icon.svg?v=2" alt="Icon">',
                '<img src="/sprite.SVG#mark" alt="Mark">',
                '<img src="data:image/svg+xml,%3Csvg%20viewBox=%220%200%2010%2010%22/%3E" alt="Inline icon">',
                '<img src="DATA:image/svg+xml;base64,PHN2Zy8+" alt="Inline logo">',
            ],
        ]);
    });

    it('fails for images without width and height', function (): void {
        $this->getRuleTester()->run(new RequireExplicitSizeRule, [
            'invalid' => [
                [
                    'code' => '<img src="photo.jpg" alt="Photo">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for images with only width', function (): void {
        $this->getRuleTester()->run(new RequireExplicitSizeRule, [
            'invalid' => [
                [
                    'code' => '<img src="photo.jpg" width="800" alt="Photo">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for images with only height', function (): void {
        $this->getRuleTester()->run(new RequireExplicitSizeRule, [
            'invalid' => [
                [
                    'code' => '<img src="photo.jpg" height="600" alt="Photo">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for invalid static dimensions', function (string $code): void {
        $this->getRuleTester()->run(new RequireExplicitSizeRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'bare width' => [
            '<img src="photo.jpg" width height="600" alt="Photo">',
        ],
        'empty height' => [
            '<img src="photo.jpg" width="800" height="" alt="Photo">',
        ],
        'non-numeric width' => [
            '<img src="photo.jpg" width="auto" height="600" alt="Photo">',
        ],
        'negative height' => [
            '<img src="photo.jpg" width="800" height="-1" alt="Photo">',
        ],
        'vertical-tab-prefixed width' => [
            "<img src=\"photo.jpg\" width=\"\x0B100\" height=\"600\" alt=\"Photo\">",
        ],
    ]);

    it('validates the first dimensions emitted on each render path', function (): void {
        $this->getRuleTester()->run(new RequireExplicitSizeRule, [
            'valid' => [
                '<img src="photo.jpg" width="800" @if($legacy) width="bogus" @endif height="600" alt="Photo">',
            ],
        ]);
    });

    it('reports multiple violations', function (): void {
        $this->getRuleTester()->run(new RequireExplicitSizeRule, [
            'invalid' => [
                [
                    'code' => '<img src="a.jpg" alt="A"><img src="b.png" alt="B">',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('skips images with dynamic src attributes', function (): void {
        $this->getRuleTester()->run(new RequireExplicitSizeRule, [
            'valid' => [
                '<img :src="imageUrl" alt="Dynamic image">',
                '<img src="{{ $imagePath }}" alt="Dynamic image">',
                '<img src="{{ asset(\'images/photo.jpg\') }}" alt="Dynamic image">',
            ],
        ]);
    });
});
