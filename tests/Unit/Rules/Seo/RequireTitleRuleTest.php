<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Seo\RequireTitleRule;

describe('RequireTitleRule', function (): void {
    it('passes for documents with title', function (): void {
        $this->getRuleTester()->run(new RequireTitleRule, [
            'valid' => [
                '<head><title>Page Title</title></head>',
                '<!DOCTYPE html><html><head><title>My Site</title></head></html>',
                '<HEAD><TITLE>Page Title</TITLE></HEAD>',
            ],
        ]);
    });

    it('passes for partial templates without head', function (): void {
        $this->getRuleTester()->run(new RequireTitleRule, [
            'valid' => [
                '<div>Partial content</div>',
                '<x-component>Content</x-component>',
            ],
        ]);
    });

    it('stays quiet when the head pulls in content it cannot see through', function (): void {
        $this->getRuleTester()->run(new RequireTitleRule, [
            'valid' => [
                '<head><s:se_meta /></head>',
                "<head>@include('partials.meta')</head>",
                "<head>@stack('meta')</head>",
                '<head>{!! $meta !!}</head>',
            ],
        ]);
    });

    it('still fails when the head content is fully visible', function (): void {
        $this->getRuleTester()->run(new RequireTitleRule, [
            'invalid' => [
                [
                    'code' => '<head><meta charset="utf-8">{{ $meta }}</head>',
                    'errors' => 1,
                ],
                [
                    'code' => '<head>@if($secure)<meta charset="utf-8">@endif</head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('requires an opaque title provider on every render path', function (): void {
        $this->getRuleTester()->run(new RequireTitleRule, [
            'invalid' => [[
                'code' => '<head>@if($custom)<s:se_meta />@endif</head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('does not count titles inside inert templates', function (): void {
        $this->getRuleTester()->run(new RequireTitleRule, [
            'invalid' => [[
                'code' => '<head><template><title>Template title</title></template></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for documents without title', function (): void {
        $this->getRuleTester()->run(new RequireTitleRule, [
            'invalid' => [
                [
                    'code' => '<head><meta charset="utf-8"></head>',
                    'errors' => 1,
                ],
                [
                    'code' => '<HEAD><META charset="utf-8"></HEAD>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for empty title', function (): void {
        $this->getRuleTester()->run(new RequireTitleRule, [
            'invalid' => [
                [
                    'code' => '<head><title></title></head>',
                    'errors' => 1,
                ],
                [
                    'code' => '<head><title>   </title></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('validates the title in every conditional branch regardless of source order', function (string $code): void {
        $this->getRuleTester()->run(new RequireTitleRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'empty title in second branch' => '<head>@if($x)<title>Good</title>@else<title></title>@endif</head>',
        'empty title in first branch' => '<head>@if($x)<title></title>@else<title>Good</title>@endif</head>',
        'empty title in switch default' => '<head>@switch($x)@case(1)<title>Good</title>@break @default<title></title>@endswitch</head>',
    ]);

    it('validates a visible title even when another render path has no title', function (): void {
        $this->getRuleTester()->run(new RequireTitleRule, [
            'invalid' => [[
                'code' => '<head>@if($x)<title></title>@endif</head>',
                'errors' => 2,
            ]],
        ]);
    });

    it('does not validate captured titles at their definition site', function (): void {
        $this->getRuleTester()->run(new RequireTitleRule, [
            'valid' => [
                '<head>@push("x")<title></title>@endpush<title>Live</title></head>',
            ],
        ]);
    });
});
