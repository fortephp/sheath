<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Seo\CanonicalTagRule;

describe('CanonicalTagRule', function (): void {
    it('uses explicit canonical attributes before a later opaque provider', function (): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'valid' => ['<head><link {{ $attributes }} rel="canonical" href=""></head>'],
            'invalid' => [[
                'code' => '<head><link rel="canonical" href="" {{ $attributes }}></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for documents with canonical link', function (): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'valid' => [
                '<head><link rel="canonical" href="https://example.com/page"></head>',
                '<head><title>Test</title><link rel="canonical" href="/page"></head>',
                '<head><link rel="alternate canonical" href="/page"></head>',
            ],
        ]);
    });

    it('passes for partial templates without head', function (): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'valid' => [
                '<div>Partial content</div>',
                '<section>Content</section>',
            ],
        ]);
    });

    it('stays quiet when the head pulls in content it cannot see through', function (): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'valid' => [
                '<head><s:se_meta /></head>',
                "<head>@include('partials.meta')</head>",
                "<head>@stack('meta')</head>",
                '<head>{!! $meta !!}</head>',
            ],
        ]);
    });

    it('still fails when the head content is fully visible', function (): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'invalid' => [
                [
                    'code' => '<head><title>Test</title>{{ $meta }}</head>',
                    'errors' => 1,
                ],
                [
                    'code' => '<head>@if($secure)<link rel="stylesheet" href="style.css">@endif</head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('requires opaque and dynamic providers on every render path', function (string $code): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'opaque include' => "<head>@if(\$custom)@include('canonical')@endif</head>",
        'dynamic rel' => '<head>@if($custom)<link rel="{{ $rel }}" href="/page">@endif</head>',
    ]);

    it('does not count canonical links inside inert templates', function (): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'invalid' => [[
                'code' => '<head><template><link rel="canonical" href="/template"></template></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for documents without canonical link', function (): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'invalid' => [
                [
                    'code' => '<head><title>Test</title></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for empty canonical href', function (): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'invalid' => [
                [
                    'code' => '<head><link rel="canonical" href=""></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('reports each empty canonical at its own location', function (): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'invalid' => [[
                'code' => "<head>\n<link rel=\"canonical\" href=\"\">\n<link rel=\"canonical\" href=\"/valid\">\n<link rel=\"canonical\">\n</head>",
                'errors' => [
                    ['line' => 2],
                    ['line' => 4],
                ],
            ]],
        ]);
    });

    it('handles case insensitivity for rel attribute', function (): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'valid' => [
                '<head><link rel="Canonical" href="https://example.com"></head>',
                '<head><link rel="CANONICAL" href="https://example.com"></head>',
            ],
        ]);
    });

    it('ignores other link types', function (): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'invalid' => [
                [
                    'code' => '<head><link rel="stylesheet" href="style.css"></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('does not validate captured canonical links at their definition site', function (): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'valid' => [
                '<head>@push("x")<link rel="canonical" href="">@endpush<link rel="canonical" href="/live"></head>',
            ],
        ]);
    });

    it('accepts a non-empty canonical href on every attribute render path', function (): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'valid' => [
                '<head><link rel="canonical" @if($x) href="/x" @else href="/y" @endif></head>',
            ],
        ]);
    });

    it('correlates separate attribute conditionals when checking document presence', function (): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'invalid' => [[
                'code' => '<head><link @if($x) rel="canonical" @else rel="stylesheet" @endif @if($x) href="/canonical" @else href="" @endif></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('uses exact repeated predicates for split canonical attributes', function (): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'invalid' => [[
                'code' => '<head><link @if($x) rel="canonical" @endif @if($x) href="" @endif></head>',
                'errors' => 2,
            ]],
        ]);
    });

    it('reports split attribute conditionals when every Cartesian path has an empty canonical', function (): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'invalid' => [[
                'code' => '<head><link @if($x) rel="canonical" @else rel="canonical" @endif @if($y) href="" @else href="" @endif></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('requires canonical href and rel on every attribute render path', function (string $code): void {
        $this->getRuleTester()->run(new CanonicalTagRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'missing href branch' => '<head><link rel="canonical" @if($x) href="/x" @endif></head>',
        'non-canonical rel branch' => '<head><link @if($x) rel="canonical" href="/x" @else rel="stylesheet" href="/app.css" @endif></head>',
    ]);
});
