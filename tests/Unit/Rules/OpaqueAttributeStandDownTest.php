<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Content\AnchorContentRule;
use Forte\Sheath\Rules\Accessibility\Content\ButtonAccessibleNameRule;
use Forte\Sheath\Rules\Accessibility\Content\FormLabelRule;
use Forte\Sheath\Rules\Accessibility\Content\ImgAltTextRule;
use Forte\Sheath\Rules\Accessibility\Content\RequireFrameTitleRule;
use Forte\Sheath\Rules\Accessibility\Structure\HtmlLangRule;
use Forte\Sheath\Rules\BestPractices\Attributes\ButtonTypeRule;
use Forte\Sheath\Rules\BestPractices\Attributes\RequireFormMethodRule;
use Forte\Sheath\Rules\Blade\Helpers\MethodFieldRule;
use Forte\Sheath\Rules\Performance\LazyLoadImagesRule;
use Forte\Sheath\Rules\Performance\RequireExplicitSizeRule;
use Forte\Sheath\Rules\Performance\ResponsiveImagesRule;
use Forte\Sheath\Rules\Security\CsrfFieldRule;
use Forte\Sheath\Rules\Security\NoTargetBlankRule;

describe('missing-attribute rules and opaque attribute sets', function (): void {
    it('stands down when a bag or wrapped attribute may supply the attribute', function (object $rule, string $code): void {
        $this->getRuleTester()->run($rule, ['valid' => [$code]]);
    })->with([
        'button-type: breeze primary button' => [
            new ButtonTypeRule,
            '<button {{ $attributes->merge([\'type\' => \'submit\', \'class\' => \'btn\']) }}>{{ $slot }}</button>',
        ],
        'button-type: bare bag' => [
            new ButtonTypeRule,
            '<button {{ $attributes }}>Go</button>',
        ],
        'form-label: breeze text input' => [
            new FormLabelRule,
            "@props(['disabled' => false])\n\n<input @disabled(\$disabled) {{ \$attributes->merge(['class' => 'border-gray-300']) }}>",
        ],
        'form-label: jetstream input with raw bag' => [
            new FormLabelRule,
            '<input {!! $attributes->merge([\'class\' => \'border-gray-300\']) !!}>',
        ],
        'form-label: select with bag' => [
            new FormLabelRule,
            '<select {{ $attributes }}>{{ $slot }}</select>',
        ],
        'form-label: textarea with bag' => [
            new FormLabelRule,
            '<textarea {{ $attributes->merge([\'class\' => \'x\']) }}></textarea>',
        ],
        'alt-text: img with bag' => [
            new ImgAltTextRule,
            '<img {{ $attributes->merge([\'src\' => $src, \'alt\' => $alt]) }}>',
        ],
        'frame-title: iframe with bag' => [
            new RequireFrameTitleRule,
            '<iframe {{ $attributes }}></iframe>',
        ],
        'form-method: form with bag' => [
            new RequireFormMethodRule,
            '<form {{ $attributes }}>{{ $slot }}</form>',
        ],
        'html-lang: html with bag' => [
            new HtmlLangRule,
            '<html {{ $attributes }}><body></body></html>',
        ],
        'button-name: icon button with bag' => [
            new ButtonAccessibleNameRule,
            '<button {{ $attributes }}><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M1 1"/></svg></button>',
        ],
        'button-name: input button with bag' => [
            new ButtonAccessibleNameRule,
            '<input type="button" {{ $attributes->merge([\'class\' => \'x\']) }}>',
        ],
        'lazy-load: img with bag' => [
            new LazyLoadImagesRule,
            '<img src="/a.png" alt="A"><img {{ $attributes }}>',
        ],
        'explicit-size: img with bag' => [
            new RequireExplicitSizeRule,
            '<img {{ $attributes->merge([\'src\' => \'/a.png\']) }} alt="A">',
        ],
        'responsive: img with bag' => [
            new ResponsiveImagesRule,
            '<img src="/large-hero.png" alt="A" {{ $attributes }}>',
        ],
        'anchor-content: icon link with bag' => [
            new AnchorContentRule,
            '<a {{ $attributes }}><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M1 1"/></svg></a>',
        ],
        'target-blank: bag may carry rel' => [
            new NoTargetBlankRule,
            '<a target="_blank" {{ $attributes }}>{{ $slot }}</a>',
        ],
        'target-blank: merge supplies rel' => [
            new NoTargetBlankRule,
            '<a target="_blank" {{ $attributes->merge([\'rel\' => \'noopener noreferrer\']) }}>{{ $slot }}</a>',
        ],
        'target-blank: if-wrapped rel' => [
            new NoTargetBlankRule,
            '<a href="/x" target="_blank" @if($ext) rel="noopener" @endif>Docs</a>',
        ],
        'button-type: Alpine object binding' => [
            new ButtonTypeRule,
            '<button x-bind="trigger">Go</button>',
        ],
        'form-method: Alpine object binding' => [
            new RequireFormMethodRule,
            '<form x-bind="formBindings"></form>',
        ],
        'button-type: Alpine object binding may replace an earlier explicit type' => [
            new ButtonTypeRule,
            '<button type="bogus" x-bind="trigger">Go</button>',
        ],
        'button-type: Alpine object binding may replace a later explicit type' => [
            new ButtonTypeRule,
            '<button x-bind="trigger" type="bogus">Go</button>',
        ],
        'button-type: Alpine longhand binding' => [
            new ButtonTypeRule,
            '<button x-bind:type="type">Go</button>',
        ],
        'form-method: Alpine longhand binding' => [
            new RequireFormMethodRule,
            '<form x-bind:method="method"></form>',
        ],
        'form-label: Alpine longhand aria-label binding' => [
            new FormLabelRule,
            '<input x-bind:aria-label="label">',
        ],
        'alt-text: Alpine longhand binding' => [
            new ImgAltTextRule,
            '<img src="/a.png" x-bind:alt="alt">',
        ],
        'frame-title: Alpine longhand binding' => [
            new RequireFrameTitleRule,
            '<iframe x-bind:title="title"></iframe>',
        ],
        'html-lang: Alpine longhand binding' => [
            new HtmlLangRule,
            '<html x-bind:lang="locale"></html>',
        ],
        'button-name: Alpine longhand aria-label binding' => [
            new ButtonAccessibleNameRule,
            '<button x-bind:aria-label="label"></button>',
        ],
        'anchor-content: Alpine longhand aria-label binding' => [
            new AnchorContentRule,
            '<a href="/" x-bind:aria-label="label"></a>',
        ],
        'lazy-load: Alpine longhand binding' => [
            new LazyLoadImagesRule,
            '<img src="/hero.png" alt="Hero"><img src="/a.png" alt="A" x-bind:loading="loading">',
        ],
        'explicit-size: Alpine longhand bindings' => [
            new RequireExplicitSizeRule,
            '<img src="/a.png" alt="A" x-bind:width="width" x-bind:height="height">',
        ],
        'responsive: Alpine longhand binding' => [
            new ResponsiveImagesRule,
            '<img src="/large-hero.png" alt="A" x-bind:srcset="srcset">',
        ],
        'button-type: Alpine shorthand binding' => [
            new ButtonTypeRule,
            '<button :type="type">Go</button>',
        ],
        'form-method: Alpine shorthand binding' => [
            new RequireFormMethodRule,
            '<form :method="method"></form>',
        ],
        'form-label: Alpine shorthand aria-label binding' => [
            new FormLabelRule,
            '<input :aria-label="label">',
        ],
        'alt-text: Alpine shorthand binding' => [
            new ImgAltTextRule,
            '<img src="/a.png" :alt="alt">',
        ],
        'frame-title: Alpine shorthand binding' => [
            new RequireFrameTitleRule,
            '<iframe :title="title"></iframe>',
        ],
        'html-lang: Alpine shorthand binding' => [
            new HtmlLangRule,
            '<html :lang="locale"></html>',
        ],
        'button-name: Alpine shorthand aria-label binding' => [
            new ButtonAccessibleNameRule,
            '<button :aria-label="label"></button>',
        ],
        'anchor-content: Alpine shorthand aria-label binding' => [
            new AnchorContentRule,
            '<a href="/" :aria-label="label"></a>',
        ],
        'lazy-load: Alpine shorthand binding' => [
            new LazyLoadImagesRule,
            '<img src="/hero.png" alt="Hero"><img src="/a.png" alt="A" :loading="loading">',
        ],
        'explicit-size: Alpine shorthand bindings' => [
            new RequireExplicitSizeRule,
            '<img src="/a.png" alt="A" :width="width" :height="height">',
        ],
        'responsive: Alpine shorthand binding' => [
            new ResponsiveImagesRule,
            '<img src="/large-hero.png" alt="A" :srcset="srcset">',
        ],
        'button-type: Livewire longhand binding' => [
            new ButtonTypeRule,
            '<button wire:bind:type="type">Go</button>',
        ],
        'form-method: Livewire longhand binding' => [
            new RequireFormMethodRule,
            '<form wire:bind:method="method"></form>',
        ],
        'form-label: Livewire longhand aria-label binding' => [
            new FormLabelRule,
            '<input wire:bind:aria-label="label">',
        ],
        'alt-text: Livewire longhand binding' => [
            new ImgAltTextRule,
            '<img src="/a.png" wire:bind:alt="alt">',
        ],
        'frame-title: Livewire longhand binding' => [
            new RequireFrameTitleRule,
            '<iframe wire:bind:title="title"></iframe>',
        ],
        'html-lang: Livewire longhand binding' => [
            new HtmlLangRule,
            '<html wire:bind:lang="locale"></html>',
        ],
        'button-name: Livewire longhand aria-label binding' => [
            new ButtonAccessibleNameRule,
            '<button wire:bind:aria-label="label"></button>',
        ],
        'anchor-content: Livewire longhand aria-label binding' => [
            new AnchorContentRule,
            '<a href="/" wire:bind:aria-label="label"></a>',
        ],
        'lazy-load: Livewire longhand binding' => [
            new LazyLoadImagesRule,
            '<img src="/hero.png" alt="Hero"><img src="/a.png" alt="A" wire:bind:loading="loading">',
        ],
        'explicit-size: Livewire longhand bindings' => [
            new RequireExplicitSizeRule,
            '<img src="/a.png" alt="A" wire:bind:width="width" wire:bind:height="height">',
        ],
        'responsive: Livewire longhand binding' => [
            new ResponsiveImagesRule,
            '<img src="/large-hero.png" alt="A" wire:bind:srcset="srcset">',
        ],
        'target-blank: Livewire longhand rel binding' => [
            new NoTargetBlankRule,
            '<a href="/" target="_blank" wire:bind:rel="rel">Docs</a>',
        ],
    ]);

    it('does not stand down for client bindings that x-ignore prevents from running', function (object $rule, string $code): void {
        $this->getRuleTester()->run($rule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'button type from ignored Alpine object' => [new ButtonTypeRule, '<button x-ignore x-bind="trigger">Go</button>'],
        'button type from ignored Alpine binding' => [new ButtonTypeRule, '<button x-ignore x-bind:type="type">Go</button>'],
        'button type from ignored Alpine shorthand' => [new ButtonTypeRule, '<button x-ignore :type="type">Go</button>'],
        'button type below ignored Livewire binding' => [new ButtonTypeRule, '<div x-ignore><button wire:bind:type="type">Go</button></div>'],
        'form method from ignored Alpine binding' => [new RequireFormMethodRule, '<form x-ignore x-bind:method="method"></form>'],
        'label from ignored Alpine binding' => [new FormLabelRule, '<input x-ignore x-bind:aria-label="label">'],
        'alt from ignored Alpine binding' => [new ImgAltTextRule, '<img x-ignore src="/a.png" x-bind:alt="alt">'],
        'frame title from ignored Alpine binding' => [new RequireFrameTitleRule, '<iframe x-ignore x-bind:title="title"></iframe>'],
        'document language below ignored Alpine binding' => [new HtmlLangRule, '<html x-ignore x-bind:lang="locale"></html>'],
        'button name below ignored Livewire binding' => [new ButtonAccessibleNameRule, '<div x-ignore><button wire:bind:aria-label="label"></button></div>'],
        'anchor name from ignored Alpine binding' => [new AnchorContentRule, '<a x-ignore href="/" x-bind:aria-label="label"></a>'],
        'image loading from ignored Alpine binding' => [new LazyLoadImagesRule, '<img src="/hero.png" alt="Hero"><img x-ignore src="/a.png" alt="A" x-bind:loading="loading">'],
        'image size from ignored Alpine bindings' => [new RequireExplicitSizeRule, '<img x-ignore src="/a.png" alt="A" x-bind:width="width" x-bind:height="height">'],
        'responsive source from ignored Alpine binding' => [new ResponsiveImagesRule, '<img x-ignore src="/large-hero.png" alt="A" x-bind:srcset="srcset">'],
        'target blank rel from ignored Alpine binding' => [new NoTargetBlankRule, '<a x-ignore href="/" target="_blank" rel="opener" x-bind:rel="rel">Docs</a>'],
    ]);

    it('still reports when only self-contained directives are present', function (object $rule, string $code): void {
        $this->getRuleTester()->run($rule, [
            'invalid' => [['code' => $code, 'errors' => 1]],
        ]);
    })->with([
        'button-type: disabled directive cannot supply type' => [
            new ButtonTypeRule,
            '<button @disabled($locked)>Go</button>',
        ],
        'button-type: alpine event is not a bag' => [
            new ButtonTypeRule,
            '<button @click="open = !open" aria-label="Toggle">Go</button>',
        ],
        'button-type: unrelated Alpine binding cannot supply type' => [
            new ButtonTypeRule,
            '<button x-bind:class="classes">Go</button>',
        ],
        'button-type: unrelated Livewire binding cannot supply type' => [
            new ButtonTypeRule,
            '<button wire:bind:class="classes">Go</button>',
        ],
        'form-label: disabled directive cannot supply a label' => [
            new FormLabelRule,
            '<input type="text" name="q" @disabled($locked)>',
        ],
        'alt-text: class directive cannot supply alt' => [
            new ImgAltTextRule,
            '<img src="/a.png" @class([\'rounded\' => $round])>',
        ],
        'anchor-content: class directive cannot supply a label' => [
            new AnchorContentRule,
            '<a href="/x" @class([\'active\' => $active])></a>',
        ],
        'target-blank: class directive cannot neutralize opener' => [
            new NoTargetBlankRule,
            '<a href="/x" target="_blank" rel="opener" @class([\'ext\' => $ext])>Docs</a>',
        ],
        'target-blank: explicit opener is reported beside a bag' => [
            new NoTargetBlankRule,
            '<a target="_blank" rel="opener" {{ $attributes }}>Docs</a>',
        ],
    ]);
});

describe('form rules and opaque form content', function (): void {
    it('stands down when the form body only exists at runtime', function (object $rule, string $code): void {
        $this->getRuleTester()->run($rule, ['valid' => [$code]]);
    })->with([
        'csrf: slot' => [
            new CsrfFieldRule,
            '<form method="POST" action="{{ $action }}">{{ $slot }}</form>',
        ],
        'csrf: slot beside a bag' => [
            new CsrfFieldRule,
            '<form method="POST" {{ $attributes }}>{{ $slot }}</form>',
        ],
        'csrf: include' => [
            new CsrfFieldRule,
            "<form method=\"POST\" action=\"/save\">@include('partials.fields')</form>",
        ],
        'csrf: nested component' => [
            new CsrfFieldRule,
            '<form method="POST" action="/save"><x-form.fields /></form>',
        ],
        'csrf: raw echo' => [
            new CsrfFieldRule,
            '<form method="POST" action="/save">{!! $fields !!}</form>',
        ],
        'method-field: slot' => [
            new MethodFieldRule,
            '<form method="PUT" action="{{ $action }}">{{ $slot }}</form>',
        ],
    ]);

    it('still reports when the form body is fully visible', function (object $rule, string $code): void {
        $this->getRuleTester()->run($rule, [
            'invalid' => [['code' => $code, 'errors' => 1]],
        ]);
    })->with([
        'csrf: literal inputs only' => [
            new CsrfFieldRule,
            '<form method="POST" action="/save"><input type="text" name="q" aria-label="Query"><button type="submit">Go</button></form>',
        ],
        'csrf: an ordinary echo is not a slot' => [
            new CsrfFieldRule,
            '<form method="POST" action="/save"><p>{{ $message }}</p><button type="submit">Go</button></form>',
        ],
        'method-field: literal inputs only' => [
            new MethodFieldRule,
            '<form method="PUT" action="/save"><button type="submit">Go</button></form>',
        ],
    ]);
});
