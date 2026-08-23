<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Rules\Accessibility\Aria\AriaRoleResolver;

/** @return array{role: string, implicit: bool}|null */
function resolvedAriaRole(string $template, string $tag): ?array
{
    $element = Document::parse($template)->findElementsByName($tag)->first();
    expect($element)->toBeInstanceOf(ElementNode::class);
    if (! $element instanceof ElementNode) {
        throw new RuntimeException("Expected a {$tag} element in resolver fixture.");
    }

    $path = [];
    /** @var Attribute $attribute */
    foreach ($element->attributes() as $attribute) {
        $path[] = $attribute;
    }

    return AriaRoleResolver::effectiveRole($element, $path);
}

it('resolves only input types with defined HTML-AAM roles', function (string $type, ?string $role): void {
    expect(resolvedAriaRole("<input type=\"{$type}\">", 'input'))
        ->toBe($role === null ? null : ['role' => $role, 'implicit' => true]);
})->with([
    ['text', 'textbox'],
    ['email', 'textbox'],
    ['search', 'searchbox'],
    ['number', 'spinbutton'],
    ['range', 'slider'],
    ['checkbox', 'checkbox'],
    ['password', null],
    ['file', null],
    ['color', null],
    ['date', null],
    ['week', null],
    ['not-a-real-type', 'textbox'],
]);

it('stands down when an input or select discriminator is dynamic', function (string $template, string $tag): void {
    expect(resolvedAriaRole($template, $tag))->toBeNull();
})->with([
    ['<input :type="$type">', 'input'],
    ['<select :multiple="$multiple"><option>One</option></select>', 'select'],
    ['<select :size="$size"><option>One</option></select>', 'select'],
]);

it('resolves table roles only with enough native context', function (string $template, string $tag, ?string $role): void {
    expect(resolvedAriaRole($template, $tag))
        ->toBe($role === null ? null : ['role' => $role, 'implicit' => true]);
})->with([
    ['<table><tr><th scope="row">Row</th></tr></table>', 'th', 'rowheader'],
    ['<table><tr><th scope="rowgroup">Rows</th></tr></table>', 'th', 'rowheader'],
    ['<table><tr><th scope="col">Column</th></tr></table>', 'th', 'columnheader'],
    ['<table><tr><th scope="colgroup">Columns</th></tr></table>', 'th', 'columnheader'],
    ['<table><tr><th>Ambiguous</th></tr></table>', 'th', null],
    ['<th scope="row">Orphan</th>', 'th', null],
    ['<table><tr><td>Cell</td></tr></table>', 'td', 'cell'],
    ['<td>Orphan</td>', 'td', null],
    ['<table role="grid"><tr><td>Grid cell</td></tr></table>', 'td', 'gridcell'],
    ['<table role="treegrid"><tr><td>Tree grid cell</td></tr></table>', 'td', 'gridcell'],
    ['<table role="presentation"><tr><td>Layout cell</td></tr></table>', 'td', null],
    ['<table :role="$role"><tr><td>Dynamic cell</td></tr></table>', 'td', null],
    ['<table @if($grid) role="grid" @endif><tr><td>Conditional cell</td></tr></table>', 'td', null],
]);

it('stands down on native roles whose HTML-AAM preconditions need wider context', function (string $template, string $tag): void {
    expect(resolvedAriaRole($template, $tag))->toBeNull();
})->with([
    ['<datalist id="choices"><option>One</option></datalist>', 'datalist'],
    ['<aside>Scoped content</aside>', 'aside'],
]);

it('resolves statically linked suggestion controls and datalists', function (): void {
    $template = '<input type="text" list="choices"><datalist id="choices"><option>One</option></datalist>';
    $spacedReference = '<input type="text" list=" choices "><datalist id="choices"></datalist>';

    expect(resolvedAriaRole($template, 'input'))->toBe(['role' => 'combobox', 'implicit' => true])
        ->and(resolvedAriaRole($template, 'datalist'))->toBe(['role' => 'listbox', 'implicit' => true])
        ->and(resolvedAriaRole($spacedReference, 'input'))->toBe(['role' => 'textbox', 'implicit' => true]);
});

it('stands down on dynamic or ambiguous suggestion references', function (string $template, string $tag): void {
    expect(resolvedAriaRole($template, $tag))->toBeNull();
})->with([
    ['<input type="text" :list="$list"><datalist id="choices"></datalist>', 'input'],
    ['<input type="text" list="choices"><datalist :id="$id"></datalist>', 'input'],
    ['<input type="text" list="choices"><datalist id="choices"></datalist><datalist id="choices"></datalist>', 'input'],
]);

it('resolves list items only under an unmodified native list parent', function (string $template, string $role): void {
    expect(resolvedAriaRole($template, 'li'))->toBe(['role' => $role, 'implicit' => true]);
})->with([
    ['<ul><li>Item</li></ul>', 'listitem'],
    ['<ol><li>Item</li></ol>', 'listitem'],
    ['<menu><li>Item</li></menu>', 'listitem'],
    ['<li>Orphan</li>', 'generic'],
    ['<div><li>Invalid parent</li></div>', 'generic'],
]);

it('stands down on list items whose native parent role is authored', function (string $template): void {
    expect(resolvedAriaRole($template, 'li'))->toBeNull();
})->with([
    '<ul role="presentation"><li>Item</li></ul>',
    '<ul :role="$role"><li>Item</li></ul>',
    '<ul @if($flat) role="presentation" @endif><li>Item</li></ul>',
]);

it('resolves native context through Alpine local templates without crossing detached templates', function (
    string $template,
    string $tag,
    ?string $role,
): void {
    expect(resolvedAriaRole($template, $tag))
        ->toBe($role === null ? null : ['role' => $role, 'implicit' => true]);
})->with([
    'x-if list item' => ['<ul><template x-if="visible"><li>Item</li></template></ul>', 'li', 'listitem'],
    'x-for list item' => ['<ol><template x-for="item in items"><li>Item</li></template></ol>', 'li', 'listitem'],
    'native template list item' => ['<ul><template><li>Item</li></template></ul>', 'li', 'generic'],
    'teleported list item' => ['<ul><template x-teleport="body"><li>Item</li></template></ul>', 'li', 'generic'],
    'x-if table cell' => ['<table><template x-if="visible"><tr><td>Cell</td></tr></template></table>', 'td', 'cell'],
    'x-for table cell' => ['<table><template x-for="row in rows"><tr><td>Cell</td></tr></template></table>', 'td', 'cell'],
    'native template table cell' => ['<table><template><tr><td>Cell</td></tr></template></table>', 'td', null],
    'x-if option' => ['<select><template x-if="visible"><option>One</option></template></select>', 'option', 'option'],
    'native template option' => ['<select><template><option>One</option></template></select>', 'option', null],
]);
