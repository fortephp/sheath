<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Directives\PreferForelseRule;

describe('PreferForelseRule', function (): void {
    it('passes for @forelse blocks', function (): void {
        $this->getRuleTester()->run(new PreferForelseRule, [
            'valid' => [
                '@forelse($items as $item) {{ $item }} @empty No items @endforelse',
                '@forelse ($users as $user) {{ $user->name }} @empty No users @endforelse',
            ],
        ]);
    });

    it('passes for @foreach without wrapping @if', function (): void {
        $this->getRuleTester()->run(new PreferForelseRule, [
            'valid' => [
                '@foreach($items as $item) {{ $item }} @endforeach',
            ],
        ]);
    });

    it('passes for @if without @foreach inside', function (): void {
        $this->getRuleTester()->run(new PreferForelseRule, [
            'valid' => [
                '@if($isAdmin) Admin content @endif',
                '@if(count($items) > 0) Has items @endif',
            ],
        ]);
    });

    it('passes for @if with @foreach but different variable check', function (): void {
        $this->getRuleTester()->run(new PreferForelseRule, [
            'valid' => [
                '@if($isEnabled) @foreach($items as $item) {{ $item }} @endforeach @endif',
            ],
        ]);
    });

    it('fails for @if(count($var) > 0) with @foreach', function (): void {
        $this->getRuleTester()->run(new PreferForelseRule, [
            'invalid' => [
                [
                    'code' => '@if(count($items) > 0) @foreach($items as $item) {{ $item }} @endforeach @endif',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('does not mistake PHP truthiness for an iterable emptiness check', function (): void {
        $this->getRuleTester()->run(new PreferForelseRule, [
            'valid' => [
                '@if($items) @foreach($items as $item) {{ $item }} @endforeach @endif',
                '@if(! empty($items)) @foreach($items as $item) {{ $item }} @endforeach @endif',
            ],
        ]);
    });

    it('fails for @if with @else containing @foreach', function (): void {
        $this->getRuleTester()->run(new PreferForelseRule, [
            'invalid' => [
                [
                    'code' => '@if(count($items) > 0) @foreach($items as $item) {{ $item }} @endforeach @else No items @endif',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    describe('conditions that are not a non-empty check', function (): void {
        it('leaves the condition alone', function (string $code): void {
            $this->getRuleTester()->run(new PreferForelseRule, ['valid' => [$code]]);
        })->with([
            'empty()' => '@if(empty($items)) @foreach($items as $item) {{ $item }} @endforeach @endif',
            'not empty()' => '@if(!empty($items)) @foreach($items as $item) {{ $item }} @endforeach @endif',
            'truthy variable' => '@if($items) @foreach($items as $item) {{ $item }} @endforeach @endif',
            'isEmpty()' => '@if($items->isEmpty()) @foreach($items as $item) {{ $item }} @endforeach @endif',
            'count() === 0' => '@if(count($items) === 0) @foreach($items as $item) {{ $item }} @endforeach @endif',
            'count() >= 0' => '@if(count($items) >= 0) @foreach($items as $item) {{ $item }} @endforeach @endif',
            'count() > 1' => '@if(count($items) > 1) @foreach($items as $item) {{ $item }} @endforeach @endif',
            'other variable' => '@if($showList) @foreach($items as $item) {{ $item }} @endforeach @endif',
            'feature flag' => '@if($user->canSeeList()) @foreach($items as $item) {{ $item }} @endforeach @endif',
            'nullsafe count' => '@if($items?->count()) @foreach($items as $item) {{ $item }} @endforeach @endif',
            'nullsafe isNotEmpty' => '@if($items?->isNotEmpty()) @foreach($items as $item) {{ $item }} @endforeach @endif',
        ]);

        it('still reports the positive forms', function (string $code): void {
            $this->getRuleTester()->run(new PreferForelseRule, [
                'invalid' => [['code' => $code, 'errors' => 1]],
            ]);
        })->with([
            'count() > 0' => '@if(count($items) > 0) @foreach($items as $item) {{ $item }} @endforeach @endif',
            'count() >= 1' => '@if(count($items) >= 1) @foreach($items as $item) {{ $item }} @endforeach @endif',
            'isNotEmpty()' => '@if($items->isNotEmpty()) @foreach($items as $item) {{ $item }} @endforeach @endif',
            'not isEmpty()' => '@if(!$items->isEmpty()) @foreach($items as $item) {{ $item }} @endforeach @endif',
            'isEmpty() === false' => '@if($items->isEmpty() === false) @foreach($items as $item) {{ $item }} @endforeach @endif',
        ]);
    });
});
