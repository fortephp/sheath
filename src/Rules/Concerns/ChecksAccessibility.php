<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Concerns;

use Forte\Ast\Components\ComponentNode;
use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\EchoNode;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\EscapeNode;
use Forte\Ast\Node;
use Forte\Ast\PhpBlockNode;
use Forte\Ast\PhpTagNode;
use Forte\Ast\TextNode;
use Forte\Ast\VerbatimNode;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Support\ReactiveAttributeSemantics;
use Forte\Support\HtmlWhitespace;

/** @internal */
trait ChecksAccessibility
{
    use ResolvesAccessibilityTree;

    /** @var array<string, true> */
    private const NON_RENDERING_DIRECTIVES = [
        'csrf' => true,
        'method' => true,
        'props' => true,
        'aware' => true,
        'inject' => true,
        'use' => true,
    ];

    protected function hasAriaLabel(ElementNode $element): bool
    {
        return $this->hasNonEmptyAccessibleNameAttribute($element, 'aria-label');
    }

    protected function hasAriaLabelledBy(ElementNode $element): bool
    {
        $attribute = $element->attribute('aria-labelledby');
        if ($attribute === null) {
            return false;
        }

        return $this->ariaLabelledByAttributeProvidesName($element, $attribute);
    }

    protected function ariaLabelledByAttributeProvidesName(ElementNode $element, Attribute $attribute): bool
    {
        if ($attribute->isDynamic()) {
            return true;
        }

        $idReferences = $attribute->tokens();
        if ($idReferences === []) {
            return false;
        }

        $visited = [];
        $resolvedTarget = false;

        foreach ($idReferences as $id) {
            $target = $this->elementByIdInTree($element, $id);
            if ($target === null) {
                continue;
            }

            $resolvedTarget = true;

            if ($this->nodeRendersWhenever($target, $element)
                && ReactiveAttributeSemantics::elementRendersWhenever($target, $element)
                && $this->elementContributesAccessibleName($target, $visited, includeHidden: true)) {
                return true;
            }
        }

        if ($resolvedTarget) {
            return false;
        }

        // A partial may reference a label supplied by its parent layout. In a
        // complete document, however, an unresolved static IDREF is invalid.
        return $element->getDocument()->queryElements('html')->isEmpty();
    }

    /**
     * @param  list<Attribute>  $path
     * @param  list<string>  $names
     */
    protected function attributePathProvidesAccessibleName(
        ElementNode $element,
        array $path,
        array $names,
    ): bool {
        foreach ($names as $name) {
            $attribute = $this->firstAccessibilityAttributeOnPath($path, $name);
            if ($attribute === null) {
                continue;
            }

            if ($name === 'aria-labelledby') {
                if ($this->ariaLabelledByAttributeProvidesName($element, $attribute)) {
                    return true;
                }

                continue;
            }

            if (in_array($name, ReactiveAttributeSemantics::CLIENT_TEXT_DIRECTIVES, true)) {
                if (ReactiveAttributeSemantics::clientTextMayBeNonEmpty($attribute, $element)) {
                    return true;
                }

                continue;
            }

            if ($this->attributeMayHaveNonEmptyValue($attribute)) {
                return true;
            }
        }

        return false;
    }

    protected function hasTitleAttribute(ElementNode $element): bool
    {
        return $this->hasNonEmptyAccessibleNameAttribute($element, 'title');
    }

    protected function hasAccessibleName(ElementNode $element): bool
    {
        return $this->hasAriaLabel($element)
            || $this->hasAriaLabelledBy($element)
            || $this->hasTitleAttribute($element);
    }

    /**
     * @param  array<int, true>  $visited
     */
    private function elementContributesAccessibleName(
        ElementNode $element,
        array &$visited,
        bool $includeHidden = false,
    ): bool {
        if (isset($visited[$element->index()])) {
            return false;
        }

        $visited[$element->index()] = true;

        if ($this->hasAriaLabel($element)
            || $this->hasTitleAttribute($element)) {
            return true;
        }

        $labelledBy = $element->attribute('aria-labelledby');
        if ($labelledBy !== null) {
            if ($labelledBy->isDynamic()) {
                return true;
            }

            foreach ($labelledBy->tokens() as $id) {
                $target = $this->elementByIdInTree($element, $id);
                if ($target !== null
                    && $this->nodeRendersWhenever($target, $element)
                    && ReactiveAttributeSemantics::elementRendersWhenever($target, $element)
                    && $this->elementContributesAccessibleName($target, $visited, includeHidden: true)) {
                    return true;
                }
            }
        }

        if ($element->isTag('img')
            && $this->hasNonEmptyAccessibleNameAttribute($element, 'alt')) {
            return true;
        }

        return $this->elementTextContentWithVisited($element, $visited, $includeHidden);
    }

    protected function hasTextContent(ElementNode $element): bool
    {
        $visited = [];

        return $this->elementTextContentWithVisited($element, $visited);
    }

    /** @param array<int, true> $visited */
    private function elementTextContentWithVisited(
        ElementNode $element,
        array &$visited,
        bool $includeHidden = false,
    ): bool {
        $staticContent = $this->hasTextContentWithVisited($element, $visited, $includeHidden, $element);

        return $this->clientRenderedTextVerdict($element, $staticContent);
    }

    /**
     * @param  array<int, true>  $visited
     */
    private function hasTextContentWithVisited(
        ElementNode $element,
        array &$visited,
        bool $includeHidden = false,
        ?ElementNode $subject = null,
    ): bool {
        return $this->everyRenderPathContains(
            $element->children(),
            function (mixed $node) use (&$visited, $includeHidden, $subject): bool {
                if (! $node instanceof Node) {
                    return false;
                }

                return $this->nodeRendersContentWithVisited($node, $visited, $includeHidden, $subject);
            },
            fn (Node $node): bool => ! $node instanceof ElementNode
                || $this->elementMayContributeContent($node, $includeHidden, $subject),
        );
    }

    protected function hasAccessibleContent(ElementNode $element): bool
    {
        return $this->hasAccessibleName($element) || $this->hasTextContent($element);
    }

    protected function accessibleNameSourceRendersWhenever(
        ElementNode $required,
        ElementNode $subject,
    ): bool {
        return $this->nodeRendersWhenever($required, $subject)
            && ReactiveAttributeSemantics::elementRendersWhenever($required, $subject);
    }

    private function clientRenderedTextVerdict(ElementNode $element, bool $staticContent): bool
    {
        $paths = $this->explicitAttributeRenderPaths(
            $element,
            ReactiveAttributeSemantics::CLIENT_TEXT_DIRECTIVES,
        );
        if ($paths === null || $paths === []) {
            return $staticContent;
        }

        $hasClientDirective = false;

        foreach ($paths as $path) {
            $effectiveDirective = null;

            foreach ($path as $attribute) {
                if (ReactiveAttributeSemantics::isClientTextDirective($attribute)) {
                    $effectiveDirective = $attribute;
                }
            }

            if ($effectiveDirective === null) {
                if (! $staticContent) {
                    return false;
                }

                continue;
            }

            $hasClientDirective = true;

            if (! ReactiveAttributeSemantics::clientTextMayBeNonEmpty($effectiveDirective, $element)) {
                return false;
            }
        }

        return $hasClientDirective || $staticContent;
    }

    protected function nodeRendersContent(Node $node): bool
    {
        $visited = [];

        return $this->nodeRendersContentWithVisited($node, $visited);
    }

    /**
     * @param  array<int, true>  $visited
     */
    private function nodeRendersContentWithVisited(
        Node $node,
        array &$visited,
        bool $includeHidden = false,
        ?ElementNode $subject = null,
    ): bool {
        if ($node instanceof DirectiveBlockNode && $node->isDirectiveNamed('teleport')) {
            return false;
        }

        if ($node instanceof TextNode) {
            return trim($node->getSemanticContent()) !== '';
        }

        if ($node instanceof EscapeNode || $node instanceof VerbatimNode) {
            return trim($node->render()) !== '';
        }

        if ($node instanceof EchoNode || $node instanceof ComponentNode) {
            return true;
        }

        if ($node instanceof PhpTagNode) {
            return $node->isShortEcho() || PhpSource::mayProduceOutput($node->code());
        }

        if ($node instanceof PhpBlockNode) {
            return PhpSource::mayProduceOutput($node->code());
        }

        if ($node instanceof DirectiveNode) {
            $name = strtolower($node->nameText());

            if ($name === 'php') {
                return PhpSource::mayProduceOutput(PhpSource::innerArguments($node->arguments()) ?? '');
            }

            if (in_array($name, ['break', 'continue'], true)) {
                return false;
            }

            if (! isset(self::NON_RENDERING_DIRECTIVES[$name]) && $node->isStandalone()) {
                return true;
            }

            return false;
        }

        if ($node instanceof ElementNode) {
            return $this->elementRendersContent($node, $visited, $includeHidden, $subject);
        }

        foreach ($node->children() as $child) {
            if ($this->nodeRendersContentWithVisited($child, $visited, $includeHidden, $subject)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, true>  $visited
     */
    private function elementRendersContent(
        ElementNode $element,
        array &$visited,
        bool $includeHidden = false,
        ?ElementNode $subject = null,
    ): bool {
        if (! $this->elementMayContributeContent($element, $includeHidden, $subject)) {
            return false;
        }

        return $this->elementContributesAccessibleName($element, $visited, $includeHidden);
    }

    private function elementMayContributeContent(
        ElementNode $element,
        bool $includeHidden,
        ?ElementNode $subject = null,
    ): bool {
        if ($element->isTag(['script', 'style', 'template']) || $this->mayRenderInert($element)) {
            return false;
        }

        if ($includeHidden) {
            return true;
        }

        foreach ($this->attributesInRenderStructure($element) as $attribute) {
            if (ReactiveAttributeSemantics::mayHideElement($attribute, $element)
                && ($subject === null
                    || ! ReactiveAttributeSemantics::elementRendersWhenever($element, $subject))) {
                return false;
            }
        }

        if ($this->attributesInRenderStructure($element, ['hidden', 'inert']) !== []) {
            return false;
        }

        foreach ($this->attributesInRenderStructure($element, 'aria-hidden') as $attribute) {
            if ($attribute->isDynamic()
                || strtolower($attribute->decodedValueText() ?? '') === 'true') {
                return false;
            }
        }

        return true;
    }

    private function mayRenderInert(ElementNode $element): bool
    {
        if ($element->hasAttribute('inert')) {
            return true;
        }

        foreach ($element->attributes() as $attribute) {
            if ($attribute->isBladeConstruct()) {
                return $this->attributesInRenderStructure($element, 'inert') !== [];
            }
        }

        return false;
    }

    private function hasNonEmptyAccessibleNameAttribute(ElementNode $element, string $name): bool
    {
        $attribute = $element->attribute($name);
        if ($attribute === null) {
            return false;
        }

        $value = $attribute->isDynamic()
            ? $attribute->valueText()
            : $attribute->decodedValueText();

        return $value !== null && HtmlWhitespace::trim($value) !== '';
    }
}
