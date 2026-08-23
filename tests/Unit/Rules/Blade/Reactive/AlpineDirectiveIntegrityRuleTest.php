<?php

declare(strict_types=1);

use Forte\Sheath\Tests\Fixtures\LivewireTagPrecompilerPresenceStub;

require_once __DIR__.'/../../../../Fixtures/LivewireTagPrecompilerPresenceStub.php';

beforeAll(function (): void {
    $compiler = 'Livewire\\Mechanisms\\CompileLivewireTags\\LivewireTagPrecompiler';

    if (! class_exists($compiler)) {
        class_alias(LivewireTagPrecompilerPresenceStub::class, $compiler);
    }
});

it('accepts valid Alpine directive contracts', function (string $source): void {
    expect(lintAlpineDirectiveIntegrity($source))->toBe([]);
})->with([
    'ID names array' => '<div x-data x-id="[\'field\', \'label\']"></div>',
    'dynamic ID collection' => '<div x-data x-id="names"></div>',
    'array with dynamic entries' => '<div x-data x-id="[prefix + \'-field\']"></div>',
    'modelable with Alpine owner' => '<div x-data="{ inner: null, outer: null }" x-modelable="inner" x-model="outer"></div>',
    'modelable with Livewire owner' => '<div x-data="{ inner: null }" x-modelable="inner" wire:model="outer"></div>',
    'exclusive modelable owner' => '<div x-data @if($local) x-modelable="inner" x-model="outer" @else wire:model="outer" @endif></div>',
    'teleport event forwarding' => '<template x-teleport="body" @click="close"><button>Close</button></template>',
    'global template event' => '<template x-if="open" @keydown.escape.window="close"><div></div></template>',
    'ignored Alpine contract' => '<div x-ignore><div x-id="\'wrong\'"></div><span x-modelable></span></div>',
    'model modifier grammar' => '<input x-data="{ value: 0 }" x-model.number.debounce.250ms="value">',
    'model Enter key chord' => '<input x-data="{ value: 0 }" x-model.ctrl.shift.enter="value">',
    'explicit non-passive event option' => '<input x-data="{ value: 0 }" x-model.passive.false="value">',
    'callable x-id object method' => '<div x-data x-id="{ forEach(callback) { callback(\'field\') } }"></div>',
    'referenced x-id forEach method' => '<div x-data x-id="{ forEach: names.forEach }"></div>',
    'referenced x-id callback' => '<div x-data x-id="{ forEach: callback }"></div>',
    'shorthand x-id callback' => '<div x-data x-id="{ forEach }"></div>',
    'computed x-id member may be iterable' => '<div x-data x-id="{ [name]: callback }"></div>',
    'spread x-id member may be iterable' => '<div x-data x-id="{ ...provider }"></div>',
    'async x-id method' => '<div x-data x-id="{ async forEach(callback) { callback(\'field\') } }"></div>',
    'show modifiers' => '<div x-data="{ open: true }" x-show.immediate.important="open"></div>',
    'camel-cased bound attribute' => '<svg :view-box.camel="box"></svg>',
    'teleport placement' => '<template x-teleport.append="body"><div></div></template>',
    'ignore self' => '<div x-ignore.self></div>',
    'Blade component owns its attributes' => '<x-input x-model.debonce="value" />',
]);

it('reports unsupported modifiers on fixed Alpine directive grammars', function (string $source): void {
    $violations = lintAlpineDirectiveIntegrity($source);

    expect($violations)->toHaveCount(1);
})->with([
    '<template x-if.once="open"><div></div></template>',
    '<template x-for.track="item in items"><div></div></template>',
    '<div x-show.imporant="open"></div>',
    '<span x-text.debounce="label"></span>',
    '<div x-ignore.children></div>',
    '<div :title.once="label"></div>',
    '<template x-for="item in items" :key.camel="item.id"><span></span></template>',
]);

it('reports mutually exclusive Alpine teleport placement modifiers', function (): void {
    $violations = lintAlpineDirectiveIntegrity(
        '<template x-teleport.append.prepend="body"><div></div></template>',
    );

    expect($violations)->toHaveCount(1);
});

it('reports unsupported Alpine model modifiers', function (string $source): void {
    $violations = lintAlpineDirectiveIntegrity($source);

    expect($violations)->toHaveCount(1);
})->with([
    '<input x-data="{ value: 0 }" x-model.debonce="value">',
    '<input x-data="{ value: 0 }" x-model.debounce.2s="value">',
    '<input x-data="{ value: 0 }" x-model.number.typo="value">',
    '<input x-data="{ value: 0 }" x-model.outside="value">',
    '<input x-data="{ value: 0 }" x-model.away="value">',
    '<input x-data="{ value: 0 }" x-model.enter.number="value">',
    '<input x-data="{ value: 0 }" x-model.trim.enter="value">',
    '<input x-data="{ value: 0 }" x-model.enter.passive.false="value">',
    '<input x-data="{ value: 0 }" x-model.ctrl="value">',
]);

it('reports static x-id values that do not provide forEach', function (string $source): void {
    $violations = lintAlpineDirectiveIntegrity($source);

    expect($violations)->toHaveCount(1);
})->with([
    '<div x-data x-id></div>',
    '<div x-data x-id="\'field\'"></div>',
    '<div x-data x-id="null"></div>',
    '<div x-data x-id="{ field: true }"></div>',
    '<div x-data x-id="{ forEach: true }"></div>',
    '<div x-data x-id="{ notforEach: true }"></div>',
    '<div x-data @if($bad) x-id="42" @else x-id="[\'field\']" @endif></div>',
]);

it('reports x-model without an assignable expression', function (): void {
    $violations = lintAlpineDirectiveIntegrity('<input x-data="{ value: 0 }" x-model>');

    expect($violations)->toHaveCount(1);
});

it('reports literal Alpine model expressions that cannot receive updates', function (string $source): void {
    $violations = lintAlpineDirectiveIntegrity($source);

    expect($violations)->toHaveCount(1);
})->with([
    '<input x-model="\'fixed\'">',
    '<div x-modelable="42" x-model="outer"></div>',
]);

it('reports x-modelable without a usable same-path model owner', function (string $source): void {
    $violations = lintAlpineDirectiveIntegrity($source);

    expect($violations)->toHaveCount(1);
})->with([
    'empty expression' => ['<div x-data x-modelable></div>'],
    'missing owner' => ['<div x-data x-modelable="inner"></div>'],
    'owner on other path' => ['<div x-data @if($inner) x-modelable="inner" @else x-model="outer" @endif></div>'],
]);

it('reports event and transition directives attached to inert templates', function (string $source, string $slice): void {
    $violations = lintAlpineDirectiveIntegrity($source);

    expect($violations)->toHaveCount(1)
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe($slice);
})->with([
    'x-if event' => ['<template x-if="open" @click="close"><button>Close</button></template>', '@click="close"'],
    'ordinary template event' => ['<template x-on:focus="open"><input></template>', 'x-on:focus="open"'],
    'transition on template' => ['<template x-if="open" x-transition><div></div></template>', 'x-transition'],
    'transition phase on template' => ['<template x-if="open" x-transition:enter><div></div></template>', 'x-transition:enter'],
    'conditional template event' => ['<template x-if="open" @if($clickable) @click="close" @endif><div></div></template>', '@click="close"'],
]);
