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

it('accepts valid Livewire directive contracts', function (string $source): void {
    expect(lintLivewireDirectiveIntegrity($source))->toBe([]);
})->with([
    'model property' => '<input wire:model.live="search">',
    'native navigate href' => '<a href="/docs" wire:navigate>Docs</a>',
    'empty href is the current page URL' => '<a href="" wire:navigate>Reload</a>',
    'bound navigate href' => '<a :href="destination" wire:navigate>Docs</a>',
    'current href' => '<a href="/docs" wire:current="font-bold">Docs</a>',
    'ignored current state' => '<span wire:current.ignore></span>',
    'confirmed action' => '<button wire:click="destroy" wire:confirm="Delete it?">Delete</button>',
    'prompt expectation' => '<button wire:click="destroy" wire:confirm.prompt="Type DELETE|DELETE">Delete</button>',
    'Livewire sort hierarchy' => '<ul wire:sort="reorder" wire:sort:group="tasks"><li wire:sort:item="one">One</li></ul>',
    'Alpine sort hierarchy' => '<ul x-sort><li wire:sort:item="one">One</li></ul>',
    'same-branch conditional navigation' => '<a @if($spa) wire:navigate href="/spa" @else href="/plain" @endif>Go</a>',
    'same-branch conditional confirmation' => '<button @if($dangerous) wire:confirm="Sure?" wire:click="destroy" @else wire:click="save" @endif>Go</button>',
    'ignored subtree' => '<div x-ignore><a wire:navigate>No runtime directive</a><input wire:model=""></div>',
    'network timing after live' => '<input wire:model.number.live.debounce.250ms="search">',
    'ephemeral blur before live' => '<input wire:model.blur.live.debounce.250ms="search">',
    'ephemeral Enter key chord before live' => '<input wire:model.ctrl.shift.enter.live="search">',
    'network blur after live' => '<input wire:model.live.blur="search">',
    'v3-compatible lazy request' => '<input wire:model.lazy="search">',
    'renderless live model' => '<input wire:model.renderless.live="search">',
    'preserved-scroll live model' => '<input wire:model.live.preserve-scroll="search">',
    'navigate modifiers' => '<a href="/docs" wire:navigate.hover.preserve-scroll>Docs</a>',
    'current modifiers' => '<a href="/docs" wire:current.exact.strict="font-bold">Docs</a>',
    'show modifier' => '<div wire:show.important="open"></div>',
    'ignore modifier' => '<div wire:ignore.children></div>',
    'replace modifier' => '<div wire:replace.self></div>',
    'loading state modifiers' => '<div wire:loading.delay.shortest.inline-flex></div>',
    'dirty class modifier' => '<div wire:dirty.class.remove="opacity-50"></div>',
    'offline attribute modifier' => '<div wire:offline.attr="inert"></div>',
    'poll modifiers' => '<div wire:poll.15s.keep-alive.visible></div>',
    'poll interval with leading zero' => '<div wire:poll.015s></div>',
    'target inversion' => '<div wire:loading wire:target.except="save"></div>',
    'intersect modifiers' => '<div wire:intersect.once.parent.margin.10%.20px.threshold.50="load"></div>',
    'intersect leave variant' => '<div wire:intersect:leave.once="unload"></div>',
    'intersect threshold follows Alpine decimal-digit parsing' => '<div wire:intersect.threshold.101="load"></div>',
    'sort modifiers' => '<ul wire:sort.async.ghost.group.tasks="reorder"><li wire:sort:item="one"></li></ul>',
    'island append mode' => '<button wire:click="load" wire:island.append="feed"></button>',
    'renderless initializer' => '<div wire:init.renderless="load"></div>',
    'replace stream target' => '<span wire:stream.replace="answer"></span>',
    'camel-cased Livewire binding' => '<svg wire:bind:view-box.camel="box"></svg>',
    'Blade component owns its attributes' => '<x-input wire:navigate.prefetch />',
    'Livewire child component decides whether wire model is modelable' => '<livewire:search wire:model.debonce="query" />',
]);

it('reports unsupported Livewire modifiers that would otherwise be ignored', function (string $source): void {
    $violations = lintLivewireDirectiveIntegrity($source);

    expect($violations)->toHaveCount(1);
})->with([
    'model typo' => ['<input wire:model.debonce="search">'],
    'model duration typo' => ['<input wire:model.live.debounce.2s="search">'],
    'navigate exact selector miss' => ['<a href="/docs" wire:navigate.prefetch>Docs</a>'],
    'current typo' => ['<a href="/docs" wire:current.excat="font-bold">Docs</a>'],
    'confirm typo' => ['<button wire:click="destroy" wire:confirm.promt="DELETE|DELETE">Delete</button>'],
    'show typo' => ['<div wire:show.imporant="open"></div>'],
    'text modifier' => ['<span wire:text.debounce="status"></span>'],
    'key modifier' => ['<article wire:key.once="article"></article>'],
    'ignore modifier' => ['<div wire:ignore.once></div>'],
    'replace modifier' => ['<div wire:replace.children></div>'],
    'transition modifier' => ['<div wire:transition.fade></div>'],
    'loading modifier' => ['<div wire:loading.debounce></div>'],
    'dirty modifier' => ['<div wire:dirty.delay></div>'],
    'offline modifier' => ['<div wire:offline.once></div>'],
    'target modifier' => ['<div wire:target.only="save"></div>'],
    'poll modifier' => ['<div wire:poll.forever></div>'],
    'intersect modifier' => ['<div wire:intersect.nearly="load"></div>'],
    'sort modifier' => ['<ul wire:sort.snap="reorder"></ul>'],
    'island modifier' => ['<button wire:click="load" wire:island.replace="feed"></button>'],
    'init modifier' => ['<div wire:init.once="load"></div>'],
    'stream modifier' => ['<span wire:stream.append="answer"></span>'],
    'binding modifier' => ['<div wire:bind:title.once="label"></div>'],
]);

it('reports wire:model modifiers that Livewire removes or ignores because of their position', function (string $source): void {
    expect(lintLivewireDirectiveIntegrity($source))->toHaveCount(1);
})->with([
    'debounce without a live request' => '<input wire:model.debounce.250ms="search">',
    'debounce before the live boundary' => '<input wire:model.debounce.250ms.live="search">',
    'value coercion after live' => '<input wire:model.live.number="search">',
    'system key without Enter' => '<input wire:model.ctrl.live="search">',
    'zero network delay falls back to default' => '<input wire:model.live.debounce.0ms="search">',
    'fill after live' => '<input wire:model.live.fill="search">',
    'duplicate live boundary' => '<input wire:model.live.live="search">',
    'request-only modifier without request' => '<input wire:model.renderless="search">',
    'outside model guard' => '<input wire:model.outside.live="search">',
    'ephemeral Enter value coercion' => '<input wire:model.number.enter.live="search">',
    'ephemeral Enter deep mode' => '<input wire:model.deep.enter.live="search">',
]);

it('reports deterministic modifier conflicts and incomplete modifier values', function (string $source): void {
    $violations = lintLivewireDirectiveIntegrity($source);

    expect($violations)->toHaveCount(1);
})->with([
    'navigate duplicate misses exact selector' => ['<a href="/docs" wire:navigate.hover.hover>Docs</a>'],
    'ignore modes compete' => ['<div wire:ignore.self.children></div>'],
    'island modes compete' => ['<button wire:click="load" wire:island.prepend.append="feed"></button>'],
    'loading output modes compete' => ['<div wire:loading.class.attr="hidden"></div>'],
    'loading class needs a value' => ['<div wire:loading.class=""></div>'],
    'loading duration requires delay' => ['<div wire:loading.shortest></div>'],
    'loading durations compete' => ['<div wire:loading.delay.short.long></div>'],
    'poll intervals compete' => ['<div wire:poll.1s.500ms></div>'],
    'zero poll interval falls back to default' => ['<div wire:poll.0ms></div>'],
    'intersect threshold value missing' => ['<div wire:intersect.threshold.once="load"></div>'],
    'intersect threshold modes compete' => ['<div wire:intersect.half.full="load"></div>'],
    'sort group name missing' => ['<ul wire:sort.group="reorder"></ul>'],
    'duplicate stream selector misses initialization' => ['<span wire:stream.replace.replace="answer"></span>'],
]);

it('reports unsupported built-in directive variants and empty intersect actions', function (string $source): void {
    $violations = lintLivewireDirectiveIntegrity($source);

    expect($violations)->toHaveCount(1);
})->with([
    'unknown intersect event becomes enter' => ['<div wire:intersect:exit="unload"></div>'],
    'empty intersect action' => ['<div wire:intersect.once></div>'],
    'unknown sort variant becomes base sort' => ['<ul wire:sort:items="reorder"></ul>'],
    'bare binding has no target' => ['<div wire:bind="value"></div>'],
]);

it('reports intrinsic empty values and orphaned sort items', function (string $source, string $slice): void {
    $violations = lintLivewireDirectiveIntegrity($source);

    expect($violations)->toHaveCount(1)
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe($slice);
})->with([
    'empty model' => ['<input wire:model>', 'wire:model'],
    'empty key' => ['<article wire:key=""></article>', 'wire:key=""'],
    'empty sort item' => ['<ul wire:sort="reorder"><li wire:sort:item="">One</li></ul>', 'wire:sort:item=""'],
    'empty sort action' => ['<ul wire:sort><li wire:sort:item="one">One</li></ul>', 'wire:sort'],
    'orphan sort item' => ['<li wire:sort:item="one">One</li>', 'wire:sort:item="one"'],
    'conditional empty model' => ['<input @if($live) wire:model="" @endif>', 'wire:model=""'],
    'empty target' => ['<div wire:loading wire:target=""></div>', 'wire:target=""'],
]);

it('reports navigation and current-state directives without href on the same render path', function (string $source, string $slice): void {
    $violations = lintLivewireDirectiveIntegrity($source);

    expect($violations)->toHaveCount(1)
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe($slice);
})->with([
    'navigate absent href' => ['<button wire:navigate>Go</button>', 'wire:navigate'],
    'current absent href' => ['<span wire:current="font-bold">Here</span>', 'wire:current="font-bold"'],
    'mismatched conditional href' => ['<a @if($spa) wire:navigate @else href="/plain" @endif>Go</a>', 'wire:navigate'],
    'ignored bound href' => ['<a x-ignore :href="destination" wire:navigate>Go</a>', 'wire:navigate'],
]);

it('reports current-page state on fragment-only links that Livewire explicitly ignores', function (): void {
    $violations = lintLivewireDirectiveIntegrity('<a href="#details" wire:current="font-bold">Details</a>');

    expect($violations)->toHaveCount(1);
});

it('reports confirmation and sort companion contracts', function (string $source): void {
    $violations = lintLivewireDirectiveIntegrity($source);

    expect($violations)->toHaveCount(1);
})->with([
    'confirm without action' => ['<button wire:confirm="Sure?">No action</button>'],
    'prompt missing separator' => ['<button wire:click="destroy" wire:confirm.prompt="Type DELETE">Delete</button>'],
    'prompt empty expectation' => ['<button wire:click="destroy" wire:confirm.prompt="Type DELETE|">Delete</button>'],
    'confirm and action on different paths' => ['<button @if($dangerous) wire:confirm="Sure?" @else wire:click="destroy" @endif>Delete</button>'],
    'confirm with empty action' => ['<button wire:click="" wire:confirm="Sure?">Delete</button>'],
    'island metadata is not an action' => ['<button wire:island="feed" wire:confirm="Sure?">No action</button>'],
    'sort item metadata is not a confirmable event' => ['<ul wire:sort="reorder"><li wire:sort:item="one" wire:confirm="Sure?"></li></ul>'],
    'sort group without owner' => ['<ul wire:sort:group="tasks"></ul>'],
]);
