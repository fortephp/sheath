<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Seo\MetaDescriptionRule;

describe('MetaDescriptionRule', function (): void {
    it('uses explicit description attributes before a later opaque provider', function (): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'valid' => ['<head><meta {{ $attributes }} name="description" content=""></head>'],
            'invalid' => [[
                'code' => '<head><meta name="description" content="" {{ $attributes }}></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for documents with meta description', function (): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'valid' => [
                '<head><meta name="description" content="A comprehensive page about various topics and helpful resources for users."></head>',
                '<head><title>Test</title><meta name="description" content="This is a well-crafted description that provides value to users."></head>',
            ],
        ]);
    });

    it('does not apply static length limits to a description rendered at runtime', function (): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'valid' => [
                '<head><meta name="description" content="{{ $description }}"></head>',
                '<head><meta name="description" :content="description"></head>',
            ],
        ]);
    });

    it('passes for partial templates without head', function (): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'valid' => [
                '<div>Partial content</div>',
                '<section>Content</section>',
            ],
        ]);
    });

    it('stays quiet when the head pulls in content it cannot see through', function (): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'valid' => [
                '<head><s:se_meta /></head>',
                "<head>@include('partials.meta')</head>",
                "<head>@stack('meta')</head>",
                '<head>{!! $meta !!}</head>',
            ],
        ]);
    });

    it('still fails when the head content is fully visible', function (): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'invalid' => [
                [
                    'code' => '<head><title>Test</title>{{ $meta }}</head>',
                    'errors' => 1,
                ],
                [
                    'code' => '<head>@if($secure)<meta charset="utf-8">@endif</head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('requires opaque and dynamic providers on every render path', function (string $code): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'opaque include' => "<head>@if(\$custom)@include('meta')@endif</head>",
        'dynamic name' => '<head>@if($custom)<meta name="{{ $name }}" content="Runtime description">@endif</head>',
    ]);

    it('does not count descriptions inside inert templates', function (): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'invalid' => [[
                'code' => '<head><template><meta name="description" content="A long enough description that only belongs to inert template content."></template></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for documents without meta description', function (): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'invalid' => [
                [
                    'code' => '<head><title>Test</title></head>',
                    'errors' => 1,
                ],
                [
                    'code' => '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for empty meta description', function (): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'invalid' => [
                [
                    'code' => '<head><meta name="description" content=""></head>',
                    'errors' => 1,
                ],
                [
                    'code' => '<head><meta name="description" content="   "></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('validates every description at its own location', function (): void {
        $valid = 'A useful description with enough detail to satisfy the configured minimum length.';

        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'invalid' => [[
                'code' => "<head>\n<meta name=\"description\" content=\"\">\n<meta name=\"description\" content=\"{$valid}\">\n<meta name=\"description\" content=\"short\">\n</head>",
                'errors' => [
                    ['line' => 2],
                    ['line' => 4],
                ],
            ]],
        ]);
    });

    it('handles case insensitivity for name attribute', function (): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'valid' => [
                '<head><meta name="Description" content="This is a valid description that meets the minimum length requirement."></head>',
                '<head><meta name="DESCRIPTION" content="This is another valid description that meets the minimum length requirement."></head>',
            ],
        ]);
    });

    it('warns for descriptions that are too short', function (): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'invalid' => [
                [
                    'code' => '<head><meta name="description" content="Too short"></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('warns for descriptions that are too long', function (): void {
        $longDescription = str_repeat('A', 200);
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'invalid' => [
                [
                    'code' => '<head><meta name="description" content="'.$longDescription.'"></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('does not validate captured descriptions at their definition site', function (): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'valid' => [
                '<head>@push("x")<meta name="description" content="">@endpush<meta name="description" content="This is a valid live description with enough useful text for search results."></head>',
            ],
        ]);
    });

    it('accepts valid description content on every attribute render path', function (): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'valid' => [
                '<head><meta name="description" @if($x) content="A complete page description that is comfortably longer than fifty characters." @else content="Another complete page description that is comfortably longer than fifty characters." @endif></head>',
            ],
        ]);
    });

    it('correlates separate attribute conditionals when checking document presence', function (): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'invalid' => [[
                'code' => '<head><meta @if($x) name="description" @else name="author" @endif @if($x) content="A complete page description that is comfortably longer than fifty characters." @else content="" @endif></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('reports split attribute conditionals when every Cartesian path has an invalid description', function (): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'invalid' => [[
                'code' => '<head><meta @if($x) name="description" @else name="description" @endif @if($y) content="" @else content="short" @endif></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('uses exact repeated predicates for split description attributes', function (): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'invalid' => [[
                'code' => '<head><meta @if($x) name="description" @endif @if($x) content="" @endif></head>',
                'errors' => 2,
            ]],
        ]);
    });

    it('validates description content on each attribute render path', function (): void {
        $this->getRuleTester()->run(new MetaDescriptionRule, [
            'invalid' => [[
                'code' => '<head><meta name="description" @if($x) content="A complete page description that is comfortably longer than fifty characters." @else content="" @endif></head>',
                'errors' => 1,
            ]],
        ]);
    });
});
