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

it('accepts dynamic Livewire loop identities and constants outside loops', function (string $source): void {
    expect(lintLivewireLoopKey($source))->toBe([]);
})->with([
    'dynamic HTML key' => '@foreach($posts as $post)<article wire:key="post-{{ $post->id }}"></article>@endforeach',
    'dynamic component key' => '@foreach($posts as $post)<livewire:post :wire:key="$post->id" />@endforeach',
    'dynamic sort item' => '@foreach($posts as $post)<article wire:sort:item="{{ $post->id }}"></article>@endforeach',
    'static key outside loop' => '<article wire:key="singleton"></article>',
    'ignored loop identity' => '@foreach($posts as $post)<div x-ignore><article wire:key="post"></article></div>@endforeach',
    'conditional dynamic identity' => '@foreach($posts as $post)<article @if($keyed) wire:key="post-{{ $post->id }}" @endif></article>@endforeach',
    'ordinary Blade component owns its wire-looking attribute' => '@foreach($posts as $post)<x-card wire:key="presentation" />@endforeach',
]);

it('reports constant Livewire identities inside Blade loops', function (string $source, string $slice): void {
    $violations = lintLivewireLoopKey($source);

    expect($violations)->toHaveCount(1)
        ->and(substr(
            $source,
            $violations[0]->start->offset,
            $violations[0]->end->offset - $violations[0]->start->offset,
        ))->toBe($slice);
})->with([
    'HTML key' => ['@foreach($posts as $post)<article wire:key="post"></article>@endforeach', 'wire:key="post"'],
    'component key' => ['@foreach($posts as $post)<livewire:post wire:key="post" />@endforeach', 'wire:key="post"'],
    'bound constant key' => ['@foreach($posts as $post)<livewire:post :wire:key="\'post\'" />@endforeach', ':wire:key="\'post\'"'],
    'sort item' => ['@foreach($posts as $post)<article wire:sort:item="post"></article>@endforeach', 'wire:sort:item="post"'],
    'conditional static key' => ['@foreach($posts as $post)<article @if($keyed) wire:key="post" @endif></article>@endforeach', 'wire:key="post"'],
]);
