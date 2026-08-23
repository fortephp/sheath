<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Aria\NoUnsupportedAriaPropertiesRule;

it('accepts role-supported, global, native, and deliberately unmapped semantics', function (string $code): void {
    $this->getRuleTester()->run(new NoUnsupportedAriaPropertiesRule, ['valid' => [$code]]);
})->with([
    '<button aria-pressed="false">Toggle</button>',
    '<a href="/" aria-expanded="false">Open</a>',
    '<div role="checkbox" aria-checked="false"></div>',
    '<custom-element aria-valuenow="10"></custom-element>',
    '<div role="comment" aria-valuenow="10"></div>',
    '<div role="button" :aria-expanded="expanded"></div>',
    '<div :role="role" aria-checked="false"></div>',
    '<div wire:bind:role="role" aria-checked="false"></div>',
    '<div role="button" wire:bind:aria-expanded="expanded"></div>',
    '<div role="button" {{ $attributes }} aria-checked="false"></div>',
    '<select :multiple="$multiple" aria-checked="false"><option>One</option></select>',
    '<input type="password" aria-checked="false">',
    '<input type="file" aria-checked="false">',
    '<input type="color" aria-checked="false">',
    '<input type="date" aria-checked="false">',
    '<th aria-checked="false">Orphan</th>',
    '<td aria-checked="false">Orphan</td>',
    '<table><tr><th scope="rowgroup" aria-sort="ascending">Rows</th></tr></table>',
    '<table><tr><th scope="colgroup" aria-sort="ascending">Columns</th></tr></table>',
    '<table role="grid"><tr><td aria-selected="true">Selected</td></tr></table>',
    '<table role="presentation"><tr><td aria-selected="true">Layout</td></tr></table>',
    '<datalist id="choices" aria-checked="false"><option>One</option></datalist>',
    '<aside aria-checked="false">Scoped content</aside>',
    '<ul><li aria-level="2">Nested item</li></ul>',
    '<ol><li aria-posinset="1">Nested item</li></ol>',
    '<menu><li aria-setsize="2">Nested item</li></menu>',
    '<ul role="presentation"><li aria-level="2">Suppressed item</li></ul>',
    '<ul :role="$role"><li aria-level="2">Dynamic parent</li></ul>',
    '<input type="text" list="choices" aria-expanded="false"><datalist id="choices"><option>A</option></datalist>',
    '<img alt="" aria-label="Chart">',
    '<div role="doc-chapter separator" tabindex="0" aria-valuenow="50"></div>',
    '<p hidden aria-label="x">Hidden</p>',
    '<section inert><p aria-label="x">Hidden</p></section>',
    '<section aria-hidden="true"><p aria-label="x">Hidden</p></section>',
    '<section @if($hidden) hidden @endif><p aria-label="x">Maybe hidden</p></section>',
]);

it('reports unsupported and prohibited properties for explicit and implicit roles', function (string $code): void {
    $this->getRuleTester()->run(new NoUnsupportedAriaPropertiesRule, [
        'invalid' => [['code' => $code, 'errors' => 1]],
    ]);
})->with([
    '<button aria-checked="false">Button</button>',
    '<a href="/" aria-selected="true">Link</a>',
    '<img alt="Chart" aria-checked="false">',
    '<div aria-label="Name"></div>',
    '<div role="none" aria-label="Name"></div>',
    '<input type="text" aria-checked="false">',
    '<select multiple aria-checked="false"><option>One</option></select>',
    '<table><tr><td aria-checked="false">Cell</td></tr></table>',
    '<li aria-level="2">Orphan</li>',
    '<div><li aria-level="2">Invalid parent</li></div>',
    '<table role="grid"><tr><td aria-checked="true">Cell</td></tr></table>',
]);

it('uses the first valid concrete role and conditional render paths', function (): void {
    $this->getRuleTester()->run(new NoUnsupportedAriaPropertiesRule, [
        'valid' => [
            '<div role="future checkbox" aria-checked="false"></div>',
            '<div @if($toggle) role="checkbox" aria-checked="false" @else role="button" aria-pressed="false" @endif></div>',
        ],
        'invalid' => [[
            'code' => '<div role="future button" aria-checked="false"></div>',
            'errors' => 1,
        ]],
    ]);
});

it('does not let unrelated conditional attributes exhaust role-property analysis', function (): void {
    $unrelated = implode(' ', array_map(
        static fn (int $index): string => "@if(\$c{$index}) data-c{$index}=\"1\" @endif",
        range(1, 8),
    ));

    $this->getRuleTester()->run(new NoUnsupportedAriaPropertiesRule, [
        'invalid' => [[
            'code' => "<div {$unrelated} role=\"button\" aria-checked=\"false\"></div>",
            'errors' => 1,
        ]],
    ]);
});
