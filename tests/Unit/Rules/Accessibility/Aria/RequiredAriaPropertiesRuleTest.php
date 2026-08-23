<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Aria\RequiredAriaPropertiesRule;

it('accepts required properties, dynamic values, and native equivalents', function (string $code): void {
    $this->getRuleTester()->run(new RequiredAriaPropertiesRule, ['valid' => [$code]]);
})->with([
    '<div role="checkbox" aria-checked="false"></div>',
    '<div role="checkbox" :aria-checked="checked"></div>',
    '<div role="checkbox" wire:bind:aria-checked="checked"></div>',
    '<div wire:bind:role="role"></div>',
    '<input type="checkbox">',
    '<input type="checkbox" role="checkbox">',
    '<input type="checkbox" role="switch">',
    '<input type="checkbox" role="menuitemcheckbox">',
    '<input type="radio" role="radio">',
    '<input type="radio" role="menuitemradio">',
    '<h2>Heading</h2>',
    '<select><option>One</option></select>',
    '<input type="range">',
    '<input type="range" role="slider">',
    '<input type="number" role="spinbutton">',
    '<meter role="meter" value="5" min="0" max="10"></meter>',
    '<progress role="progressbar" value="5" max="10"></progress>',
    '<div role="slider" aria-valuenow="10"></div>',
    '<div role="comment"></div>',
    '<div role="checkbox" {{ $attributes }}></div>',
]);

it('reports missing or empty required role properties', function (string $code, int $errors): void {
    $this->getRuleTester()->run(new RequiredAriaPropertiesRule, [
        'invalid' => [['code' => $code, 'errors' => array_fill(0, $errors, [])]],
    ]);
})->with([
    ['<div role="checkbox"></div>', 1],
    ['<div role="checkbox" aria-checked=""></div>', 1],
    ['<div role="slider"></div>', 1],
    ['<div role="combobox"></div>', 2],
    ['<div role="scrollbar"></div>', 2],
    ['<input type="text" role="switch">', 1],
    ['<input type="text" role="menuitemcheckbox">', 1],
]);

it('correlates conditional roles and properties on render paths', function (): void {
    $this->getRuleTester()->run(new RequiredAriaPropertiesRule, [
        'valid' => [
            '<div @if($toggle) role="checkbox" aria-checked="false" @else role="button" @endif></div>',
        ],
        'invalid' => [[
            'code' => '<div @if($toggle) role="checkbox" @else role="button" @endif></div>',
            'errors' => 1,
        ]],
    ]);
});

it('does not let unrelated conditional attributes exhaust ARIA path analysis', function (): void {
    $unrelated = implode(' ', array_map(
        static fn (int $index): string => "@if(\$c{$index}) class=\"c{$index}\" @endif",
        range(1, 8),
    ));

    $this->getRuleTester()->run(new RequiredAriaPropertiesRule, [
        'invalid' => [[
            'code' => "<div {$unrelated} role=\"checkbox\"></div>",
            'errors' => 1,
        ]],
    ]);
});

it('requires aria-valuenow only for focusable separators', function (): void {
    $this->getRuleTester()->run(new RequiredAriaPropertiesRule, [
        'valid' => [
            '<div role="separator"></div>',
            '<hr>',
            '<div role="separator" tabindex="0" aria-valuenow="50"></div>',
            '<div role="separator" :tabindex="$tabindex"></div>',
            '<div role="separator" @if($focusable) tabindex="0" aria-valuenow="50" @endif></div>',
            '<button role="separator" disabled tabindex="0"></button>',
            '<fieldset disabled><button role="separator" tabindex="0"></button></fieldset>',
            '<fieldset disabled><legend><button role="separator" tabindex="0" aria-valuenow="50"></button></legend></fieldset>',
            '<fieldset disabled><template x-for="item in items"><legend><button role="separator" tabindex="0"></button></legend></template></fieldset>',
            '<fieldset disabled><template x-if="first"><legend>First</legend></template><legend><button role="separator" tabindex="0"></button></legend></fieldset>',
            '<button role="separator" :disabled="$disabled" tabindex="0"></button>',
            '<div role="doc-chapter separator" tabindex="0"></div>',
        ],
        'invalid' => [
            [
                'code' => '<div role="separator" tabindex="0"></div>',
                'errors' => 1,
            ],
            [
                'code' => '<div role="separator" tabindex="-1"></div>',
                'errors' => 1,
            ],
            [
                'code' => '<hr tabindex="0">',
                'errors' => 1,
            ],
            [
                'code' => '<div role="separator" @if($focusable) tabindex="0" @endif></div>',
                'errors' => 1,
            ],
            [
                'code' => '<fieldset disabled><template x-if="open"><legend><button role="separator" tabindex="0"></button></legend></template></fieldset>',
                'errors' => 1,
            ],
        ],
    ]);
});

it('does not enforce role contracts outside a deterministic accessibility tree', function (string $code): void {
    $this->getRuleTester()->run(new RequiredAriaPropertiesRule, ['valid' => [$code]]);
})->with([
    '<div hidden role="checkbox"></div>',
    '<div inert role="checkbox"></div>',
    '<div aria-hidden="true" role="checkbox"></div>',
    '<section hidden><div role="checkbox"></div></section>',
    '<section @if($hidden) hidden @endif><div role="checkbox"></div></section>',
]);
