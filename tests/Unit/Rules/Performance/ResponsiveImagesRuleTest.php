<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Performance\ResponsiveImagesRule;

describe('ResponsiveImagesRule', function (): void {
    it('uses an unconditional Blade class directive before a later duplicate class', function (): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'valid' => [
                '<img @class([\'icon\']) src="x.jpg" srcset="" width="500" class="photo" role="img" alt="description">',
                '<img @class($classes) src="x.jpg" srcset="" width="500" class="photo" role="img" alt="description">',
            ],
            'invalid' => [[
                'code' => '<img @class([\'photo\']) src="x.jpg" srcset="" width="500" class="icon" role="img" alt="description">',
                'errors' => 1,
            ]],
        ]);
    });

    it('uses explicit image attributes before a later opaque provider', function (): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'valid' => [
                '<img {{ $attributes }} src="photo.jpg" srcset="" width="500" class="photo" role="img" alt="description">',
            ],
            'invalid' => [[
                'code' => '<img src="photo.jpg" srcset="" width="500" class="photo" role="img" alt="description" {{ $attributes }}>',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for images with srcset', function (): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'valid' => [
                '<img src="photo.jpg" srcset="photo-320.jpg 320w, photo-640.jpg 640w">',
                '<img src="hero.jpg" srcset="hero-1x.jpg 1x, hero-2x.jpg 2x" alt="Hero">',
                '<img src="photo.jpg" srcset="photo.jpg" alt="Photo">',
                '<img src="photo.jpg" srcset="small.jpg .5x, large.jpg 2x" alt="Photo">',
                '<img src="photo.jpg" srcset="photo.jpg 800w 600h" alt="Photo">',
            ],
        ]);
    });

    it('passes for picture sources that provide responsive candidates', function (): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'valid' => [
                '<picture><source srcset="photo.avif 1x, photo@2x.avif 2x"><img src="photo.jpg" alt="Photo"></picture>',
                '<picture>@if($avif)<source srcset="photo.avif 1x, photo@2x.avif 2x">@endif<img src="photo.jpg" alt="Photo"></picture>',
                '<picture><source {{ $sourceAttributes }}><img src="photo.jpg" alt="Photo"></picture>',
            ],
        ]);
    });

    it('rejects empty responsive candidates', function (string $code): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'empty img srcset' => '<img src="photo.jpg" srcset="" alt="Photo">',
        'whitespace img srcset' => '<img src="photo.jpg" srcset="   " alt="Photo">',
        'empty picture source' => '<picture><source srcset=""><img src="photo.jpg" alt="Photo"></picture>',
        'zero width' => '<img src="photo.jpg" srcset="small.jpg 0w" alt="Photo">',
        'zero density' => '<img src="photo.jpg" srcset="small.jpg 0x" alt="Photo">',
        'negative density' => '<img src="photo.jpg" srcset="small.jpg -1x" alt="Photo">',
        'comma only' => '<img src="photo.jpg" srcset="," alt="Photo">',
        'duplicate width descriptor' => '<img src="photo.jpg" srcset="small.jpg 320w 640w" alt="Photo">',
        'mixed width and density' => '<img src="photo.jpg" srcset="small.jpg 320w 2x" alt="Photo">',
        'height without width' => '<img src="photo.jpg" srcset="small.jpg 200h" alt="Photo">',
        'invalid picture source' => '<picture><source srcset="small.jpg 0w"><img src="photo.jpg" alt="Photo"></picture>',
    ]);

    it('passes for SVG images (vector graphics)', function (): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'valid' => [
                '<img src="logo.svg" alt="Logo">',
                '<img src="icon.SVG" alt="Icon">',
                '<img src="logo.svg?v=2026" alt="Logo">',
                '<img src="/icons/mark.SVG#symbol" alt="Mark">',
            ],
        ]);
    });

    it('passes for data URI images', function (): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'valid' => [
                '<img src="data:image/png;base64,abc123" alt="Inline image">',
                '<img src="DATA:image/png;base64,abc123" alt="Inline image">',
            ],
        ]);
    });

    it('passes for small images (below minWidth)', function (): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'valid' => [
                '<img src="icon.png" width="50" alt="Icon">',
                '<img src="badge.png" width="100" alt="Badge">',
            ],
        ]);
    });

    it('passes for icon class images', function (): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'valid' => [
                '<img src="menu.png" class="icon" alt="Menu">',
                '<img src="site.png" class="favicon" alt="Site">',
                '<img src="user.png" class="avatar-sm" alt="User">',
            ],
        ]);
    });

    it('lets projects replace the excluded class defaults', function (): void {
        $rule = new ResponsiveImagesRule;
        $rule->setOptions(['excludeClasses' => ['thumbnail']]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<img src="thumb.png" class="THUMBNAIL" alt="Thumbnail">',
            ],
            'invalid' => [[
                'code' => '<img src="menu.png" class="icon" alt="Menu">',
                'errors' => 1,
            ]],
        ]);
    });

    it('can disable class-based exclusions', function (): void {
        $rule = new ResponsiveImagesRule;
        $rule->setOptions(['excludeClasses' => []]);

        $this->getRuleTester()->run($rule, [
            'invalid' => [[
                'code' => '<img src="menu.png" class="icon" alt="Menu">',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for decorative images', function (): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'valid' => [
                '<img src="decoration.png" alt="">',
                '<img src="bg.png" role="presentation" alt="Background">',
                '<img src="spacer.gif" role="none" alt="Spacer">',
                '<img src="bg.png" role="PRESENTATION" alt="Background">',
                "<img src=\"bg.png\" role=\"\t presentation \" alt=\"Background\">",
                '<img src="bg.png" role="none presentation" alt="Background">',
                '<img src="bg.png" role="future none" alt="Background">',
                '<img src="bg.png" role="presentation" contenteditable="false" alt="Background">',
                '<img src="bg.png" role="presentation" aria-foo="bar" alt="Background">',
            ],
        ]);
    });

    it('fails for large images without srcset', function (): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'invalid' => [
                [
                    'code' => '<img src="photo.jpg" alt="Photo">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for images with width above minWidth', function (): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'invalid' => [
                [
                    'code' => '<img src="photo.jpg" width="800" alt="Photo">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('does not treat vertical tabs as HTML whitespace in width, role, or class', function (string $code): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'width' => "<img src=\"photo.jpg\" width=\"\x0B100\" alt=\"Photo\">",
        'role' => "<img src=\"photo.jpg\" role=\"presentation\x0B\" alt=\"Photo\">",
        'class prefix' => "<img src=\"photo.jpg\" class=\"\x0Bicon\" alt=\"Photo\">",
        'class suffix' => "<img src=\"photo.jpg\" class=\"icon\x0B\" alt=\"Photo\">",
    ]);

    it('does not exempt a presentational role that conflicts with semantics', function (string $code): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'global ARIA property' => '<img src="photo.jpg" role="future none" aria-label="Photo">',
        'programmatically focusable' => '<img src="photo.jpg" role="presentation" tabindex="-1" alt="Photo">',
    ]);

    it('normalizes leading C0 controls and space before data URLs', function (string $src): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'valid' => ["<img src=\"{$src}\" alt=\"Inline\">"],
        ]);
    })->with([
        'space' => ' data:image/png;base64,abc123',
        'vertical tab' => "\x0Bdata:image/png;base64,abc123",
    ]);

    it('respects excludePatterns option', function (): void {
        $rule = new ResponsiveImagesRule;
        $rule->setOptions(['excludePatterns' => ['placeholder', 'tiny']]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<img src="placeholder.jpg" alt="Placeholder">',
                '<img src="tiny-image.png" alt="Tiny">',
            ],
        ]);
    });

    it('respects custom minWidth option', function (): void {
        $rule = new ResponsiveImagesRule;
        $rule->setOptions(['minWidth' => 100]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<img src="small.png" width="50" alt="Small">',
            ],
            'invalid' => [
                [
                    'code' => '<img src="medium.png" width="150" alt="Medium">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('skips images with dynamic src attributes', function (): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'valid' => [
                '<img :src="imageUrl" alt="Dynamic image">',
                '<img src="{{ $imagePath }}" alt="Dynamic image">',
                '<img src="{{ asset(\'images/photo.jpg\') }}" alt="Dynamic image">',
            ],
        ]);
    });

    it('applies image exemptions on every attribute render path', function (string $code): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, ['valid' => [$code]]);
    })->with([
        'SVG sources' => '<img @if($x) src="a.svg" alt="A" @else src="b.svg" alt="B" @endif>',
        'decorative alternatives' => '<img src="decoration.jpg" @if($x) alt="" @else role="presentation" alt="Decoration" @endif>',
        'small image widths' => '<img src="icon.png" @if($x) width="50" @else width="100" @endif alt="Icon">',
    ]);

    it('stands down when separate attribute conditionals require predicate correlation', function (): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'valid' => [
                '<img @if($x) src="photo.jpg" @else src="icon.svg" @endif @if($x) alt="" @else alt="Icon" @endif>',
            ],
        ]);
    });

    it('reports split conditional attributes when every Cartesian path needs responsive candidates', function (): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'invalid' => [[
                'code' => '<img @if($x) src="dark.jpg" @else src="light.jpg" @endif @if($y) width="800" @else alt="Photo" @endif>',
                'errors' => 1,
            ]],
        ]);
    });

    it('reports when one attribute render path still needs responsive candidates', function (): void {
        $this->getRuleTester()->run(new ResponsiveImagesRule, [
            'invalid' => [[
                'code' => '<img @if($x) src="icon.svg" @else src="photo.jpg" @endif alt="Photo">',
                'errors' => 1,
            ]],
        ]);
    });
});
