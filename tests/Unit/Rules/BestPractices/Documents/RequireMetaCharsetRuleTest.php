<?php

declare(strict_types=1);

use Forte\Sheath\Rules\BestPractices\Documents\RequireMetaCharsetRule;

describe('RequireMetaCharsetRule', function (): void {
    it('passes for documents with charset meta tag', function (): void {
        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'valid' => [
                '<head><meta charset="utf-8"></head>',
                '<head><meta charset="UTF-8"></head>',
                "\xEF\xBB\xBF<head><meta charset=\"utf-8\"></head>",
            ],
        ]);
    });

    it('passes for documents with legacy charset declaration', function (): void {
        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'valid' => [
                '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>',
                '<head><meta http-equiv="content-type" content="text/html; charset=UTF-8"></head>',
            ],
        ]);
    });

    it('recognizes exhaustive conditional charset attributes', function (): void {
        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'valid' => [
                '<head><meta @if($x) charset="utf-8" @else charset="utf-8" @endif></head>',
                '<head><meta @if($x) http-equiv="content-type" content="text/html; charset=utf-8" @else charset="utf-8" @endif></head>',
                '<head><meta @if($x) charset="utf-8" @endif @if(!$x) charset="utf-8" @endif></head>',
            ],
        ]);
    });

    it('passes for documents without head tag (partial templates)', function (): void {
        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'valid' => [
                '<div>Content</div>',
                '<p>Paragraph</p>',
                '<section><h1>Title</h1></section>',
            ],
        ]);
    });

    it('stays quiet when the head pulls in content it cannot see through', function (): void {
        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'valid' => [
                '<head><s:se_meta /></head>',
                "<head>@include('partials.meta')</head>",
                "<head>@stack('meta')</head>",
                '<head>{!! $meta !!}</head>',
            ],
        ]);
    });

    it('still fails when the head content is fully visible', function (): void {
        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'invalid' => [
                [
                    'code' => '<head><title>Test</title>{{ $meta }}</head>',
                    'errors' => 1,
                ],
                [
                    'code' => '<head>@if($secure)<meta name="referrer" content="no-referrer">@endif</head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for documents with head but no charset', function (): void {
        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'invalid' => [
                [
                    'code' => '<head><title>Test</title></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for documents with empty head', function (): void {
        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'invalid' => [
                [
                    'code' => '<head></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for documents with meta but no charset', function (): void {
        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'invalid' => [
                [
                    'code' => '<head><meta name="viewport" content="width=device-width"></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('rejects empty modern and legacy charset declarations', function (string $code): void {
        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'empty charset' => '<head><meta charset=""></head>',
        'bare charset' => '<head><meta charset></head>',
        'empty legacy charset' => '<head><meta http-equiv="Content-Type" content="text/html; charset="></head>',
    ]);

    it('rejects non-conforming modern and legacy charset declarations', function (string $code): void {
        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'non-UTF-8 encoding' => '<head><meta charset="iso-8859-1"></head>',
        'unknown encoding' => '<head><meta charset="totally-invalid"></head>',
        'surrounding whitespace' => '<head><meta charset=" utf-8 "></head>',
        'wrong legacy media type' => '<head><meta http-equiv="content-type" content="application/json; charset=utf-8"></head>',
        'wrong legacy encoding' => '<head><meta http-equiv="content-type" content="text/html; charset=iso-8859-1"></head>',
        'spaces around legacy equals' => '<head><meta http-equiv="content-type" content="text/html; charset = utf-8"></head>',
        'legacy trailing content' => '<head><meta http-equiv="content-type" content="text/html; charset=utf-8; foo=bar"></head>',
    ]);

    it('rejects charset declarations serialized after the first 1024 bytes', function (): void {
        $padding = '<!--'.str_repeat('x', 1024).'-->';

        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'invalid' => [[
                'code' => "<head>{$padding}<meta charset=\"utf-8\"></head>",
                'errors' => 1,
            ]],
        ]);
    });

    it('does not count Blade comments toward serialized charset position', function (): void {
        $bladeComment = '{{--'.str_repeat('x', 1100).'--}}';

        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'valid' => [
                "<head>{$bladeComment}<meta charset=\"utf-8\"></head>",
            ],
        ]);
    });

    it('ignores charset declarations inside inert templates', function (): void {
        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'invalid' => [[
                'code' => '<head><template><meta charset="utf-8"></template></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('does not count non-output PHP toward serialized charset position', function (): void {
        $php = '@php $padding = \''.str_repeat('x', 1100).'\'; @endphp';

        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'valid' => ["<head>{$php}<meta charset=\"utf-8\"></head>"],
        ]);
    });

    it('does not count inline PHP source toward the serialized charset position', function (): void {
        $assignment = "\$value = '".str_repeat('a', 1000)."'";

        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'valid' => [
                '<!doctype html><html><head>@php('.$assignment.')<meta charset="utf-8"></head><body></body></html>',
            ],
        ]);
    });

    it('does not count control-directive syntax toward the serialized charset position', function (): void {
        $condition = "'".str_repeat('a', 1000)."' === 'a'";

        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'valid' => [
                '<!doctype html><html><head>@if('.$condition.')@endif<meta charset="utf-8"></head><body></body></html>',
            ],
        ]);
    });

    it('does not count capture-only Blade blocks toward serialized charset position', function (string $capture): void {
        $this->getRuleTester()->run(new RequireMetaCharsetRule, [
            'valid' => [
                '<head>'.$capture.'<meta charset="utf-8"></head>',
            ],
        ]);
    })->with([
        'section' => "@section('x')".str_repeat('x', 1100)."\n@endsection",
        'push' => "@push('x')".str_repeat('x', 1100)."\n@endpush",
    ]);
});
