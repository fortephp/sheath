<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Aria\NoUnknownAriaAttributesRule;

it('accepts every ARIA 1.2 attribute and dynamic values', function (): void {
    $this->getRuleTester()->run(new NoUnknownAriaAttributesRule, [
        'valid' => [
            '<div aria-label="Name" aria-controls="panel"></div>',
            '<div aria-expanded="{{ $expanded }}"></div>',
            '<div :aria-checked="checked"></div>',
            '<div x-bind:aria-checked="checked"></div>',
            '<div wire:bind:aria-checked="checked"></div>',
        ],
    ]);
});

it('reports unknown and post-1.2 ARIA attributes', function (string $code): void {
    $this->getRuleTester()->run(new NoUnknownAriaAttributesRule, [
        'invalid' => [[
            'code' => $code,
            'errors' => 1,
        ]],
    ]);
})->with([
    '<button aria-labl="Save">Save</button>',
    '<div aria-description="Details"></div>',
    '<div hidden aria-labl="Hidden"></div>',
    '<div wire:bind:aria-labl="label"></div>',
]);
