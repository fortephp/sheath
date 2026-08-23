<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;

describe('DetectsOpaqueAttributes', function (): void {
    $detect = function (string $template): bool {
        $detector = new class
        {
            use DetectsOpaqueAttributes;

            public function detect(ElementNode $element): bool
            {
                return $this->elementHasOpaqueAttributes($element);
            }
        };

        $element = Document::parse($template)->getElements()->first();

        expect($element)->toBeInstanceOf(ElementNode::class);

        /** @var ElementNode $element */
        return $detector->detect($element);
    };

    it('treats attribute sets the linter cannot see through as opaque', function (string $template) use ($detect): void {
        expect($detect($template))->toBeTrue();
    })->with([
        'attribute bag' => '<button {{ $attributes }}>Go</button>',
        'attribute bag with merge' => '<button {{ $attributes->merge([\'type\' => \'submit\']) }}>Go</button>',
        'raw echo bag' => '<input {!! $attributes->merge([\'class\' => \'x\']) !!}>',
        'any echo in attribute position' => '<div {{ $dataAttributes }}>x</div>',
        'content-emitting directive' => '<div @include(\'partials.attrs\')>x</div>',
        'Alpine object binding' => '<button x-bind="trigger">Go</button>',
    ]);

    it('treats attribute sets it can fully inspect as visible', function (string $template) use ($detect): void {
        expect($detect($template))->toBeFalse();
    })->with([
        'no attributes' => '<button>Go</button>',
        'static attributes' => '<button type="button" data-x="1">Go</button>',
        'if-wrapped attribute' => '<button @if($submit) type="submit" @endif>Go</button>',
        'unless-wrapped attribute' => '<button @unless($plain) type="submit" @endunless>Go</button>',
        'error block around an attribute' => '<input type="text" @error(\'name\') aria-invalid="true" @enderror>',
        'interpolated value' => '<button type="{{ $type }}">Go</button>',
        'class directive' => '<button @class([\'btn\' => $on])>Go</button>',
        'style directive' => '<div @style([\'color: red\' => $err])>x</div>',
        'checked directive' => '<input type="checkbox" @checked($on)>',
        'selected directive' => '<option value="1" @selected($chosen)>One</option>',
        'disabled directive' => '<input type="text" @disabled($locked)>',
        'readonly directive' => '<input type="text" @readonly($frozen)>',
        'required directive' => '<input type="text" @required($must)>',
        'alpine shorthand event' => '<button @click="open = !open">Go</button>',
        'alpine x-on' => '<button x-on:click="open = true">Go</button>',
        'livewire action' => '<button wire:click="save">Go</button>',
        'bound attribute' => '<button :disabled="busy">Go</button>',
        'Alpine specific binding' => '<button x-bind:disabled="busy">Go</button>',
        'Livewire specific binding' => '<button wire:bind:disabled="busy">Go</button>',
        'empty Alpine object binding' => '<button x-bind="">Go</button>',
        'blade comment between attributes' => '<button {{-- keep --}} data-x="1">Go</button>',
    ]);

    it('evaluates known attribute control flow while preserving opaque providers', function (string $template, bool $expected): void {
        $detector = new class
        {
            use DetectsOpaqueAttributes;

            public function satisfied(ElementNode $element): bool
            {
                return $this->everyAttributeRenderPathIsSatisfied($element, 'type');
            }
        };

        $element = Document::parse($template)->getElements()->first();

        expect($element)->toBeInstanceOf(ElementNode::class);

        /** @var ElementNode $element */
        expect($detector->satisfied($element))->toBe($expected);
    })->with([
        'direct attribute' => ['<button type="button">x</button>', true],
        'both if branches' => ['<button @if($x) type="button" @else type="submit" @endif>x</button>', true],
        'one if branch' => ['<button @if($x) type="button" @endif>x</button>', false],
        'possibly empty loop' => ['<button @foreach($types as $type) type="button" @endforeach>x</button>', false],
        'bag on every path' => ['<button @if($x) {{ $attributes }} @else type="button" @endif>x</button>', true],
        'bag on only one path' => ['<button @if($x) {{ $attributes }} @endif>x</button>', false],
        'include on every path' => ['<button @include(\'attrs\')>x</button>', true],
        'self-contained directive' => ['<button @disabled($x)>x</button>', false],
        'non-attribute csrf directive' => ['<button @csrf>x</button>', false],
    ]);

    it('collects explicit attributes from every conditional branch', function (): void {
        $detector = new class
        {
            use DetectsOpaqueAttributes;

            /** @return list<string> */
            public function values(ElementNode $element): array
            {
                return array_map(
                    static fn ($attribute): string => $attribute->decodedValueText() ?? '',
                    $this->attributesInRenderStructure($element, 'type'),
                );
            }
        };

        $element = Document::parse(
            '<button data-x="1" @if($x) type="button" @elseif($y) type="reset" @else type="submit" @endif>x</button>'
        )->getElements()->first();

        expect($element)->toBeInstanceOf(ElementNode::class);

        /** @var ElementNode $element */
        expect($detector->values($element))->toBe(['button', 'reset', 'submit']);
    });

    it('does not select a later attribute directive after a nested explicit attribute', function (string $template, string $name): void {
        $detector = new class
        {
            use DetectsOpaqueAttributes;

            public function directive(ElementNode $element, string $name): ?string
            {
                return $this->firstUnconditionalKnownAttributeDirective($element, $name)?->nameText();
            }
        };

        $element = Document::parse($template)->getElements()->first();

        expect($element)->toBeInstanceOf(ElementNode::class);

        /** @var ElementNode $element */
        expect($detector->directive($element, $name))->toBeNull();
    })->with([
        'style' => ['<div @if($plain) style="color: blue" @endif @style(["color: red" => $error])></div>', 'style'],
        'class' => ['<div @if($plain) class="plain" @endif @class(["active" => $active])></div>', 'class'],
    ]);
});
