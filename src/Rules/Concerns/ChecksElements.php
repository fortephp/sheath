<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Concerns;

use Forte\Ast\Elements\ElementNode;
use Forte\Support\HtmlInteger;

/** @internal */
trait ChecksElements
{
    use DetectsOpaqueAttributes;

    private const INTERACTIVE_ELEMENTS = [
        'button', 'details', 'embed', 'iframe', 'label', 'select', 'textarea',
    ];

    private const CONDITIONALLY_INTERACTIVE = [
        'audio' => ['controls'],
        'img' => ['usemap'],
        'input' => [],
        'video' => ['controls'],
    ];

    private const HEADING_ELEMENTS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    private const FORM_CONTROL_ELEMENTS = [
        'input', 'select', 'textarea', 'button',
    ];

    private const LIST_ELEMENTS = ['ul', 'ol', 'menu', 'dl'];

    private const LIST_ITEM_ELEMENTS = ['li', 'dt', 'dd'];

    protected function isInteractiveElement(ElementNode $element): bool
    {
        $tagName = strtolower($element->tagNameText());

        if (in_array($tagName, self::INTERACTIVE_ELEMENTS, true)) {
            return true;
        }

        if ($tagName === 'a') {
            return $this->hasAttributeOnAnyRenderPath($element, 'href');
        }

        if (isset(self::CONDITIONALLY_INTERACTIVE[$tagName])) {
            $requiredAttrs = self::CONDITIONALLY_INTERACTIVE[$tagName];

            if ($tagName === 'input') {
                $typeAttributes = $this->firstAttributesOnRenderPaths($element, 'type');
                if ($typeAttributes === null) {
                    return false;
                }

                foreach ($typeAttributes as $typeAttribute) {
                    if ($typeAttribute === null) {
                        return true;
                    }

                    if ($typeAttribute->isDynamic()) {
                        continue;
                    }

                    if (strcasecmp($typeAttribute->decodedValueText() ?? '', 'hidden') !== 0) {
                        return true;
                    }
                }

                return false;
            }

            if (empty($requiredAttrs)) {
                return true;
            }

            foreach ($requiredAttrs as $attr) {
                if ($this->hasAttributeOnAnyRenderPath($element, $attr)) {
                    return true;
                }
            }
        }

        $tabindexAttributes = $this->firstAttributesOnRenderPaths($element, 'tabindex');
        foreach ($tabindexAttributes ?? [] as $tabindexAttribute) {
            if ($tabindexAttribute === null) {
                continue;
            }

            if (! $tabindexAttribute->isDynamic()
                && (HtmlInteger::parse($tabindexAttribute->decodedValueText() ?? '') ?? -1) >= 0) {
                return true;
            }
        }

        return false;
    }

    private function hasAttributeOnAnyRenderPath(ElementNode $element, string $name): bool
    {
        $attributes = $this->firstAttributesOnRenderPaths($element, $name);
        if ($attributes === null) {
            return false;
        }

        foreach ($attributes as $attribute) {
            if ($attribute !== null) {
                return true;
            }
        }

        return false;
    }

    protected function isHeadingElement(ElementNode $element): bool
    {
        return $element->isTag(self::HEADING_ELEMENTS);
    }

    protected function isFormControlElement(ElementNode $element): bool
    {
        return $element->isTag(self::FORM_CONTROL_ELEMENTS);
    }

    protected function isListElement(ElementNode $element): bool
    {
        return $element->isTag(self::LIST_ELEMENTS);
    }

    protected function isListItemElement(ElementNode $element): bool
    {
        return $element->isTag(self::LIST_ITEM_ELEMENTS);
    }

    protected function hasTagPrefix(ElementNode $element, string $prefix): bool
    {
        return str_starts_with(strtolower($element->tagNameText()), strtolower($prefix));
    }

    /**
     * @param  string|array<string>  $tags
     */
    protected function matchesTagName(ElementNode $element, string|array $tags): bool
    {
        $tagList = is_array($tags) ? $tags : [$tags];

        return $element->isTag($tagList);
    }

    /**
     * @return array<string>
     */
    protected function getHeadingTagNames(): array
    {
        return self::HEADING_ELEMENTS;
    }

    /**
     * @return array<string>
     */
    protected function getInteractiveTagNames(): array
    {
        return self::INTERACTIVE_ELEMENTS;
    }
}
