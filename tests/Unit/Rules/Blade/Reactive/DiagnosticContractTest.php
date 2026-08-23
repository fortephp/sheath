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

it('anchors each reactive diagnostic to the smallest actionable source range', function (
    string $rule,
    string $source,
    string $expected,
): void {
    $violations = match ($rule) {
        'blade-alpine-directive-integrity' => lintAlpineDirectiveIntegrity($source),
        'blade-alpine-for-key-integrity' => lintAlpineForKey($source),
        'blade-livewire-directive-integrity' => lintLivewireDirectiveIntegrity($source),
        'blade-livewire-loop-key-integrity' => lintLivewireLoopKey($source),
        'blade-reactive-directive-conflicts' => lintReactiveDirectiveConflicts($source),
        default => throw new InvalidArgumentException("Unknown test rule [{$rule}]."),
    };
    $violation = $violations[0] ?? null;

    expect($violation)->not->toBeNull()
        ->and(substr(
            $source,
            $violation?->start->offset ?? 0,
            ($violation?->end->offset ?? 0) - ($violation?->start->offset ?? 0),
        ))->toBe($expected);
})->with([
    'Alpine directive' => [
        'blade-alpine-directive-integrity',
        "<p>before</p>\n<div x-show.imporant=\"open\"><span>child</span></div>",
        'x-show.imporant="open"',
    ],
    'Alpine loop key' => [
        'blade-alpine-for-key-integrity',
        "<p>before</p>\n<template x-for=\"item in items\" :key=\"'same'\"><span>child</span></template>",
        ':key="\'same\'"',
    ],
    'Livewire directive' => [
        'blade-livewire-directive-integrity',
        "<p>before</p>\n<a wire:navigate><span>child</span></a>",
        'wire:navigate',
    ],
    'Livewire loop key' => [
        'blade-livewire-loop-key-integrity',
        '@foreach ($items as $item)'."\n<div wire:key=\"same\"><span>child</span></div>\n@endforeach",
        'wire:key="same"',
    ],
    'reactive conflict' => [
        'blade-reactive-directive-conflicts',
        "<p>before</p>\n<input x-model=\"local\" wire:model=\"remote\"><span>child</span>",
        'wire:model="remote"',
    ],
]);

it('does not emit duplicate preset diagnostics for one underlying mistake', function (): void {
    $violations = lintCoreReactivePreset('<a wire:navigate><span>Child</span></a>');

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->ruleId)->toBe('blade-livewire-directive-integrity');
});
