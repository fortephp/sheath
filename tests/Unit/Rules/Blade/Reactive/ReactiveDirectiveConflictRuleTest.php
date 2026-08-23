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

it('accepts independent or mutually exclusive reactive behavior', function (string $source): void {
    expect(lintReactiveDirectiveConflicts($source))->toBe([]);
})->with([
    'Alpine model only' => '<input x-model="draft">',
    'Livewire model only' => '<input wire:model="draft">',
    'separate state owners' => '<input x-model="local"><output wire:text="remote"></output>',
    'exclusive Blade visibility' => '<div @if($local) x-show="open" @else wire:show="open" @endif></div>',
    'bound class may remove hidden' => '<div class="hidden" :class="{ hidden: ! open }" x-show="open"></div>',
    'bound hidden attribute may remove blocker' => '<div hidden :hidden="! open" x-show="open"></div>',
    'Livewire class removal owns hidden token' => '<div class="hidden" wire:loading.class.remove="hidden"></div>',
    'ignored Alpine owner' => '<div x-ignore x-show="open" wire:show="open"></div>',
    'ordinary passive listener' => '<div @scroll.passive="measure"></div>',
    'explicitly non-passive Alpine listener' => '<form @submit.passive.false.prevent="save"></form>',
    'loading inline display overrides an ordinary hidden class' => '<span class="hidden" wire:loading>Saving</span>',
    'vertical tab remains part of a class token' => "<div class=\"other\x0Bhidden\" x-show=\"open\"></div>",
    'Blade component decides which client attributes reach its root' => '<x-input x-model="local" wire:model="remote" />',
]);

it('reports competing Alpine and Livewire state owners at the Livewire directive', function (string $source, string $slice): void {
    $violations = lintReactiveDirectiveConflicts($source);

    expect($violations)->toHaveCount(1)
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe($slice);
})->with([
    'model' => ['<input x-model="local" wire:model="remote">', 'wire:model="remote"'],
    'visibility' => ['<div x-show="local" wire:show="remote"></div>', 'wire:show="remote"'],
    'text' => ['<span x-text="local" wire:text="remote"></span>', 'wire:text="remote"'],
    'navigation' => ['<a href="/docs" x-navigate wire:navigate>Docs</a>', 'wire:navigate'],
    'sorting' => ['<ul x-sort="local" wire:sort="remote"></ul>', 'wire:sort="remote"'],
    'bound title' => ['<div :title="local" wire:bind:title="remote"></div>', 'wire:bind:title="remote"'],
]);

it('reports multiple reactive owners within the same behavior family', function (string $source): void {
    $violations = lintReactiveDirectiveConflicts($source);

    expect($violations)->toHaveCount(1);
})->with([
    'Alpine content owners' => ['<span x-text="label" x-html="markup"></span>'],
    'cross-framework content owners' => ['<span x-html="markup" wire:text="label"></span>'],
    'cross-framework display owners' => ['<div x-show="open" wire:loading></div>'],
    'Livewire display owners' => ['<div wire:dirty wire:offline></div>'],
    'duplicate Alpine attribute bindings' => ['<div :title="first" x-bind:title="second"></div>'],
]);

it('reports static visibility blockers on the directive that cannot overcome them', function (string $source, string $slice): void {
    $violations = lintReactiveDirectiveConflicts($source);

    expect($violations)->toHaveCount(1)
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe($slice);
})->with([
    'native hidden with Alpine show' => ['<div hidden x-show="open"></div>', 'x-show="open"'],
    'Tailwind hidden with Livewire show' => ['<div class="hidden" wire:show="open"></div>', 'wire:show="open"'],
    'native hidden with loading' => ['<span hidden wire:loading>Saving</span>', 'wire:loading'],
    'conditional blocker path' => ['<div @if($concealed) hidden @endif x-show="open"></div>', 'x-show="open"'],
]);

it('reports passive listeners that also try to prevent browser defaults', function (string $source): void {
    $violations = lintReactiveDirectiveConflicts($source);

    expect($violations)->toHaveCount(1);
})->with([
    '<form @submit.passive.prevent="save"></form>',
    '<form x-on:submit.prevent.passive="save"></form>',
    '<form wire:submit.passive="save"></form>',
    '<button wire:click.passive.prevent="save">Save</button>',
]);

it('reports contradictory Alpine event targets for Alpine and Livewire listeners', function (string $source): void {
    $violations = lintReactiveDirectiveConflicts($source);

    expect($violations)->toHaveCount(1);
})->with([
    'Alpine global targets' => ['<div @resize.window.document="measure"></div>'],
    'Livewire global targets' => ['<button wire:click.window.document="save">Save</button>'],
    'Alpine impossible ownership guards' => ['<div x-on:click.outside.self="close"></div>'],
    'Livewire impossible ownership guards' => ['<button wire:click.away.self="save">Save</button>'],
]);

it('does not treat Livewire colon metadata as an event action', function (): void {
    expect(lintReactiveDirectiveConflicts(
        '<ul wire:sort="reorder"><li wire:sort:item.passive.prevent="one"></li></ul>',
    ))->toBe([]);
});
