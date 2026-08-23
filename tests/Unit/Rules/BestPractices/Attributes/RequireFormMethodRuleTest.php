<?php

declare(strict_types=1);

use Forte\Sheath\Rules\BestPractices\Attributes\RequireFormMethodRule;

describe('RequireFormMethodRule', function (): void {
    it('passes for forms with method attribute', function (): void {
        $this->getRuleTester()->run(new RequireFormMethodRule, [
            'valid' => [
                '<form method="GET" action="/search"></form>',
                '<form method="POST" action="/submit"></form>',
                '<form method="get" action="/search"></form>',
                '<form method="post" action="/submit"></form>',
                '<dialog><form method="dialog"><button>Close</button></form></dialog>',
                '<dialog><form method="DIALOG"><button>Close</button></form></dialog>',
            ],
        ]);
    });

    it('passes for forms with method and other attributes', function (): void {
        $this->getRuleTester()->run(new RequireFormMethodRule, [
            'valid' => [
                '<form method="POST" action="/submit" enctype="multipart/form-data"></form>',
                '<form class="form" method="GET" id="search-form"></form>',
            ],
        ]);
    });

    it('passes for forms whose method is resolved at runtime', function (): void {
        $this->getRuleTester()->run(new RequireFormMethodRule, [
            'valid' => [
                '<form method="{{ $method }}"></form>',
                '<form :method="$method"></form>',
                '<form @if($custom) {{ $attributes }} @else method="post" @endif></form>',
            ],
        ]);
    });

    it('passes when every path durably intercepts native submission', function (string $code): void {
        $this->getRuleTester()->run(new RequireFormMethodRule, ['valid' => [$code]]);
    })->with([
        'Livewire submit' => '<form wire:submit="save"></form>',
        'Livewire explicit prevent' => '<form wire:submit.prevent="save"></form>',
        'Livewire action modifiers' => '<form wire:submit.async.renderless.debounce.250ms="save"></form>',
        'Alpine longhand' => '<form x-data x-on:submit.prevent="save()"></form>',
        'Alpine shorthand' => '<form x-data @submit.prevent="save()"></form>',
        'Alpine object binding' => '<form x-data x-bind="formBindings"></form>',
        'method or Livewire on complementary branches' => '<form @if($native) method="post" @else wire:submit="save" @endif></form>',
        'method or Alpine across correlated blocks' => '<form @if($native) method="post" @endif @if(!$native) @submit.prevent="save()" @endif></form>',
    ]);

    it('does not mistake non-preventing or non-durable listeners for a form method', function (string $code): void {
        $this->getRuleTester()->run(new RequireFormMethodRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'Alpine listener without prevent' => '<form x-data x-on:submit="save()"></form>',
        'Alpine shorthand without prevent' => '<form x-data @submit.stop="save()"></form>',
        'Livewire once' => '<form wire:submit.once="save"></form>',
        'Livewire passive' => '<form wire:submit.passive="save"></form>',
        'Livewire outside' => '<form wire:submit.outside="save"></form>',
        'Alpine away' => '<form x-data @submit.prevent.away="save()"></form>',
        'conditional Livewire submit' => '<form @if($reactive) wire:submit="save" @endif></form>',
        'unrelated Livewire action' => '<form wire:click="save"></form>',
        'unrelated Livewire binding' => '<form wire:bind:class="classes"></form>',
        'unrelated Alpine binding' => '<form x-bind:class="classes"></form>',
        'empty Alpine object binding' => '<form x-bind=""></form>',
        'ignored Alpine submit listener' => '<form x-ignore @submit.prevent="save()"></form>',
        'Alpine submit listener below ignored subtree' => '<div x-ignore><form @submit.prevent="save()"></form></div>',
        'Livewire submit listener below ignored subtree' => '<div x-ignore><form wire:submit="save"></form></div>',
    ]);

    it('keeps Livewire submit active when x-ignore is on the same element', function (): void {
        $this->getRuleTester()->run(new RequireFormMethodRule, [
            'valid' => ['<form x-ignore wire:submit="save"></form>'],
        ]);
    });

    it('still validates an authored native method on intercepted forms', function (): void {
        $this->getRuleTester()->run(new RequireFormMethodRule, [
            'invalid' => [[
                'code' => '<form method="PUT" wire:submit="save"></form>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for forms without method', function (): void {
        $this->getRuleTester()->run(new RequireFormMethodRule, [
            'invalid' => [
                [
                    'code' => '<form action="/submit"></form>',
                    'errors' => 1,
                ],
                [
                    'code' => '<FORM></FORM>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for empty forms without method', function (): void {
        $this->getRuleTester()->run(new RequireFormMethodRule, [
            'invalid' => [
                [
                    'code' => '<form></form>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for empty or unsupported static methods', function (string $code): void {
        $this->getRuleTester()->run(new RequireFormMethodRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'bare method' => '<form method></form>',
        'empty method' => '<form method=""></form>',
        'unsupported method' => '<form method="PUT"></form>',
    ]);

    it('validates the first method attribute emitted on each render path', function (): void {
        $this->getRuleTester()->run(new RequireFormMethodRule, [
            'valid' => [
                '<form method="get" @if($legacy) method="bogus" @endif></form>',
            ],
        ]);
    });

    it('reports multiple forms without method', function (): void {
        $this->getRuleTester()->run(new RequireFormMethodRule, [
            'invalid' => [
                [
                    'code' => '<form action="/a"></form><form action="/b"></form>',
                    'errors' => 2,
                ],
            ],
        ]);
    });
});
