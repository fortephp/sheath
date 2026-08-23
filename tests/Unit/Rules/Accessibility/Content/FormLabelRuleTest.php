<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Content\FormLabelRule;

describe('FormLabelRule', function (): void {
    it('accepts labels populated by Alpine or Livewire on every render path', function (): void {
        $this->getRuleTester()->run(new FormLabelRule, [
            'valid' => [
                '<label for="email" x-text="label"></label><input id="email">',
                '<label for="email" x-html="labelHtml"></label><input id="email">',
                '<label for="email" wire:text="label"></label><input id="email">',
                '<label for="email" @if($live) wire:text="label" @else x-text="fallback" @endif></label><input id="email">',
            ],
            'invalid' => [[
                'code' => '<label for="email" x-text=""></label><input id="email">',
                'errors' => 1,
            ], [
                'code' => '<label for="email" wire:text=""></label><input id="email">',
                'errors' => 1,
            ], [
                'code' => '<label for="email" @if($live) wire:text="label" @endif></label><input id="email">',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for inputs with associated labels', function (string $code): void {
        $this->getRuleTester()->run(new FormLabelRule, ['valid' => [$code]]);
    })->with([
        'label with for attribute' => '<label for="email">Email</label><input type="text" id="email">',
        'label with name id' => '<label for="name">Name</label><input type="text" id="name">',
        'identical Blade expression' => '<label for="{{ $id }}">Name</label><input type="text" id="{{ $id }}">',
        'identical deterministic expression' => '<label for="field-{{ $index }}">Name</label><input type="text" id="field-{{ $index }}">',
        'identical bound expression' => '<label :for="$id">Name</label><input type="text" :id="$id">',
        'label and control in same branch' => '@if($show)<label for="name">Name</label><input id="name">@endif',
        'label outside Alpine teleport' => '<label for="name">Name</label><template x-teleport="body"><input id="name"></template>',
        'matching Alpine visibility' => '<label for="name" x-show="open">Name</label><input id="name" wire:show="open">',
        'matching Livewire loading state' => '<label for="name" wire:loading wire:target="save">Name</label><input id="name" wire:loading wire:target="save">',
        'same Alpine template' => '<template x-if="open"><label for="name">Name</label><input id="name"></template>',
    ]);

    it('requires explicit labels on every path that renders the control', function (string $code): void {
        $this->getRuleTester()->run(new FormLabelRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'optional label' => '@if($show)<label for="name">Name</label>@endif<input id="name">',
        'mutually exclusive label and control' => '@if($show)<label for="name">Name</label>@else<input id="name">@endif',
        'label in inert template' => '<template><label for="name">Name</label></template><input id="name">',
        'conditionally visible Alpine label' => '<label for="name" x-show="open">Name</label><input id="name">',
        'label in a separate Alpine template' => '<template x-if="open"><label for="name">Name</label></template><input id="name">',
    ]);

    it('does not associate different dynamic label targets', function (): void {
        $this->getRuleTester()->run(new FormLabelRule, [
            'invalid' => [
                [
                    'code' => '<label for="{{ $labelId }}">Name</label><input type="text" id="{{ $inputId }}">',
                    'errors' => 1,
                ],
                [
                    'code' => '<label for="{{ $other }}">Name<input type="text" id="{{ $id }}"></label>',
                    'errors' => 1,
                ],
                [
                    'code' => '<label :for="target">Name<input type="text" id="name"></label>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('requires label text on every rendered path', function (): void {
        $this->getRuleTester()->run(new FormLabelRule, [
            'invalid' => [[
                'code' => '<label for="name">@if($show)Name@endif</label><input id="name">',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for inputs with accessible name attributes', function (string $code): void {
        $this->getRuleTester()->run(new FormLabelRule, ['valid' => [$code]]);
    })->with([
        'aria-label on text input' => '<input type="text" aria-label="Search">',
        'aria-label on email input' => '<input type="email" aria-label="Email address">',
        'aria-labelledby' => '<span id="email-label">Email</span><input type="text" aria-labelledby="email-label">',
        'title attribute' => '<input type="text" title="Enter your name">',
    ]);

    it('passes for inputs that do not require labels', function (string $code): void {
        $this->getRuleTester()->run(new FormLabelRule, ['valid' => [$code]]);
    })->with([
        'hidden input' => '<input type="hidden" name="token" value="abc">',
        'submit input' => '<input type="submit" value="Submit">',
        'button input' => '<input type="button" value="Click">',
        'reset input' => '<input type="reset" value="Reset">',
    ]);

    it('passes for select and textarea with accessible names', function (string $code): void {
        $this->getRuleTester()->run(new FormLabelRule, ['valid' => [$code]]);
    })->with([
        'select with aria-label' => '<select aria-label="Country"><option>USA</option></select>',
        'textarea with label' => '<label for="msg">Message</label><textarea id="msg"></textarea>',
    ]);

    it('passes for controls wrapped in a label', function (string $code): void {
        $this->getRuleTester()->run(new FormLabelRule, ['valid' => [$code]]);
    })->with([
        'label wrapping input' => '<label>Name <input type="text" name="name"></label>',
        'label wrapping select' => '<label>Country <select name="country"><option>USA</option></select></label>',
        'label wrapping textarea' => '<label>Message <textarea name="message"></textarea></label>',
        'label wrapping input in a span' => '<label><span>Name</span> <span><input type="text" name="name"></span></label>',
        'label wrapping input behind a directive' => '<label>Name @if($x)<input type="text" name="name">@endif</label>',
        'dynamic visible label' => '<label for="name">{{ $label }}</label><input type="text" id="name">',
    ]);

    it('fails when aria-labelledby has no target in a complete document', function (): void {
        $this->getRuleTester()->run(new FormLabelRule, [
            'invalid' => [[
                'code' => '<!doctype html><html><body><input type="text" aria-labelledby="missing"></body></html>',
                'errors' => 1,
            ]],
        ]);
    });

    it('matches label targets to exact untrimmed HTML ids', function (): void {
        $this->getRuleTester()->run(new FormLabelRule, [
            'invalid' => [[
                'code' => '<label for="foo">Name</label><input id=" foo ">',
                'errors' => 1,
            ]],
        ]);
    });

    it('skips inputs with dynamic type attributes', function (string $code): void {
        $this->getRuleTester()->run(new FormLabelRule, ['valid' => [$code]]);
    })->with([
        'alpine bound' => '<input :type="inputType" name="field">',
        'blade interpolation' => '<input type="{{ $type }}" name="field">',
        'blade ternary' => '<input type="{{ $isPassword ? \'password\' : \'text\' }}" name="field">',
    ]);

    it('evaluates excluded input types on every conditional attribute path', function (): void {
        $this->getRuleTester()->run(new FormLabelRule, [
            'valid' => [
                '<input @if($a) type="hidden" @else type="hidden" @endif>',
                '<input @if($a) type="button" @else type="submit" @endif value="Go">',
                '<input @if($visible) type="text" aria-label="Name" @else type="hidden" @endif>',
            ],
            'invalid' => [[
                'code' => '<input @if($a) type="hidden" @endif>',
                'errors' => 1,
            ]],
        ]);
    });

    it('ignores form controls outside the accessibility tree', function (string $code): void {
        $this->getRuleTester()->run(new FormLabelRule, ['valid' => [$code]]);
    })->with([
        'hidden input' => '<input type="text" hidden>',
        'aria-hidden select' => '<select aria-hidden="true"></select>',
        'inert ancestor' => '<section inert><textarea></textarea></section>',
    ]);

    it('fails for inputs without labels', function (string $code): void {
        $this->getRuleTester()->run(new FormLabelRule, [
            'invalid' => [['code' => $code, 'errors' => 1]],
        ]);
    })->with([
        'text input' => ['<input type="text" name="email">'],
        'email input' => ['<input type="email" name="email">'],
        'password input' => ['<input type="password" name="pass">'],
        'checkbox input' => ['<input type="checkbox" name="agree">'],
        'select' => ['<select name="country"><option>USA</option></select>'],
        'textarea' => ['<textarea name="message"></textarea>'],
    ]);

    it('uses the text state for empty, bare, and invalid static input types', function (string $code): void {
        $this->getRuleTester()->run(new FormLabelRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'empty' => '<input type="">',
        'bare' => '<input type>',
        'unknown' => '<input type="not-a-real-state">',
        'whitespace is not a keyword' => '<input type=" text ">',
        'conditional empty value' => '<input @if($empty) type="" @else type="hidden" @endif>',
    ]);

    it('fails when an associated label has no accessible content', function (string $code): void {
        $this->getRuleTester()->run(new FormLabelRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'empty explicit label' => '<label for="x"></label><input id="x">',
        'whitespace explicit label' => '<label for="x">   </label><input id="x">',
        'empty implicit label' => '<label><span></span><input id="x"></label>',
        'aria-hidden label text' => '<label for="x"><span aria-hidden="true">Name</span></label><input id="x">',
    ]);

    it('only treats the first labelable descendant as implicitly labeled', function (): void {
        $this->getRuleTester()->run(new FormLabelRule, [
            'invalid' => [[
                'code' => '<label>Range <input id="from"> <input id="to"></label>',
                'errors' => 1,
            ]],
        ]);
    });

    it('does not let template contents claim the outer label implicit association', function (): void {
        $this->getRuleTester()->run(new FormLabelRule, [
            'invalid' => [[
                'code' => '<label>Name<template><input></template><input></label>',
                'errors' => [[

                    'column' => 22,
                ]],
            ]],
        ]);
    });

    it('does not count labels made unreachable by loop control flow', function (): void {
        $this->getRuleTester()->run(new FormLabelRule, [
            'invalid' => [[
                'code' => '@foreach($xs as $x)<input id="a">@continue<label for="a">Name</label>@endforeach',
                'errors' => 1,
            ]],
        ]);
    });

    it('handles many forward label associations without rescanning every document node', function (): void {
        $controls = [];
        $labels = [];
        for ($index = 0; $index < 1200; $index++) {
            $controls[] = '<input id="field-'.$index.'">';
            $labels[] = '<label for="field-'.$index.'">Field '.$index.'</label>';
        }

        $this->getRuleTester()->run(new FormLabelRule, [
            'valid' => [implode('', $controls).implode('', $labels)],
        ]);
    });
});
