<?php

declare(strict_types=1);

it('accepts optional and iteration-derived Alpine keys', function (string $source): void {
    expect(lintAlpineForKey($source))->toBe([]);
})->with([
    'key is optional' => '<template x-for="item in items"><div x-text="item.name"></div></template>',
    'item property' => '<template x-for="item in items" :key="item.id"><div></div></template>',
    'index' => '<template x-for="(item, index) in items" x-bind:key="index"><div></div></template>',
    'derived expression' => '<template x-for="item in items" :key="`item-${item.id}`"><div></div></template>',
    'ignored template' => '<template x-ignore x-for="item in items" key="same"><div></div></template>',
    'key is on path without loop' => '<template @if($loop) x-for="item in items" @else key="same" @endif><div></div></template>',
    'constant key on singleton array' => '<template x-for="item in [1]" :key="\'row\'"><div></div></template>',
    'constant key on one numeric iteration' => '<template x-for="item in 1" :key="\'row\'"><div></div></template>',
    'constant key on one fractional iteration' => '<template x-for="item in 1.9" :key="\'row\'"><div></div></template>',
    'constant key on empty collection' => '<template x-for="item in []" :key="\'row\'"><div></div></template>',
    'constant key on sparse singleton collection' => '<template x-for="item in [,,1,]" :key="\'row\'"><div></div></template>',
]);

it('reports no-op and constant keys on Alpine loops', function (string $source, string $slice): void {
    $violations = lintAlpineForKey($source);

    expect($violations)->toHaveCount(1)
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe($slice);
})->with([
    'plain key' => ['<template x-for="item in items" key="item.id"><div></div></template>', 'key="item.id"'],
    'quoted constant' => ['<template x-for="item in items" :key="\'row\'"><div></div></template>', ':key="\'row\'"'],
    'numeric constant' => ['<template x-for="item in items" :key="1"><div></div></template>', ':key="1"'],
    'constant template literal' => ['<template x-for="item in items" x-bind:key="`row`"><div></div></template>', 'x-bind:key="`row`"'],
    'object key' => ['<template x-for="item in items" :key="{ id: item.id }"><div></div></template>', ':key="{ id: item.id }"'],
    'array key' => ['<template x-for="item in items" :key="[item.id]"><div></div></template>', ':key="[item.id]"'],
    'conditional constant key' => ['<template @if($loop) x-for="item in items" key="same" @endif><div></div></template>', 'key="same"'],
]);
