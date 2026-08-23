<?php

declare(strict_types=1);

use Forte\Sheath\Rules\BestPractices\Attributes\ButtonTypeRule;

describe('ButtonTypeRule', function (): void {
    it('passes for buttons with valid type attribute', function (string $code): void {
        $this->getRuleTester()->run(new ButtonTypeRule, ['valid' => [$code]]);
    })->with([
        'type button' => '<button type="button">Click me</button>',
        'type submit' => '<button type="submit">Submit</button>',
        'type reset' => '<button type="reset">Reset</button>',
        'with blade syntax' => '<button type="button">{{ $label }}</button>',
        'with special chars in attr' => '<button type="button" data-info="a > b">Click</button>',
        'with title containing >' => '<button type="submit" title="Use > to continue">Submit</button>',
    ]);

    it('skips dynamic type attributes', function (string $code): void {
        $this->getRuleTester()->run(new ButtonTypeRule, ['valid' => [$code]]);
    })->with([
        'alpine bound' => '<button :type="buttonType">Click</button>',
        'blade interpolation' => '<button type="{{ $type }}">Click</button>',
        'blade ternary' => '<button type="{{ $isSubmit ? \'submit\' : \'button\' }}">Click</button>',
    ]);

    it('fails for buttons without type attribute', function (string $code): void {
        $this->getRuleTester()->run(new ButtonTypeRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'plain button' => '<button>Click me</button>',
        'button with class' => '<button class="btn">Submit</button>',
        'button with blade' => '<button>{{ $label }}</button>',
        'button with special chars' => '<button data-info="a > b">Click</button>',
    ]);

    it('fails for buttons with invalid type value', function (string $code): void {
        $this->getRuleTester()->run(new ButtonTypeRule, [
            'invalid' => [['code' => $code, 'errors' => 1]],
        ]);
    })->with([
        'type link' => ['<button type="link">Click me</button>'],
        'type custom' => ['<button type="custom">Click me</button>'],
        'empty type' => ['<button type="">Click me</button>'],
    ]);

    it('validates the first type attribute emitted on each render path', function (): void {
        $this->getRuleTester()->run(new ButtonTypeRule, [
            'valid' => [
                '<button type="button" @if($legacy) type="bogus" @endif>Save</button>',
            ],
        ]);
    });

    it('fixes to the type the button already has implicitly', function (string $code, string $output): void {
        $this->getRuleTester()->run(new ButtonTypeRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
                'output' => $output,
            ]],
        ]);
    })->with([
        'standalone button' => [
            '<button>Click me</button>',
            '<button type="button">Click me</button>',
        ],
        'button inside a form' => [
            '<form method="post" action="/save">@csrf<button>Save</button></form>',
            '<form method="post" action="/save">@csrf<button type="submit">Save</button></form>',
        ],
        'button nested deeper in a form' => [
            '<form method="post" action="/save"><div class="actions"><button>Save</button></div></form>',
            '<form method="post" action="/save"><div class="actions"><button type="submit">Save</button></div></form>',
        ],
        'button associated via form attribute' => [
            '<button form="edit-profile">Save</button>',
            '<button form="edit-profile" type="submit">Save</button>',
        ],
    ]);

    it('detects multiple buttons without type', function (): void {
        $this->getRuleTester()->run(new ButtonTypeRule, [
            'invalid' => [[
                'code' => '<button>One</button><button>Two</button>',
                'errors' => 2,
            ]],
        ]);
    });

    describe('fix danger in fragments', function (): void {
        it('marks the fix dangerous for a fragment button with no in-file form', function (string $code): void {
            $this->getRuleTester()->run(new ButtonTypeRule, [
                'invalid' => [[
                    'code' => $code,
                    'errors' => [[
                        'hasFixAvailable' => true,
                        'hasDangerousFix' => true,
                    ]],
                ]],
            ]);
        })->with([
            'bare fragment button' => '<button>Click me</button>',
            'wrapped fragment button' => '<div class="actions"><button>Save</button></div>',
        ]);

        it('keeps the fix safe when the file shows the whole page', function (): void {
            $this->getRuleTester()->run(new ButtonTypeRule, [
                'invalid' => [[
                    'code' => '<html lang="en"><body><button>Menu</button></body></html>',
                    'errors' => [[
                        'hasFixAvailable' => true,
                        'hasDangerousFix' => false,
                    ]],
                ]],
            ]);
        });

        it('keeps the in-form submit fix safe', function (): void {
            $this->getRuleTester()->run(new ButtonTypeRule, [
                'invalid' => [[
                    'code' => '<form method="post"><button>Save</button></form>',
                    'errors' => [[
                        'hasFixAvailable' => true,
                        'hasDangerousFix' => false,
                    ]],
                ]],
            ]);
        });

        it('preserves implicit submit behavior for conditional form association', function (): void {
            $this->getRuleTester()->run(new ButtonTypeRule, [
                'invalid' => [[
                    'code' => '<html><body><form id="edit"></form><button @if($save) form="edit" @endif>Save</button></body></html>',
                    'errors' => [['hasFixAvailable' => true, 'hasDangerousFix' => false]],
                    'output' => '<html><body><form id="edit"></form><button @if($save) form="edit" @endif type="submit">Save</button></body></html>',
                ]],
            ]);
        });

        it('withholds an unconditional fix when type already exists on one path', function (): void {
            $this->getRuleTester()->run(new ButtonTypeRule, [
                'invalid' => [[
                    'code' => '<button @if($cancel) type="button" @endif>Save</button>',
                    'errors' => [['hasFixAvailable' => false]],
                ]],
            ]);
        });
    });
});
