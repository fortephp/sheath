<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Performance\LazyLoadImagesRule;

describe('LazyLoadImagesRule', function (): void {
    it('uses explicit loading attributes before a later opaque provider', function (): void {
        $rule = new LazyLoadImagesRule;
        $rule->setOptions(['skipAboveFold' => false]);

        $this->getRuleTester()->run($rule, [
            'valid' => ['<img {{ $attributes }} src="photo.jpg" loading="eager" fetchpriority="low">'],
            'invalid' => [[
                'code' => '<img src="photo.jpg" loading="eager" fetchpriority="low" {{ $attributes }}>',
                'errors' => 1,
            ]],
        ]);
    });

    it('reports invalid static loading keywords on below-fold images', function (string $value): void {
        $code = '<img src="hero.jpg"><img src="below.jpg" loading="'.$value.'">';

        $this->getRuleTester()->run(new LazyLoadImagesRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [['hasDangerousFix' => true]],
                'output' => '<img src="hero.jpg"><img src="below.jpg" loading="lazy">',
            ]],
        ]);
    })->with([
        'empty' => '',
        'eager' => 'eager',
        'surrounding whitespace' => ' lazy ',
        'unknown keyword' => 'bogus',
    ]);

    it('matches lazy case-insensitively and stands down for dynamic values', function (): void {
        $this->getRuleTester()->run(new LazyLoadImagesRule, [
            'valid' => [
                '<img src="hero.jpg"><img src="below.jpg" loading="LAZY">',
                '<img src="hero.jpg"><img src="below.jpg" :loading="$loading">',
            ],
        ]);
    });

    it('skips first image by default (above the fold)', function (): void {
        $this->getRuleTester()->run(new LazyLoadImagesRule, [
            'valid' => [
                '<img src="hero.jpg" alt="Hero">',
            ],
        ]);
    });

    it('does not treat a potentially repeated loop image as the one above-fold image', function (): void {
        $this->getRuleTester()->run(new LazyLoadImagesRule, [
            'invalid' => [[
                'code' => '@foreach($photos as $photo)<img src="photo.jpg">@endforeach',
                'errors' => 1,
            ]],
        ]);
    });

    it('does not infer output position from non-output Blade captures', function (): void {
        $this->getRuleTester()->run(new LazyLoadImagesRule, [
            'valid' => [
                '@section("hero")<img src="captured.jpg">@endsection<img src="first-live.jpg">',
                '<img src="first-live.jpg">@push("images")<img src="captured.jpg">@endpush',
                '@pushif($enabled, "images")<img src="captured.jpg">@endpushif<img src="first-live.jpg">',
            ],
            'invalid' => [[
                'code' => '@section("hero")<img src="captured.jpg">@endsection<img src="first-live.jpg"><img src="second-live.jpg">',
                'errors' => 1,
            ]],
        ]);
    });

    it('treats a section closed with show as live output', function (): void {
        $this->getRuleTester()->run(new LazyLoadImagesRule, [
            'invalid' => [[
                'code' => '@section("hero")<img src="shown.jpg">@show<img src="second.jpg">',
                'errors' => [['hasDangerousFix' => true]],
            ]],
        ]);
    });

    it('allows one above-fold image in each mutually exclusive branch', function (string $code): void {
        $this->getRuleTester()->run(new LazyLoadImagesRule, ['valid' => [$code]]);
    })->with([
        'if else' => '@if($desktop)<img src="wide.jpg">@else<img src="narrow.jpg">@endif',
        'switch' => '@switch($layout)@case("wide")<img src="wide.jpg">@break @default<img src="narrow.jpg">@endswitch',
    ]);

    it('handles a large elseif chain', function (): void {
        $branches = '@if($layout === 0)<img src="0.jpg">';
        for ($branch = 1; $branch < 800; $branch++) {
            $branches .= "@elseif(\$layout === {$branch})<img src=\"{$branch}.jpg\">";
        }
        $branches .= '@endif';

        $this->getRuleTester()->run(new LazyLoadImagesRule, ['valid' => [$branches]]);
    });

    it('withholds image-loading changes unless dangerous fixes are enabled', function (): void {
        $code = '<img src="first.jpg"><img src="second.jpg">';
        $tester = $this->getRuleTester();

        expect($tester->fix(new LazyLoadImagesRule, $code))->toBe($code)
            ->and($tester->fix(new LazyLoadImagesRule, $code, dangerous: true))
            ->toBe('<img src="first.jpg"><img src="second.jpg" loading="lazy">');
    });

    it('only exempts the exact fetchpriority high keyword', function (): void {
        $this->getRuleTester()->run(new LazyLoadImagesRule, [
            'valid' => [
                '<img src="hero.jpg"><img src="important.jpg" fetchpriority="high">',
            ],
            'invalid' => [[
                'code' => '<img src="hero.jpg"><img src="important.jpg" fetchpriority=" high ">',
                'errors' => 1,
            ]],
        ]);
    });

    it('can check all images when skipAboveFold is false', function (): void {
        $rule = new LazyLoadImagesRule;
        $rule->setOptions(['skipAboveFold' => false]);

        $this->getRuleTester()->run($rule, [
            'invalid' => [
                [
                    'code' => '<img src="photo.jpg">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('does not skip images with dynamic fetchpriority', function (): void {
        $this->getRuleTester()->run(new LazyLoadImagesRule, [
            'invalid' => [
                [
                    'code' => '<img src="first.jpg"><img src="second.jpg" :fetchpriority="priority">',
                    'errors' => 1,
                ],
                [
                    'code' => '<img src="first.jpg"><img src="second.jpg" fetchpriority="{{ $priority }}">',
                    'errors' => 1,
                ],
            ],
        ]);
    });
});
