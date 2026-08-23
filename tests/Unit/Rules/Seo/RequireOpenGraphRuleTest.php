<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Seo\RequireOpenGraphRule;

describe('RequireOpenGraphRule', function (): void {
    it('passes for pages with all required OG tags', function (): void {
        $this->getRuleTester()->run(new RequireOpenGraphRule, [
            'valid' => [
                '<head>
                    <meta property="og:title" content="Page Title">
                    <meta property="og:type" content="website">
                    <meta property="og:image" content="https://example.com/image.jpg">
                    <meta property="og:url" content="https://example.com/page">
                </head>',
            ],
        ]);
    });

    it('accepts required properties and content on every opening-tag render path', function (): void {
        $this->getRuleTester()->run(new RequireOpenGraphRule, [
            'valid' => ['<head>
                <meta @if($page) property="og:title" @else property="og:title" @endif content="Page Title">
                <meta property="og:type" @if($page) content="website" @else content="article" @endif>
                <meta property="og:image" content="image.jpg">
                <meta property="og:url" content="/page">
            </head>'],
            'invalid' => [[
                'code' => '<head>
                    <meta @if($page) property="og:title" @else property="og:title" @endif @if($page) content="Page Title" @endif>
                    <meta property="og:type" content="website">
                    <meta property="og:image" content="image.jpg">
                    <meta property="og:url" content="/page">
                </head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for partial templates without head', function (): void {
        $this->getRuleTester()->run(new RequireOpenGraphRule, [
            'valid' => [
                '<div>Content</div>',
                '<section><h1>Title</h1></section>',
            ],
        ]);
    });

    it('passes with additional OG tags', function (): void {
        $this->getRuleTester()->run(new RequireOpenGraphRule, [
            'valid' => [
                '<head>
                    <meta property="og:title" content="Page Title">
                    <meta property="og:type" content="website">
                    <meta property="og:image" content="https://example.com/image.jpg">
                    <meta property="og:url" content="https://example.com/page">
                    <meta property="og:description" content="Description">
                    <meta property="og:site_name" content="Site Name">
                </head>',
            ],
        ]);
    });

    it('stays quiet when the head pulls in content it cannot see through', function (): void {
        $this->getRuleTester()->run(new RequireOpenGraphRule, [
            'valid' => [
                '<head><s:se_meta /></head>',
                "<head>@include('partials.meta')</head>",
                "<head>@stack('meta')</head>",
                '<head>{!! $meta !!}</head>',
            ],
        ]);
    });

    it('still fails when the head content is fully visible', function (): void {
        $this->getRuleTester()->run(new RequireOpenGraphRule, [
            'invalid' => [
                [
                    'code' => '<head><title>Page</title>{{ $meta }}</head>',
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
        $this->getRuleTester()->run(new RequireOpenGraphRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'opaque include' => "<head>@if(\$custom)@include('open-graph')@endif</head>",
        'dynamic property' => '<head>@if($custom)<meta property="{{ $property }}" content="Runtime value">@endif</head>',
    ]);

    it('does not count Open Graph metadata inside inert templates', function (): void {
        $this->getRuleTester()->run(new RequireOpenGraphRule, [
            'invalid' => [[
                'code' => '<head><template>
                    <meta property="og:title" content="Page Title">
                    <meta property="og:type" content="website">
                    <meta property="og:image" content="image.jpg">
                    <meta property="og:url" content="/page">
                </template></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails when all OG tags are missing', function (): void {
        $this->getRuleTester()->run(new RequireOpenGraphRule, [
            'invalid' => [
                [
                    'code' => '<head><title>Page</title></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails when some OG tags are missing', function (): void {
        $this->getRuleTester()->run(new RequireOpenGraphRule, [
            'invalid' => [
                [
                    'code' => '<head>
                        <meta property="og:title" content="Title">
                        <meta property="og:type" content="website">
                    </head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails when only og:title is present', function (): void {
        $this->getRuleTester()->run(new RequireOpenGraphRule, [
            'invalid' => [
                [
                    'code' => '<head><meta property="og:title" content="Title"></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('treats missing and empty content values as missing metadata', function (): void {
        $this->getRuleTester()->run(new RequireOpenGraphRule, [
            'invalid' => [[
                'code' => '<head>
                    <meta property="og:title">
                    <meta property="og:type" content="">
                    <meta property="og:image" content=" ">
                    <meta property="og:url" content="   ">
                </head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('accepts a usable duplicate declaration', function (): void {
        $this->getRuleTester()->run(new RequireOpenGraphRule, [
            'valid' => [
                '<head>
                    <meta property="og:title" content="">
                    <meta property="og:title" content="Title">
                    <meta property="og:type" content="website">
                    <meta property="og:image" content="image.jpg">
                    <meta property="og:url" content="url">
                </head>',
            ],
        ]);
    });

    it('stands down for content supplied dynamically', function (): void {
        $this->getRuleTester()->run(new RequireOpenGraphRule, [
            'valid' => [
                '<head>
                    <meta property="og:title" content="{{ $title }}">
                    <meta property="og:type" :content="type">
                    <meta property="og:image" {{ $imageAttributes }}>
                    <meta property="og:url" content="{{ $url }}">
                </head>',
            ],
        ]);
    });

    it('is case insensitive for property names', function (): void {
        $this->getRuleTester()->run(new RequireOpenGraphRule, [
            'valid' => [
                '<head>
                    <meta property="OG:TITLE" content="Title">
                    <meta property="og:Type" content="website">
                    <meta property="OG:Image" content="image.jpg">
                    <meta property="og:URL" content="url">
                </head>',
            ],
        ]);
    });

    it('recognizes Open Graph predicates in RDFa property token lists', function (): void {
        $this->getRuleTester()->run(new RequireOpenGraphRule, [
            'valid' => [
                '<head>
                    <meta property="og:title schema:name" content="Title">
                    <meta property="schema:additionalType og:type" content="website">
                    <meta property="og:image schema:image" content="image.jpg">
                    <meta property="schema:url og:url" content="url">
                </head>',
            ],
        ]);
    });

    it('checks for recommended properties when enabled', function (): void {
        $rule = new RequireOpenGraphRule;
        $rule->setOptions(['checkRecommended' => true]);

        $this->getRuleTester()->run($rule, [
            'invalid' => [
                [
                    'code' => '<head>
                        <meta property="og:title" content="Title">
                        <meta property="og:type" content="website">
                        <meta property="og:image" content="image.jpg">
                        <meta property="og:url" content="url">
                    </head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('passes recommended check when all present', function (): void {
        $rule = new RequireOpenGraphRule;
        $rule->setOptions(['checkRecommended' => true]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<head>
                    <meta property="og:title" content="Title">
                    <meta property="og:type" content="website">
                    <meta property="og:image" content="image.jpg">
                    <meta property="og:url" content="url">
                    <meta property="og:description" content="Description">
                    <meta property="og:site_name" content="Site">
                </head>',
            ],
        ]);
    });

    it('requires usable content for configured recommended properties', function (): void {
        $rule = new RequireOpenGraphRule;
        $rule->setOptions(['checkRecommended' => true]);

        $this->getRuleTester()->run($rule, [
            'invalid' => [[
                'code' => '<head>
                    <meta property="og:title" content="Title">
                    <meta property="og:type" content="website">
                    <meta property="og:image" content="image.jpg">
                    <meta property="og:url" content="url">
                    <meta property="og:description" content="">
                    <meta property="og:site_name">
                </head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('checks for image properties when enabled', function (): void {
        $rule = new RequireOpenGraphRule;
        $rule->setOptions(['checkImageProperties' => true]);

        $this->getRuleTester()->run($rule, [
            'invalid' => [
                [
                    'code' => '<head>
                        <meta property="og:title" content="Title">
                        <meta property="og:type" content="website">
                        <meta property="og:image" content="image.jpg">
                        <meta property="og:url" content="url">
                    </head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('passes image check when all image properties present', function (): void {
        $rule = new RequireOpenGraphRule;
        $rule->setOptions(['checkImageProperties' => true]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<head>
                    <meta property="og:title" content="Title">
                    <meta property="og:type" content="website">
                    <meta property="og:image" content="image.jpg">
                    <meta property="og:url" content="url">
                    <meta property="og:image:width" content="1200">
                    <meta property="og:image:height" content="630">
                    <meta property="og:image:alt" content="Image description">
                </head>',
            ],
        ]);
    });

    it('requires usable content for configured image properties', function (): void {
        $rule = new RequireOpenGraphRule;
        $rule->setOptions(['checkImageProperties' => true]);

        $this->getRuleTester()->run($rule, [
            'invalid' => [[
                'code' => '<head>
                    <meta property="og:title" content="Title">
                    <meta property="og:type" content="website">
                    <meta property="og:image" content="image.jpg">
                    <meta property="og:url" content="url">
                    <meta property="og:image:width" content="">
                    <meta property="og:image:height" content=" ">
                    <meta property="og:image:alt">
                </head>',
                'errors' => 1,
            ]],
        ]);
    });
});
