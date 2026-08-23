<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Aria;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\EchoNode;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Ast\PhpBlockNode;
use Forte\Ast\PhpTagNode;
use Forte\Sheath\Support\ReactiveAttributeSemantics;
use Forte\Support\HtmlInteger;
use JsonException;

/** @internal */
final class AriaRoleResolver
{
    /** @return list<string> */
    public static function consumedAttributeNames(): array
    {
        return [
            'role', ...array_keys(Aria12Data::properties()),
            'href', 'alt', 'type', 'multiple', 'size', 'scope',
            'tabindex', 'contenteditable', 'disabled', 'controls', 'list', 'id',
            'hidden', 'inert',
        ];
    }

    /**
     * Exact native elements modeled by this resolver. Elements outside this
     * map deliberately stand down instead of guessing HTML-AAM semantics.
     *
     * @return array<string, string>
     */
    public static function implicitRoleMap(): array
    {
        return [
            'article' => 'article',
            'blockquote' => 'blockquote', 'button' => 'button',
            'code' => 'code',
            'dd' => 'definition', 'del' => 'deletion', 'dialog' => 'dialog',
            'div' => 'generic', 'em' => 'emphasis', 'fieldset' => 'group',
            'dt' => 'term', 'figure' => 'figure', 'h1' => 'heading', 'h2' => 'heading',
            'h3' => 'heading', 'h4' => 'heading', 'h5' => 'heading',
            'h6' => 'heading', 'hr' => 'separator', 'ins' => 'insertion',
            'main' => 'main', 'math' => 'math',
            'menu' => 'list', 'meter' => 'meter', 'nav' => 'navigation',
            'ol' => 'list', 'output' => 'status', 'p' => 'paragraph',
            'pre' => 'generic', 'progress' => 'progressbar', 'search' => 'search',
            'span' => 'generic', 'strong' => 'strong', 'sub' => 'subscript',
            'sup' => 'superscript', 'table' => 'table', 'textarea' => 'textbox',
            'ul' => 'list',
        ];
    }

    /**
     * @param  list<Attribute>  $path
     * @return array{role: string, implicit: bool}|null
     *
     * @throws JsonException
     */
    public static function effectiveRole(ElementNode $element, array $path): ?array
    {
        $roleAttribute = self::firstAttribute($path, 'role');
        if ($roleAttribute !== null) {
            if ($roleAttribute->isDynamic()) {
                return null;
            }

            foreach ($roleAttribute->tokensLower() as $candidate) {
                if (Aria12Data::role($candidate) === null) {
                    if (NoInvalidRoleRule::isValidRole($candidate)) {
                        // A standardized extension or later-version role can
                        // be recognized by the user agent before a core-role
                        // fallback. The pinned 1.2 layer cannot safely impose
                        // the later fallback's contract.
                        return null;
                    }

                    continue;
                }

                if (in_array($candidate, ['none', 'presentation'], true)
                    && self::presentationRoleConflicts($element, $path)) {
                    return self::implicitRole($element, $path);
                }

                return ['role' => $candidate, 'implicit' => false];
            }

            // A role list with no ARIA 1.2 fallback is outside this layer's
            // versioned knowledge (for example an extension-module role).
            return null;
        }

        return self::implicitRole($element, $path);
    }

    /** @param list<Attribute> $path */
    public static function nativeSemanticsSatisfy(
        ElementNode $element,
        array $path,
        string $role,
        string $property,
        bool $implicit,
    ): bool {
        if ($implicit) {
            return true;
        }

        $tag = strtolower($element->tagNameText());

        return match ($property) {
            'aria-level' => $role === 'heading' && in_array($tag, ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true),
            'aria-checked' => ($tag === 'input'
                && self::inputType($path) === 'checkbox'
                && in_array($role, ['checkbox', 'menuitemcheckbox', 'switch'], true))
                || ($tag === 'input'
                    && self::inputType($path) === 'radio'
                    && in_array($role, ['menuitemradio', 'radio'], true)),
            'aria-selected' => $role === 'option' && $tag === 'option',
            'aria-valuenow' => ($role === 'meter' && $tag === 'meter')
                || ($role === 'slider' && $tag === 'input' && self::inputType($path) === 'range')
                || ($role === 'spinbutton' && $tag === 'input' && self::inputType($path) === 'number')
                || ($role === 'progressbar' && $tag === 'progress'),
            'aria-controls', 'aria-expanded' => $role === 'combobox' && $tag === 'select',
            default => false,
        };
    }

    /**
     * Whether the separator's current render path makes it focusable. A null
     * result means runtime state prevents a deterministic requirement verdict.
     *
     * @param  list<Attribute>  $path
     */
    public static function separatorIsFocusable(ElementNode $element, array $path): ?bool
    {
        $tag = strtolower($element->tagNameText());
        if (in_array($tag, ['button', 'select', 'textarea'], true)
            || ($tag === 'input' && self::inputType($path) !== 'hidden')) {
            $enabled = self::nativeControlIsEnabled($element, $path);
            if ($enabled !== true) {
                return $enabled;
            }
        }

        $tabindex = self::firstAttribute($path, 'tabindex');
        if ($tabindex !== null) {
            if ($tabindex->isDynamic() || $tabindex->isBound()) {
                return null;
            }

            if (HtmlInteger::parse($tabindex->decodedValueText() ?? '') !== null) {
                return true;
            }
        }

        $contenteditable = self::firstAttribute($path, 'contenteditable');
        if ($contenteditable !== null) {
            if ($contenteditable->isDynamic() || $contenteditable->isBound()) {
                return null;
            }

            $editable = strtolower($contenteditable->decodedValueText() ?? '');
            if (in_array($editable, ['', 'true', 'plaintext-only'], true)) {
                return true;
            }
            if ($editable !== 'false') {
                return null;
            }
        }

        if (in_array($tag, ['button', 'select', 'textarea'], true)) {
            return true;
        }

        if ($tag === 'input') {
            $type = self::inputType($path);
            if ($type === null) {
                return null;
            }

            return $type !== 'hidden';
        }

        if ($tag === 'a' || $tag === 'area') {
            $href = self::firstAttribute($path, 'href');
            if ($href === null) {
                return false;
            }

            return $href->isDynamic() || $href->isBound() ? null : true;
        }

        if ($tag === 'iframe') {
            return true;
        }

        if ($tag === 'audio' || $tag === 'video') {
            $controls = self::firstAttribute($path, 'controls');
            if ($controls === null) {
                return false;
            }

            return $controls->isDynamic() || $controls->isBound() ? null : true;
        }

        return false;
    }

    /**
     * @param  list<Attribute>  $path
     * @return array{role: string, implicit: bool}|null
     */
    private static function implicitRole(ElementNode $element, array $path): ?array
    {
        $tag = strtolower($element->tagNameText());

        if ($tag === 'a' || $tag === 'area') {
            return self::firstAttribute($path, 'href') === null
                ? null
                : ['role' => 'link', 'implicit' => true];
        }

        if ($tag === 'img') {
            $alt = self::firstAttribute($path, 'alt');
            if ($alt !== null && ($alt->isDynamic() || $alt->decodedValueText() === '')) {
                if ($alt->isDynamic()) {
                    return null;
                }

                return self::presentationRoleConflicts($element, $path)
                    ? ['role' => 'img', 'implicit' => true]
                    : ['role' => 'none', 'implicit' => true];
            }

            return ['role' => 'img', 'implicit' => true];
        }

        if ($tag === 'input') {
            $typeAttribute = self::firstAttribute($path, 'type');
            if ($typeAttribute?->isDynamic() || $typeAttribute?->isBound()) {
                return null;
            }

            $type = self::inputType($path);
            if ($type === null || in_array($type, [
                'color', 'date', 'datetime-local', 'file', 'hidden', 'month',
                'password', 'time', 'week',
            ], true)) {
                return null;
            }

            $role = match ($type) {
                'button', 'image', 'reset', 'submit' => 'button',
                'checkbox' => 'checkbox',
                'radio' => 'radio',
                'range' => 'slider',
                'number' => 'spinbutton',
                'search' => 'searchbox',
                'email', 'tel', 'text', 'url' => 'textbox',
                // Invalid values use HTML's missing/invalid-value default,
                // which is the Text state.
                default => 'textbox',
            };

            if (in_array($type, ['email', 'search', 'tel', 'text', 'url'], true)) {
                $hasSuggestions = self::inputHasLinkedDatalist($element, $path);
                if ($hasSuggestions === null) {
                    return null;
                }
                if ($hasSuggestions) {
                    $role = 'combobox';
                }
            }

            return ['role' => $role, 'implicit' => true];
        }

        if ($tag === 'datalist') {
            $linked = self::datalistHasLinkedInput($element);

            return $linked === true ? ['role' => 'listbox', 'implicit' => true] : null;
        }

        if ($tag === 'select') {
            $multiple = self::firstAttribute($path, 'multiple');
            $size = self::firstAttribute($path, 'size');
            if ($multiple?->isDynamic() || $multiple?->isBound()
                || $size?->isDynamic() || $size?->isBound()) {
                return null;
            }
            if ($multiple !== null) {
                return ['role' => 'listbox', 'implicit' => true];
            }
            $parsedSize = $size === null ? null : HtmlInteger::parse($size->decodedValueText() ?? '');

            return ['role' => $parsedSize !== null && $parsedSize > 1 ? 'listbox' : 'combobox', 'implicit' => true];
        }

        if ($tag === 'li') {
            $parent = self::renderedParentElement($element);
            if ($parent !== null && $parent->isTag(['ol', 'menu', 'ul'])) {
                return self::elementMayAuthorRole($parent)
                    ? null
                    : ['role' => 'listitem', 'implicit' => true];
            }

            return ['role' => 'generic', 'implicit' => true];
        }

        if ($tag === 'option') {
            return self::hasAncestorTag($element, ['select', 'datalist'])
                ? ['role' => 'option', 'implicit' => true]
                : null;
        }

        if ($tag === 'td') {
            $tableRole = self::owningTableRole($element);

            return match ($tableRole) {
                'table' => ['role' => 'cell', 'implicit' => true],
                'grid', 'treegrid' => ['role' => 'gridcell', 'implicit' => true],
                default => null,
            };
        }

        if ($tag === 'th') {
            if (! in_array(self::owningTableRole($element), ['table', 'grid', 'treegrid'], true)) {
                return null;
            }

            $scope = self::firstAttribute($path, 'scope');
            if ($scope?->isDynamic() || $scope?->isBound()) {
                return null;
            }

            $scopeValue = strtolower($scope?->decodedValueText() ?? '');
            $role = match ($scopeValue) {
                'row', 'rowgroup' => 'rowheader',
                'col', 'colgroup' => 'columnheader',
                default => null,
            };

            return $role === null ? null : ['role' => $role, 'implicit' => true];
        }

        if (in_array($tag, ['caption', 'tbody', 'tfoot', 'thead', 'tr'], true)) {
            if (! in_array(self::owningTableRole($element), ['table', 'grid', 'treegrid'], true)) {
                return null;
            }

            return [
                'role' => match ($tag) {
                    'caption' => 'caption',
                    'tr' => 'row',
                    default => 'rowgroup',
                },
                'implicit' => true,
            ];
        }

        $role = self::implicitRoleMap()[$tag] ?? null;

        return $role === null ? null : ['role' => $role, 'implicit' => true];
    }

    /** @param list<Attribute> $path */
    private static function inputType(array $path): ?string
    {
        $type = self::firstAttribute($path, 'type');
        if ($type?->isDynamic()) {
            return null;
        }

        return strtolower($type?->decodedValueText() ?? 'text');
    }

    /** @param list<Attribute> $path */
    private static function nativeControlIsEnabled(ElementNode $element, array $path): ?bool
    {
        $disabled = self::firstAttribute($path, 'disabled');
        if ($disabled !== null) {
            return $disabled->isDynamic() || $disabled->isBound() ? null : false;
        }

        return self::disabledByAncestorFieldset($element);
    }

    /** @param list<Attribute> $path */
    private static function inputHasLinkedDatalist(ElementNode $input, array $path): ?bool
    {
        $list = self::firstAttribute($path, 'list');
        if ($list === null) {
            return false;
        }
        if ($list->isDynamic() || $list->isBound()) {
            return null;
        }

        $id = $list->decodedValueText() ?? '';
        if ($id === '') {
            return false;
        }

        $matches = 0;
        foreach ($input->getDocument()->queryElements('datalist') as $candidate) {
            $candidateId = $candidate->attribute('id');
            if ($candidateId === null) {
                if (self::elementMayAuthorAttribute($candidate, 'id')) {
                    return null;
                }

                continue;
            }
            if ($candidateId->isDynamic() || $candidateId->isBound()) {
                return null;
            }
            if (($candidateId->decodedValueText() ?? '') === $id) {
                $matches++;
            }
        }

        return match ($matches) {
            0 => false,
            1 => true,
            default => null,
        };
    }

    private static function datalistHasLinkedInput(ElementNode $datalist): ?bool
    {
        $idAttribute = $datalist->attribute('id');
        if ($idAttribute === null) {
            return self::elementMayAuthorAttribute($datalist, 'id') ? null : false;
        }
        if ($idAttribute->isDynamic() || $idAttribute->isBound()) {
            return null;
        }

        $id = $idAttribute->decodedValueText() ?? '';
        if ($id === '') {
            return false;
        }

        foreach ($datalist->getDocument()->queryElements('input') as $input) {
            $typeAttribute = $input->attribute('type');
            if ($typeAttribute?->isDynamic() || $typeAttribute?->isBound()
                || ($typeAttribute === null && self::elementMayAuthorAttribute($input, 'type'))) {
                return null;
            }

            $type = strtolower($typeAttribute?->decodedValueText() ?? 'text');
            if (! in_array($type, ['email', 'search', 'tel', 'text', 'url'], true)) {
                continue;
            }

            $list = $input->attribute('list');
            if ($list === null) {
                if (self::elementMayAuthorAttribute($input, 'list')) {
                    return null;
                }

                continue;
            }
            if ($list->isDynamic() || $list->isBound()) {
                return null;
            }
            if (($list->decodedValueText() ?? '') === $id) {
                return true;
            }
        }

        return false;
    }

    private static function elementMayAuthorAttribute(ElementNode $element, string $name): bool
    {
        foreach ($element->attributes() as $attribute) {
            if (! $attribute->isBladeConstruct()) {
                if ($attribute->isNamed($name)
                    && ($attribute->isDynamic() || $attribute->isBound())) {
                    return true;
                }

                continue;
            }

            return true;
        }

        return false;
    }

    private static function disabledByAncestorFieldset(ElementNode $element): ?bool
    {
        $ancestor = self::renderedParentElement($element);
        while ($ancestor !== null) {
            if ($ancestor->isTag('fieldset')) {
                $disabled = $ancestor->attribute('disabled');
                if ($disabled === null) {
                    if (self::elementMayAuthorAttribute($ancestor, 'disabled')) {
                        return null;
                    }
                } elseif ($disabled->isDynamic() || $disabled->isBound()) {
                    return null;
                } else {
                    $insideFirstLegend = self::isInsideFirstDirectLegend($element, $ancestor);
                    if ($insideFirstLegend === null) {
                        return null;
                    }
                    if (! $insideFirstLegend) {
                        return false;
                    }
                }
            }

            $ancestor = self::renderedParentElement($ancestor);
        }

        return true;
    }

    private static function isInsideFirstDirectLegend(ElementNode $element, ElementNode $fieldset): ?bool
    {
        $branch = $element;
        $parent = self::renderedParentElement($branch);
        while ($parent !== null && $parent !== $fieldset) {
            $branch = $parent;
            $parent = self::renderedParentElement($branch);
        }

        if ($parent !== $fieldset || ! $branch->isTag('legend')) {
            return false;
        }

        if (self::hasLocalTemplateAncestor($branch, $fieldset, 'x-for')) {
            return null;
        }

        foreach (self::renderedDirectChildElements($fieldset) as $child) {
            if (! $child->isTag('legend')) {
                continue;
            }

            if ($child === $branch) {
                return true;
            }

            if (self::hasLocalTemplateAncestor($child, $fieldset, 'x-if')
                || self::hasLocalTemplateAncestor($child, $fieldset, 'x-for')) {
                return null;
            }

            return false;
        }

        return false;
    }

    /** @return list<ElementNode> */
    private static function renderedDirectChildElements(ElementNode $parent): array
    {
        $elements = [];

        foreach ($parent->children() as $child) {
            if (! $child instanceof ElementNode) {
                continue;
            }

            if (ReactiveAttributeSemantics::isLocalTemplateRenderer($child)) {
                array_push($elements, ...self::renderedDirectChildElements($child));

                continue;
            }

            if (! $child->isTag('template')) {
                $elements[] = $child;
            }
        }

        return $elements;
    }

    private static function hasLocalTemplateAncestor(
        ElementNode $element,
        ElementNode $boundary,
        string $directive,
    ): bool {
        $ancestor = $element->getParent();

        while ($ancestor !== null && $ancestor !== $boundary) {
            if ($ancestor instanceof ElementNode
                && $ancestor->isTag('template')
                && $ancestor->hasAttribute($directive)) {
                return true;
            }

            $ancestor = $ancestor->getParent();
        }

        return false;
    }

    private static function renderedParentElement(ElementNode $element): ?ElementNode
    {
        $parent = $element->getParent();
        while ($parent !== null) {
            if (self::isTeleportBlock($parent)) {
                return null;
            }

            if ($parent instanceof ElementNode) {
                if ($parent->isTag('template')) {
                    if (! ReactiveAttributeSemantics::isLocalTemplateRenderer($parent)) {
                        return null;
                    }

                    $parent = $parent->getParent();

                    continue;
                }

                return $parent;
            }

            $parent = $parent->getParent();
        }

        return null;
    }

    /** @param list<string> $tags */
    private static function hasAncestorTag(ElementNode $element, array $tags): bool
    {
        $ancestor = $element->getParent();
        while ($ancestor !== null) {
            if (self::isTeleportBlock($ancestor)) {
                return false;
            }

            if ($ancestor instanceof ElementNode) {
                if ($ancestor->isTag('template')) {
                    if (! ReactiveAttributeSemantics::isLocalTemplateRenderer($ancestor)) {
                        return false;
                    }
                } elseif (in_array(strtolower($ancestor->tagNameText()), $tags, true)) {
                    return true;
                }
            }

            $ancestor = $ancestor->getParent();
        }

        return false;
    }

    private static function owningTableRole(ElementNode $element): ?string
    {
        $ancestor = $element->getParent();
        while ($ancestor !== null) {
            if (self::isTeleportBlock($ancestor)) {
                return null;
            }

            if ($ancestor instanceof ElementNode) {
                if ($ancestor->isTag('template')) {
                    if (! ReactiveAttributeSemantics::isLocalTemplateRenderer($ancestor)) {
                        return null;
                    }

                    $ancestor = $ancestor->getParent();

                    continue;
                }

                if ($ancestor->isTag('table')) {
                    $role = $ancestor->attribute('role');
                    if ($role === null) {
                        return self::elementMayAuthorRole($ancestor) ? null : 'table';
                    }
                    if ($role->isDynamic() || $role->isBound()) {
                        return null;
                    }

                    foreach ($role->tokensLower() as $candidate) {
                        if (Aria12Data::role($candidate) !== null) {
                            return $candidate;
                        }
                        if (NoInvalidRoleRule::isValidRole($candidate)) {
                            return null;
                        }
                    }

                    return 'table';
                }
            }

            $ancestor = $ancestor->getParent();
        }

        return null;
    }

    private static function isTeleportBlock(Node $node): bool
    {
        return $node instanceof DirectiveBlockNode && $node->isDirectiveNamed('teleport');
    }

    private static function elementMayAuthorRole(ElementNode $element): bool
    {
        foreach ($element->attributes() as $attribute) {
            if (! $attribute->isBladeConstruct()) {
                if ($attribute->isNamed('role')) {
                    return true;
                }

                continue;
            }

            $construct = $attribute->getBladeConstruct();
            if ($construct !== null && self::attributeConstructMayAuthorRole($construct)) {
                return true;
            }
        }

        return false;
    }

    private static function attributeConstructMayAuthorRole(mixed $item): bool
    {
        if ($item instanceof Attribute) {
            return ! $item->isBladeConstruct() && $item->isNamed('role');
        }

        if ($item instanceof EchoNode || $item instanceof PhpBlockNode || $item instanceof PhpTagNode) {
            return true;
        }

        if ($item instanceof DirectiveBlockNode) {
            if (in_array(strtolower($item->nameText()), ['php', 'verbatim'], true)) {
                return true;
            }

            $branches = array_filter([
                $item->startDirective(),
                ...iterator_to_array($item->intermediateDirectives()),
            ]);
            foreach ($branches as $branch) {
                foreach ($branch->children() as $child) {
                    if (self::attributeConstructMayAuthorRole($child)) {
                        return true;
                    }
                }
            }

            return false;
        }

        if ($item instanceof DirectiveNode) {
            $name = strtolower($item->nameText());
            if (! in_array($name, [
                'if', 'elseif', 'else', 'endif', 'unless', 'endunless',
                'class', 'style', 'checked', 'selected', 'disabled', 'readonly', 'required',
            ], true)) {
                return true;
            }
        }

        if ($item instanceof Node) {
            foreach ($item->children() as $child) {
                if (self::attributeConstructMayAuthorRole($child)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param list<Attribute> $path */
    private static function presentationRoleConflicts(ElementNode $element, array $path): bool
    {
        $tag = strtolower($element->tagNameText());
        if (in_array($tag, ['button', 'input', 'select', 'textarea'], true)
            || (($tag === 'a' || $tag === 'area') && self::firstAttribute($path, 'href') !== null)) {
            return true;
        }

        $tabindex = self::firstAttribute($path, 'tabindex');
        if ($tabindex !== null
            && ($tabindex->isDynamic() || HtmlInteger::parse($tabindex->decodedValueText() ?? '') !== null)) {
            return true;
        }

        $contenteditable = self::firstAttribute($path, 'contenteditable');
        if ($contenteditable !== null
            && ($contenteditable->isDynamic()
                || in_array(strtolower($contenteditable->decodedValueText() ?? ''), ['', 'true', 'plaintext-only'], true))) {
            return true;
        }

        foreach (Aria12Data::GLOBAL_PROPERTIES as $property) {
            if (self::firstAttribute($path, $property) !== null) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Attribute> $path */
    private static function firstAttribute(array $path, string $name): ?Attribute
    {
        foreach ($path as $attribute) {
            if ($attribute->isNamed($name)) {
                return $attribute;
            }
        }

        return null;
    }
}
