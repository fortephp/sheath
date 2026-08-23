<?php

declare(strict_types=1);

use Forte\Sheath\Rules\BestPractices\Attributes\NoDuplicateAttrsRule;
use Forte\Sheath\Rules\BestPractices\Attributes\NoDuplicateIdRule;

describe('duplicate attributes across Blade render paths', function (): void {
    it('reports duplicates that can coexist at runtime', function (string $code): void {
        $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'direct and conditional' => '<div title="A" @if($verbose) title="B" @endif></div>',
        'same conditional branch' => '<div @if($verbose) title="A" title="B" @endif></div>',
        'nested conditional branch' => '<div @if($a) title="A" @if($b) title="B" @endif @endif></div>',
        'repeated loop output' => '<div @foreach($titles as $title) title="{{ $title }}" @endforeach></div>',
        'repeated forelse output' => '<div @forelse($titles as $title) title="{{ $title }}" @empty @endforelse></div>',
        'switch fallthrough' => '<div @switch($kind) @case("a") title="A" @case("b") title="B" @break @default @endswitch></div>',
        'switch conditional break' => '<div @switch($kind) @case("a") title="A" @break($stop) @case("b") title="B" @break @default @endswitch></div>',
    ]);

    it('accepts the same attribute in mutually exclusive branches', function (): void {
        $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
            'valid' => [
                '<div @if($a) title="A" @else title="B" @endif></div>',
                '<div @if($a) title="A" @elseif($b) title="B" @else title="C" @endif></div>',
                '<div @switch($kind) @case("a") title="A" @break @case("b") title="B" @break @default title="C" @endswitch></div>',
            ],
        ]);
    });
});

describe('duplicate IDs in conditional attributes', function (): void {
    it('reports a static ID that can collide on a rendered path', function (string $code): void {
        $this->getRuleTester()->run(new NoDuplicateIdRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'conditional and direct elements' => [
            '<div id="panel"></div><section @if($shown) id="panel" @endif></section>',
        ],
        'two conditional elements' => [
            '<div @if($shown) id="panel" @endif></div><section @if($shown) id="panel" @endif></section>',
        ],
        'conditional ID inside a repeated element' => [
            '@foreach($items as $item)<div @if($item->shown) id="panel" @endif></div>@endforeach',
        ],
    ]);

    it('keeps elements in mutually exclusive render branches non-conflicting', function (): void {
        $this->getRuleTester()->run(new NoDuplicateIdRule, [
            'valid' => [
                '@if($a)<div @if($shown) id="panel" @endif></div>@else<section id="panel"></section>@endif',
            ],
        ]);
    });
});
