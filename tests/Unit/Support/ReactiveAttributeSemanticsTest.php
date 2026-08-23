<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Sheath\Support\ReactiveAttributeSemantics;

function reactiveAttribute(string $source): Attribute
{
    $element = Document::parse("<form {$source}></form>")->getElements()->first();
    $attribute = $element?->attributes()->first();

    expect($attribute)->toBeInstanceOf(Attribute::class);

    /** @var Attribute $attribute */
    return $attribute;
}

it('maps Alpine and Livewire longhand bindings to their native attribute', function (string $source, string $expected): void {
    expect(ReactiveAttributeSemantics::boundAttributeName(reactiveAttribute($source)))->toBe($expected);
})->with([
    'Alpine shorthand' => [':aria-label="label"', 'aria-label'],
    'Alpine shorthand modifier' => [':aria-label.camel="label"', 'aria-label'],
    'Alpine' => ['x-bind:aria-label="label"', 'aria-label'],
    'Alpine modifier' => ['x-bind:aria-label.camel="label"', 'aria-label'],
    'Livewire' => ['wire:bind:aria-label="label"', 'aria-label'],
    'Livewire modifier' => ['wire:bind:aria-label.camel="label"', 'aria-label'],
]);

it('recognizes only non-empty Alpine object bindings as opaque attribute sets', function (string $source, bool $expected): void {
    expect(ReactiveAttributeSemantics::isOpaqueAttributeSet(reactiveAttribute($source)))->toBe($expected);
})->with([
    'named bundle' => ['x-bind="trigger"', true],
    'complex bundle' => ['x-bind="{{ $bindings }}"', true],
    'empty bundle' => ['x-bind=""', false],
    'bare bundle' => ['x-bind', false],
    'specific binding' => ['x-bind:type="type"', false],
]);

it('normalizes directive modifiers without losing the directive identity', function (string $source, string $expected): void {
    expect(ReactiveAttributeSemantics::directiveName(reactiveAttribute($source)))->toBe($expected);
})->with([
    ['x-for.foo="item in items"', 'x-for'],
    ['wire:show.important="open"', 'wire:show'],
    ['@submit.prevent="save"', '@submit'],
]);

it('recognizes modified Alpine template renderers', function (string $source, bool $local, bool $teleport): void {
    $template = Document::parse($source)->queryElements('template')->first();

    expect($template)->not->toBeNull()
        ->and(ReactiveAttributeSemantics::isLocalTemplateRenderer($template))->toBe($local)
        ->and(ReactiveAttributeSemantics::isTeleportTemplateRenderer($template))->toBe($teleport);
})->with([
    ['<template x-if.foo="open"><div></div></template>', true, false],
    ['<template x-for.foo="item in items"><div></div></template>', true, false],
    ['<template x-teleport.append="body"><div></div></template>', false, true],
]);

it('recognizes durable Livewire and Alpine submit interception', function (string $source, bool $expected): void {
    expect(ReactiveAttributeSemantics::preventsNativeFormSubmission(reactiveAttribute($source)))->toBe($expected);
})->with([
    'Livewire default prevention' => ['wire:submit="save"', true],
    'Livewire explicit prevention' => ['wire:submit.prevent="save"', true],
    'Livewire debounce prevents immediately' => ['wire:submit.debounce.250ms="save"', true],
    'Alpine longhand' => ['x-on:submit.prevent="save"', true],
    'Alpine shorthand' => ['@submit.prevent="save"', true],
    'Alpine missing prevent' => ['x-on:submit="save"', false],
    'Livewire one-shot listener' => ['wire:submit.once="save"', false],
    'Livewire passive listener' => ['wire:submit.passive="save"', false],
    'Livewire stable bundle does not implement passive false' => ['wire:submit.passive.false="save"', false],
    'Alpine explicitly disables passive mode' => ['@submit.passive.false.prevent="save"', true],
    'Alpine outside listener' => ['@submit.prevent.outside="save"', false],
    'Alpine away listener' => ['@submit.prevent.away="save"', false],
]);

it('recognizes content replacement expressions that are definitely empty', function (string $source, bool $expected): void {
    expect(ReactiveAttributeSemantics::clientTextMayBeNonEmpty(reactiveAttribute($source)))->toBe($expected);
})->with([
    'dynamic Alpine text' => ['x-text="label"', true],
    'dynamic Livewire text' => ['wire:text="label"', true],
    'empty expression' => ['x-text=""', false],
    'empty string literal' => ['x-text="\'\'"', false],
    'whitespace string literal' => ['x-html="\'   \'"', false],
    'null expression' => ['x-text="null"', false],
    'empty array expression' => ['x-text="[]"', false],
]);

it('distinguishes visibility toggles from class and attribute mutations', function (string $source, bool $expected): void {
    expect(ReactiveAttributeSemantics::mayHideElement(reactiveAttribute($source)))->toBe($expected);
})->with([
    'Alpine show' => ['x-show="visible"', true],
    'statically visible Alpine show' => ['x-show="true"', false],
    'Livewire show' => ['wire:show="visible"', true],
    'loading visibility' => ['wire:loading', true],
    'loading removal' => ['wire:loading.remove', true],
    'dirty visibility' => ['wire:dirty', true],
    'offline visibility' => ['wire:offline', true],
    'loading class' => ['wire:loading.class="opacity-50"', false],
    'dirty class removal' => ['wire:dirty.class.remove="saved"', false],
    'offline attribute' => ['wire:offline.attr="disabled"', false],
    'loading hidden attribute' => ['wire:loading.attr="hidden"', true],
    'dirty inert removal' => ['wire:dirty.attr.remove="inert"', true],
]);

it('models Alpine ignore initialization boundaries', function (string $source, string $attributeName, bool $expected): void {
    $document = Document::parse($source);
    $element = $document->queryElements()->first(
        static fn ($candidate): bool => $candidate->hasAttribute($attributeName),
    );
    $attribute = $element?->attribute($attributeName);

    expect($element)->not->toBeNull()
        ->and($attribute)->toBeInstanceOf(Attribute::class)
        ->and(ReactiveAttributeSemantics::clientDirectiveRuns($attribute, $element))->toBe($expected);
})->with([
    'Alpine on ignored owner' => ['<span x-ignore x-text="label"></span>', 'x-text', false],
    'x-ignore establishes its own boundary' => ['<span x-ignore></span>', 'x-ignore', true],
    'Alpine below ignored subtree' => ['<div x-ignore><span x-text="label"></span></div>', 'x-text', false],
    'Alpine below ignore self' => ['<div x-ignore.self><span x-text="label"></span></div>', 'x-text', true],
    'Livewire on ignored owner initializes first' => ['<span x-ignore wire:text="label"></span>', 'wire:text', true],
    'Livewire below ignored subtree never initializes' => ['<div x-ignore><span wire:text="label"></span></div>', 'wire:text', false],
    'Livewire below ignore self initializes' => ['<div x-ignore.self><span wire:text="label"></span></div>', 'wire:text', true],
]);
