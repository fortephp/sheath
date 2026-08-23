<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueHeadContent;

describe('DetectsOpaqueHeadContent', function (): void {
    $detect = function (string $template): bool {
        $detector = new class
        {
            use DetectsOpaqueHeadContent;

            public function detect(ElementNode $head): bool
            {
                return $this->headContainsOpaqueContent($head);
            }
        };

        $head = Document::parse($template)->findElementsByName('head')->first();

        expect($head)->toBeInstanceOf(ElementNode::class);

        /** @var ElementNode $head */
        return $detector->detect($head);
    };

    it('treats content the linter cannot see through as opaque', function (string $template) use ($detect): void {
        expect($detect($template))->toBeTrue();
    })->with([
        'namespaced custom tag' => '<head><s:se_meta /></head>',
        'component tag' => '<head><x-seo /></head>',
        'livewire tag' => '<head><livewire:seo-meta /></head>',
        'include directive' => "<head>@include('partials.meta')</head>",
        'includeIf directive' => "<head>@includeIf('partials.meta')</head>",
        'includeWhen directive' => "<head>@includeWhen(\$seo, 'partials.meta')</head>",
        'includeUnless directive' => "<head>@includeUnless(\$plain, 'partials.meta')</head>",
        'includeFirst directive' => "<head>@includeFirst(['custom.meta', 'partials.meta'])</head>",
        'each directive' => "<head>@each('partials.meta', \$tags, 'tag')</head>",
        'yield directive' => "<head>@yield('meta')</head>",
        'stack directive' => "<head>@stack('meta')</head>",
        'raw echo' => '<head>{!! $meta !!}</head>',
        'opaque content nested in control flow' => "<head>@if(\$seo)@include('partials.meta')@endif</head>",
        'opaque content nested in an element' => '<head><noscript><x-fallback-meta /></noscript></head>',
    ]);

    it('treats content the linter can fully inspect as visible', function (string $template) use ($detect): void {
        expect($detect($template))->toBeFalse();
    })->with([
        'empty head' => '<head></head>',
        'static tags' => '<head><meta charset="utf-8"><title>Test</title></head>',
        'escaped echo' => '<head><title>{{ $title }}</title></head>',
        'control flow around static tags' => '<head>@if($secure)<meta name="referrer" content="no-referrer">@endif</head>',
    ]);
});
