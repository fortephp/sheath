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

it('accepts one concrete root on every Blade render path', function (string $source): void {
    expect(lintReactiveTemplate($source))->toBe([]);
})->with([
    'x-if' => '<div x-data><template x-if="open"><section>Open</section></template></div>',
    'x-for' => '<div x-data><template x-for="item in items"><article x-text="item"></article></template></div>',
    'Alpine-compatible empty iterator slot' => '<template x-for="(item,,items) in rows"><div></div></template>',
    'Alpine-compatible trailing iterator comma' => '<template x-for="item, in rows"><div></div></template>',
    'x-teleport' => '<div x-data><template x-teleport="body"><dialog>Modal</dialog></template></div>',
    'exclusive Blade roots' => '<template x-if="open">@if($compact)<span>A</span>@else<div>B</div>@endif</template>',
    'Livewire teleport' => "@teleport('body')<dialog>Modal</dialog>@endteleport",
    'comments around the root' => '<template x-if="open"><!-- before --><section>Open</section><!-- after --></template>',
    'exclusive conditional structural owners' => '<template @if($single) x-if="open" @else x-for="item in items" @endif><section>Open</section></template>',
]);

it('requires structural Alpine directives on template elements', function (string $source, string $attribute): void {
    $document = "<p>before</p>\n  {$source}";
    $violations = lintReactiveTemplate($document);

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->start->line)->toBe(2)
        ->and(substr(
            $document,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe($attribute);
})->with([
    ['<div x-if="open">A</div>', 'x-if="open"'],
    ['<ul x-for="item in items"><li>A</li></ul>', 'x-for="item in items"'],
    ['<section x-teleport="body">A</section>', 'x-teleport="body"'],
]);

it('reports template paths Alpine cannot instantiate safely', function (string $source): void {
    $violations = lintReactiveTemplate($source);

    expect($violations)->toHaveCount(1);
})->with([
    'empty template' => '<template x-if="open"></template>',
    'two roots' => '<template x-if="open"><span>A</span><span>B</span></template>',
    'comments do not hide two roots' => '<template x-if="open"><!-- before --><span>A</span><span>B</span></template>',
    'ignored text sibling' => '<template x-for="item in items">Prefix <span x-text="item"></span></template>',
    'optional Blade root' => '<template x-if="open">@if($ready)<span>A</span>@endif</template>',
    'loop can be empty or repeat roots' => '<template x-if="open">@foreach($items as $item)<span>A</span>@endforeach</template>',
    'Livewire teleport roots' => "@teleport('body')<div>A</div><div>B</div>@endteleport",
]);

it('reports invalid Alpine structural expressions and competing template owners', function (string $source): void {
    $violations = lintReactiveTemplate($source);

    expect($violations)->toHaveCount(1);
})->with([
    'missing x-for expression' => ['<template x-for><div></div></template>'],
    'missing x-for operator' => ['<template x-for="item items"><div></div></template>'],
    'missing x-for collection' => ['<template x-for="item in "><div></div></template>'],
    'zero x-for collection' => ['<template x-for="item in 0"><div></div></template>'],
    'empty array x-for collection' => ['<template x-for="item in []"><div></div></template>'],
    'empty object x-for collection' => ['<template x-for="item in {}"><div></div></template>'],
    'non-finite x-for collection' => ['<template x-for="item in 1e999"><div></div></template>'],
    'missing x-if condition' => ['<template x-if><div></div></template>'],
    'empty teleport selector' => ['<template x-teleport=""><div></div></template>'],
    'JavaScript-quoted teleport selector' => ['<template x-teleport="\'body\'"><div></div></template>'],
    'if and for compete' => ['<template x-if="open" x-for="item in items"><div></div></template>'],
    'conditional invalid for expression' => ['<template @if($loop) x-for="item items" @endif><div></div></template>'],
    'same conditional branch competes' => ['<template @if($loop) x-if="open" x-for="item in items" @endif><div></div></template>'],
]);

it('stands down when a component or echo determines the runtime root', function (string $source): void {
    expect(lintReactiveTemplate($source))->toBe([]);
})->with([
    '<template x-if="open"><x-modal /></template>',
    '<template x-if="open">{!! $markup !!}</template>',
]);

it('rejects visibility directives on inert template elements', function (string $source): void {
    $violations = lintReactiveTemplate($source);

    expect($violations)->toHaveCount(1);
})->with([
    '<template x-show="open"><div>A</div></template>',
    '<template wire:show="open"><div>A</div></template>',
]);

it('rejects transitions Alpine explicitly does not support with x-if', function (): void {
    $source = '<template x-if="open"><div x-transition.opacity>Open</div></template>';
    $violations = lintReactiveTemplate($source);

    expect($violations)->toHaveCount(1)
        ->and(substr($source, $violations[0]->start->offset, $violations[0]->end->offset - $violations[0]->start->offset))
        ->toBe('x-transition.opacity');
});

it('does not describe Alpine directives as active below x-ignore', function (string $source): void {
    expect(lintReactiveTemplate($source))->toBe([]);
})->with([
    '<div x-ignore><section x-if="open">A</section></div>',
    '<div x-ignore><template x-if="open"><span>A</span><span>B</span></template></div>',
    '<template x-ignore x-if="open"><span>A</span><span>B</span></template>',
    '<template x-ignore.self x-if="open"><span x-transition>A</span></template>',
]);

it('keeps Alpine directives active below x-ignore.self', function (): void {
    expect(lintReactiveTemplate(
        '<div x-ignore.self><section x-if="open">A</section></div>',
    ))->toHaveCount(1);
});

it('anchors structural diagnostics to the opener rather than all rendered children', function (): void {
    $alpine = "<p>before</p>\n<template x-if=\"open\"><section>A</section><section>B</section></template>";
    $alpineViolation = lintReactiveTemplate($alpine)[0] ?? null;
    $livewire = "<p>before</p>\n@teleport('body')<section>A</section><section>B</section>@endteleport";
    $livewireViolation = lintReactiveTemplate($livewire)[0] ?? null;

    expect($alpineViolation)->not->toBeNull()
        ->and(substr(
            $alpine,
            $alpineViolation?->start->offset ?? 0,
            ($alpineViolation?->end->offset ?? 0) - ($alpineViolation?->start->offset ?? 0),
        ))->toBe('<template x-if="open">')
        ->and($livewireViolation)->not->toBeNull()
        ->and(substr(
            $livewire,
            $livewireViolation?->start->offset ?? 0,
            ($livewireViolation?->end->offset ?? 0) - ($livewireViolation?->start->offset ?? 0),
        ))->toBe("@teleport('body')");
});
