<?php

declare(strict_types=1);

use Forte\Sheath\Rules\BestPractices\Documents\RequireMetaViewportRule;

describe('RequireMetaViewportRule', function (): void {
    it('uses explicit viewport attributes before a later opaque provider', function (): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, [
            'valid' => ['<head><meta {{ $attributes }} name="viewport" content=""></head>'],
            'invalid' => [[
                'code' => '<head><meta name="viewport" content="" {{ $attributes }}></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for documents with viewport meta tag', function (string $code): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, ['valid' => [$code]]);
    })->with([
        'full viewport' => '<head><meta name="viewport" content="width=device-width, initial-scale=1"></head>',
        'width only' => '<head><meta name="viewport" content="width=device-width"></head>',
        'case insensitive' => '<head><meta name="Viewport" content="width=device-width"></head>',
        'numeric width' => '<head><meta name="viewport" content="width=1024"></head>',
        'runtime content' => '<head><meta name="viewport" content="{{ $viewport }}"></head>',
        'bound content' => '<head><meta name="viewport" :content="viewport"></head>',
        'uppercase tags' => '<HEAD><META name="viewport" content="width=device-width"></HEAD>',
    ]);

    it('passes for documents without head tag (partial templates)', function (string $code): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, ['valid' => [$code]]);
    })->with([
        'div' => '<div>Content</div>',
        'paragraph' => '<p>Paragraph</p>',
        'section' => '<section><h1>Title</h1></section>',
    ]);

    it('fails for documents with head but no viewport', function (string $code): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'head with title only' => '<head><title>Test</title></head>',
        'empty head' => '<head></head>',
        'head with other meta tags' => '<head><meta charset="utf-8"><meta name="description" content="Test"></head>',
        'uppercase head without viewport' => '<HEAD><TITLE>Test</TITLE></HEAD>',
    ]);

    it('stays quiet when the head pulls in content it cannot see through', function (string $code): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, ['valid' => [$code]]);
    })->with([
        'namespaced component tag' => '<head><s:se_meta /></head>',
        'include directive' => "<head>@include('partials.meta')</head>",
        'stack directive' => "<head>@stack('meta')</head>",
        'raw echo' => '<head>{!! $meta !!}</head>',
    ]);

    it('still fails when the head content is fully visible', function (string $code): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'escaped echo only' => '<head><title>Test</title>{{ $meta }}</head>',
        'if wrapping other metas' => '<head>@if($secure)<meta charset="utf-8">@endif</head>',
    ]);

    it('validates viewport content', function (string $code): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, [
            'invalid' => [['code' => $code, 'errors' => 1]],
        ]);
    })->with([
        'missing width' => [
            '<head><meta name="viewport" content="initial-scale=1"></head>',
        ],
        'invalid width value' => [
            '<head><meta name="viewport" content="width=invalid"></head>',
        ],
        'invalid initial-scale' => [
            '<head><meta name="viewport" content="width=device-width, initial-scale=100"></head>',
        ],
        'invalid maximum-scale' => [
            '<head><meta name="viewport" content="width=device-width, maximum-scale=0.01"></head>',
        ],
        'empty content' => [
            '<head><meta name="viewport" content=""></head>',
        ],
    ]);

    it('can disable content validation', function (): void {
        $rule = new RequireMetaViewportRule;
        $rule->setOptions(['validateContent' => false]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<head><meta name="viewport" content=""></head>',
            ],
        ]);
    });

    it('does not treat vertical tab as viewport whitespace', function (string $code): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'before property' => [
            "<head><meta name=\"viewport\" content=\"\x0Bwidth=device-width,initial-scale=1\"></head>",
        ],
        'before property value' => [
            "<head><meta name=\"viewport\" content=\"width=\x0Bdevice-width,initial-scale=1\"></head>",
        ],
    ]);

    it('uses viewport numeric-prefix parsing for dimensions and scales', function (): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, [
            'valid' => ['<head><meta name="viewport" content="width=320px,initial-scale=1foo,minimum-scale=1bar,maximum-scale=2baz"></head>'],
        ]);
    });

    it('validates every conditional viewport regardless of source order', function (string $code): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'empty viewport in first branch' => '<head>@if($x)<meta name="viewport" content="">@else<meta name="viewport" content="width=device-width">@endif</head>',
        'empty viewport in second branch' => '<head>@if($x)<meta name="viewport" content="width=device-width">@else<meta name="viewport" content="">@endif</head>',
        'empty viewport in switch case' => '<head>@switch($x)@case(1)<meta name="viewport" content="">@break @default<meta name="viewport" content="width=device-width">@endswitch</head>',
    ]);

    it('continues validating static viewports when another branch is dynamic', function (): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, [
            'invalid' => [[
                'code' => '<head>@if($x)<meta name="viewport" content="{{ $viewport }}">@else<meta name="viewport" content="">@endif</head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('uses exact repeated predicates for split viewport attributes', function (): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, [
            'invalid' => [[
                'code' => '<head><meta @if($x) name="viewport" @endif @if($x) content="" @endif></head>',
                'errors' => 2,
            ]],
        ]);
    });

    it('validates a visible viewport when presence is indeterminate', function (): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, [
            'invalid' => [[
                'code' => '<head><meta name="{{ $name }}" content="{{ $value }}"><meta name="viewport" content=""></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('ignores viewport declarations inside inert templates', function (): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, [
            'invalid' => [[
                'code' => '<head><template><meta name="viewport" content="width=device-width"></template></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('does not validate non-viewport meta names after PHP whitespace trimming', function (): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, [
            'invalid' => [[
                'code' => "<head><meta name=\"\x0Bviewport\" content=\"\"></head>",
                'errors' => 1,
            ]],
        ]);
    });

    it('accepts valid viewport content on every attribute render path', function (): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, [
            'valid' => [
                '<head><meta name="viewport" @if($x) content="width=device-width, initial-scale=1" @else content="width=device-width, initial-scale=2" @endif></head>',
            ],
        ]);
    });

    it('correlates separate attribute conditionals when checking document presence', function (): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, [
            'invalid' => [[
                'code' => '<head><meta @if($x) name="viewport" @else name="theme-color" @endif @if($x) content="width=device-width, initial-scale=1" @else content="" @endif></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('reports split attribute conditionals when every Cartesian path has invalid viewport content', function (): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, [
            'invalid' => [[
                'code' => '<head><meta @if($x) name="viewport" @else name="viewport" @endif @if($y) content="" @else content="initial-scale=1" @endif></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('validates viewport content on each attribute render path', function (): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, [
            'invalid' => [[
                'code' => '<head><meta name="viewport" @if($x) content="width=device-width" @else content="" @endif></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('requires a viewport name on every attribute render path', function (): void {
        $this->getRuleTester()->run(new RequireMetaViewportRule, [
            'invalid' => [[
                'code' => '<head><meta @if($x) name="viewport" content="width=device-width" @else name="theme-color" content="dark" @endif></head>',
                'errors' => 1,
            ]],
        ]);
    });
});
