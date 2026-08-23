<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Aria;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsExclusiveBranches;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\Concerns\ReportsWithFix;
use Forte\Sheath\Rules\Concerns\TraversesRenderedTree;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Support\HtmlInteger;

/** @internal */
class NoAriaHiddenOnFocusableRule extends AbstractRule
{
    use DetectsExclusiveBranches;
    use DetectsOpaqueAttributes;
    use ReportsWithFix;
    use TraversesRenderedTree;

    private const FOCUSABLE_ELEMENTS = [
        'a',
        'area',
        'button',
        'input',
        'select',
        'textarea',
        'summary',
        'audio',
        'video',
        'embed',
        'iframe',
        'object',
    ];

    private const DISABLABLE_ELEMENTS = ['button', 'input', 'select', 'textarea'];

    private const MEDIA_ELEMENTS = ['audio', 'video'];

    /** @var array<int, bool> */
    private array $disabledByFieldset = [];

    public function getId(): string
    {
        return 'a11y-no-aria-hidden-on-focusable';
    }

    public function getDescription(): string
    {
        return 'Elements with aria-hidden="true" must not be focusable or contain focusable descendants.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::ACCESSIBILITY;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $allElements = $context->elements();
        /** @var array<int, true> $ariaHiddenTargets */
        $ariaHiddenTargets = [];
        /** @var list<ElementNode> $targetElements */
        $targetElements = [];

        foreach ($allElements as $element) {
            if ($this->hasAriaHiddenTrue($element)) {
                $ariaHiddenTargets[spl_object_id($element)] = true;
                $targetElements[] = $element;
            }
        }

        if ($targetElements === []) {
            return;
        }

        /** @var array<int, true> $targetAncestors */
        $targetAncestors = [];
        foreach ($targetElements as $target) {
            $ancestor = $this->accessibilityParentElement($target);
            while ($ancestor !== null) {
                $ancestorId = spl_object_id($ancestor);
                if (isset($targetAncestors[$ancestorId])) {
                    break;
                }

                $targetAncestors[$ancestorId] = true;
                $ancestor = $this->accessibilityParentElement($ancestor);
            }
        }

        $elements = [];
        $focusable = [];
        $renderedParents = [];
        $insideHiddenOrInert = [];
        $insideAriaHiddenTarget = [];
        $this->disabledByFieldset = [];

        foreach ($allElements as $element) {
            $id = spl_object_id($element);
            $parent = $this->accessibilityParentElement($element);
            $parentId = $parent !== null ? spl_object_id($parent) : null;
            $insideAriaHiddenTarget[$id] = isset($ariaHiddenTargets[$id])
                || ($parentId !== null && ($insideAriaHiddenTarget[$parentId] ?? false));

            if (! isset($targetAncestors[$id]) && ! $insideAriaHiddenTarget[$id]) {
                continue;
            }

            $insideHiddenOrInert[$id] = $this->isAlwaysHiddenOrInert($element)
                || ($parentId !== null && ($insideHiddenOrInert[$parentId] ?? false));

            $this->disabledByFieldset[$id] = $parentId !== null
                && ($this->disabledByFieldset[$parentId] ?? false);

            if ($parent !== null
                && $parent->isTag('fieldset')
                && $parent->hasUnconditionallyPresentAttribute('disabled')
                && ! $this->isFirstRenderedLegend($element, $parent)) {
                $this->disabledByFieldset[$id] = true;
            }

            $elements[] = $element;
            $focusable[$id] = $this->isFocusable($element, $insideHiddenOrInert[$id]);
            $renderedParents[$id] = $parent;
        }

        /** @var array<int, true> $hasFocusableDescendant */
        $hasFocusableDescendant = [];

        foreach (array_reverse($elements) as $element) {
            $id = spl_object_id($element);
            if (! $focusable[$id] && ! isset($hasFocusableDescendant[$id])) {
                continue;
            }

            $parent = $renderedParents[$id];
            if ($parent !== null) {
                $hasFocusableDescendant[spl_object_id($parent)] = true;
            }
        }

        foreach ($targetElements as $element) {
            if (! isset($focusable[spl_object_id($element)])) {
                continue;
            }

            $id = spl_object_id($element);
            $elementIsFocusable = $focusable[$id];
            $elementHasFocusableDescendant = isset($hasFocusableDescendant[$id]);
            if (! $elementIsFocusable && ! $elementHasFocusableDescendant) {
                continue;
            }

            if (! $this->ariaHiddenCanCoincideWithFocusability($element, $elementHasFocusableDescendant)) {
                continue;
            }

            $ariaHiddenAttributes = array_values(array_filter(
                $this->attributesInRenderStructure($element, 'aria-hidden'),
                fn ($attribute): bool => ! $attribute->isDynamic()
                    && strtolower($attribute->decodedValueText() ?? '') === 'true',
            ));
            $ariaHiddenAttr = count($ariaHiddenAttributes) === 1 ? $ariaHiddenAttributes[0] : null;

            if ($ariaHiddenAttributes !== []
                && $this->ariaHiddenIsProvenSafeAcrossCorrelatedTreeState(
                    $element,
                    $ariaHiddenAttributes,
                    $elementIsFocusable,
                    $elements,
                    $focusable,
                    $renderedParents,
                )) {
                continue;
            }

            $fix = $ariaHiddenAttr !== null
                ? $this->createRemoveAttributeFix($ariaHiddenAttr)
                : null;

            $context->report(
                $element,
                $elementIsFocusable
                    ? 'Focusable element is hidden by aria-hidden="true".'
                    : 'aria-hidden="true" contains a focusable descendant.',
                $fix
            );
        }
    }

    /**
     * Correlate the small, exact predicate subset shared by the attribute
     * engine across ancestor/descendant nodes. Unknown predicates remain
     * conservative and therefore cannot suppress a report.
     *
     * @param  list<Attribute>  $ariaHiddenAttributes
     * @param  list<ElementNode>  $elements
     * @param  array<int, bool>  $focusable
     * @param  array<int, ElementNode|null>  $renderedParents
     */
    private function ariaHiddenIsProvenSafeAcrossCorrelatedTreeState(
        ElementNode $target,
        array $ariaHiddenAttributes,
        bool $targetIsFocusable,
        array $elements,
        array $focusable,
        array $renderedParents,
    ): bool {
        $descendants = [];
        foreach ($elements as $candidate) {
            if (! ($focusable[spl_object_id($candidate)] ?? false) || $candidate === $target) {
                continue;
            }

            $parent = $renderedParents[spl_object_id($candidate)] ?? null;
            while ($parent !== null && $parent !== $target) {
                $parent = $renderedParents[spl_object_id($parent)] ?? null;
            }

            if ($parent === $target) {
                $descendants[] = $candidate;
            }
        }

        foreach ($ariaHiddenAttributes as $ariaHidden) {
            if ($targetIsFocusable
                && ! $this->hasCorrelatedHiddenOrDisabledAncestor($target, $ariaHidden)) {
                return false;
            }

            foreach ($descendants as $descendant) {
                if (! $this->descendantIsNonSequentialWheneverAriaHidden($descendant, $target, $ariaHidden)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function hasCorrelatedHiddenOrDisabledAncestor(ElementNode $element, Attribute $ariaHidden): bool
    {
        $candidate = $this->accessibilityParentElement($element);

        while ($candidate !== null) {
            foreach (['hidden', 'inert'] as $name) {
                foreach ($this->firstStaticAttributesOnPaths($candidate, $name) as $attribute) {
                    if (! $attribute->isBound()
                        && $this->recognizedConditionRendersWhenever($attribute, $ariaHidden)) {
                        return true;
                    }
                }
            }

            if ($candidate->isTag('fieldset') && ! $this->isInsideFirstRenderedLegend($element, $candidate)) {
                foreach ($this->firstStaticAttributesOnPaths($candidate, 'disabled') as $attribute) {
                    if (! $attribute->isBound()
                        && $this->recognizedConditionRendersWhenever($attribute, $ariaHidden)) {
                        return true;
                    }
                }
            }

            $candidate = $this->accessibilityParentElement($candidate);
        }

        return false;
    }

    private function descendantIsNonSequentialWheneverAriaHidden(
        ElementNode $descendant,
        ElementNode $target,
        Attribute $ariaHidden,
    ): bool {
        if ($this->recognizedConditionsAreComplementary($descendant, $ariaHidden)) {
            return true;
        }

        foreach ($this->firstStaticAttributesOnPaths($descendant, 'tabindex') as $attribute) {
            if ($attribute->isDynamic()
                || count($this->attributesInRenderStructure($descendant, 'tabindex')) !== 1) {
                continue;
            }

            $tabindex = HtmlInteger::parse($attribute->decodedValueText() ?? '');
            if ($tabindex !== null && $tabindex < 0
                && $this->recognizedConditionRendersWhenever($attribute, $ariaHidden)) {
                return true;
            }
        }

        $candidate = $descendant;
        while ($candidate !== null) {
            foreach (['hidden', 'inert'] as $name) {
                foreach ($this->firstStaticAttributesOnPaths($candidate, $name) as $attribute) {
                    if (! $attribute->isBound()
                        && $this->recognizedConditionRendersWhenever($attribute, $ariaHidden)) {
                        return true;
                    }
                }
            }

            if ($candidate->isTag('fieldset') && ! $this->isInsideFirstRenderedLegend($descendant, $candidate)) {
                foreach ($this->firstStaticAttributesOnPaths($candidate, 'disabled') as $attribute) {
                    if (! $attribute->isBound()
                        && $this->recognizedConditionRendersWhenever($attribute, $ariaHidden)) {
                        return true;
                    }
                }
            }

            if ($candidate === $target) {
                break;
            }
            $candidate = $this->accessibilityParentElement($candidate);
        }

        return false;
    }

    /** @return list<Attribute> */
    private function firstStaticAttributesOnPaths(ElementNode $element, string $name): array
    {
        $attributes = $this->firstAttributesOnRenderPaths($element, $name);
        if ($attributes === null) {
            return [];
        }

        $result = [];
        foreach ($attributes as $attribute) {
            if ($attribute !== null && ! $attribute->isDynamic()) {
                $result[spl_object_id($attribute)] = $attribute;
            }
        }

        return array_values($result);
    }

    private function isInsideFirstRenderedLegend(ElementNode $element, ElementNode $fieldset): bool
    {
        $candidate = $element;
        $parent = $this->accessibilityParentElement($candidate);

        while ($parent !== null && $parent !== $fieldset) {
            $candidate = $parent;
            $parent = $this->accessibilityParentElement($candidate);
        }

        return $parent === $fieldset
            && $candidate->isTag('legend')
            && $this->isFirstRenderedLegend($candidate, $fieldset);
    }

    private function recognizedConditionRendersWhenever(Attribute $required, Attribute $subject): bool
    {
        $requiredPredicates = $this->conditionalPredicateIdentities($required);
        $subjectPredicates = $this->conditionalPredicateIdentities($subject);
        if ($requiredPredicates === []) {
            return true;
        }

        if (count($requiredPredicates) !== 1 || count($subjectPredicates) !== 1) {
            return false;
        }

        return $requiredPredicates[0] === $subjectPredicates[0];
    }

    private function recognizedConditionsAreComplementary(ElementNode $node, Attribute $attribute): bool
    {
        $nodePredicates = $this->conditionalPredicateIdentities($node);
        $attributePredicates = $this->conditionalPredicateIdentities($attribute);
        if (count($nodePredicates) !== 1 || count($attributePredicates) !== 1) {
            return false;
        }

        return $nodePredicates[0]['expression'] === $attributePredicates[0]['expression']
            && $nodePredicates[0]['when'] !== $attributePredicates[0]['when'];
    }

    private function hasAriaHiddenTrue(ElementNode $element): bool
    {
        foreach ($this->attributesInRenderStructure($element, 'aria-hidden') as $attribute) {
            if (! $attribute->isDynamic()
                && strtolower($attribute->decodedValueText() ?? '') === 'true') {
                return true;
            }
        }

        return false;
    }

    private function isAlwaysHiddenOrInert(ElementNode $element): bool
    {
        $paths = $this->explicitAttributeRenderPaths($element, ['hidden', 'inert'], true);
        if ($paths === null || $paths === []) {
            return false;
        }

        foreach ($paths as $path) {
            if (! $this->attributePathHasNonBound($path, 'hidden')
                && ! $this->attributePathHasNonBound($path, 'inert')) {
                return false;
            }
        }

        return true;
    }

    private function ariaHiddenCanCoincideWithFocusability(
        ElementNode $element,
        bool $hasFocusableDescendant,
    ): bool {
        $paths = $this->explicitAttributeRenderPaths($element, [
            'aria-hidden',
            'hidden',
            'inert',
            'disabled',
            'type',
            'tabindex',
            'href',
            'controls',
            'contenteditable',
        ], true);
        if ($paths === null) {
            return false;
        }

        foreach ($paths as $path) {
            if (! $this->attributePathHasStaticValue($path, 'aria-hidden', 'true')
                || $this->attributePathHasNonBound($path, 'hidden')
                || $this->attributePathHasNonBound($path, 'inert')) {
                continue;
            }

            if ($hasFocusableDescendant || $this->isFocusableOnAttributePath($element, $path)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Attribute> $path */
    private function isFocusableOnAttributePath(ElementNode $element, array $path): bool
    {
        $tagName = strtolower($element->tagNameText());

        if ($this->isDisabledOnAttributePath($element, $tagName, $path)) {
            return false;
        }

        if ($tagName === 'input') {
            $type = $this->staticAttributePathValue($path, 'type');
            if ($type === null && $this->attributePathHas($path, 'type')) {
                return false;
            }

            if (strtolower($type ?? 'text') === 'hidden') {
                return false;
            }
        }

        $tabindex = $this->staticAttributePathValue($path, 'tabindex');
        if ($tabindex !== null) {
            $parsedTabindex = HtmlInteger::parse($tabindex);
            if ($parsedTabindex !== null) {
                return $parsedTabindex >= 0;
            }
        }

        if ($tagName === 'details') {
            return ! $this->detailsHasSummary($element);
        }

        if (in_array($tagName, self::FOCUSABLE_ELEMENTS, true)) {
            if ($tagName === 'a' || $tagName === 'area') {
                return $this->attributePathHas($path, 'href');
            }

            if (in_array($tagName, self::MEDIA_ELEMENTS, true)) {
                return $this->attributePathHas($path, 'controls');
            }

            if ($tagName === 'summary') {
                return $this->isPotentialSummaryForParentDetails($element);
            }

            return true;
        }

        $contenteditable = strtolower($this->staticAttributePathValue($path, 'contenteditable') ?? '');

        return $this->attributePathHas($path, 'contenteditable')
            && in_array($contenteditable, ['true', 'plaintext-only', ''], true);
    }

    /** @param list<Attribute> $path */
    private function isDisabledOnAttributePath(ElementNode $element, string $tagName, array $path): bool
    {
        return in_array($tagName, self::DISABLABLE_ELEMENTS, true)
            && ($this->attributePathHasNonBound($path, 'disabled')
                || ($this->disabledByFieldset[spl_object_id($element)] ?? false));
    }

    /** @param list<Attribute> $path */
    private function attributePathHasStaticValue(array $path, string $name, string $value): bool
    {
        $actual = $this->staticAttributePathValue($path, $name);

        return $actual !== null && strcasecmp($actual, $value) === 0;
    }

    /** @param list<Attribute> $path */
    private function staticAttributePathValue(array $path, string $name): ?string
    {
        foreach ($path as $attribute) {
            if (! $attribute->isNamed($name)) {
                continue;
            }

            if ($attribute->isDynamic()) {
                return null;
            }

            return $attribute->decodedValueText();
        }

        return null;
    }

    /** @param list<Attribute> $path */
    private function attributePathHas(array $path, string $name): bool
    {
        foreach ($path as $attribute) {
            if ($attribute->isNamed($name)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Attribute> $path */
    private function attributePathHasNonBound(array $path, string $name): bool
    {
        foreach ($path as $attribute) {
            if ($attribute->isNamed($name) && ! $attribute->isBound()) {
                return true;
            }
        }

        return false;
    }

    private function isFocusable(ElementNode $element, bool $insideHiddenOrInert): bool
    {
        if ($insideHiddenOrInert) {
            return false;
        }

        $tagName = strtolower($element->tagNameText());

        if ($this->isUnconditionallyDisabled($element, $tagName)) {
            return false;
        }

        if ($tagName === 'input' && $this->inputTypeMayBeHidden($element)) {
            return false;
        }

        $tabindexFocusability = $this->focusabilityFromTabindex($element);

        if ($tabindexFocusability !== null) {
            return $tabindexFocusability;
        }

        if ($this->isNativelyFocusable($element, $tagName)) {
            return true;
        }

        return $this->isStaticallyContentEditable($element);
    }

    /** Null means no static integer tabindex determines focusability. */
    private function focusabilityFromTabindex(ElementNode $element): ?bool
    {
        $attributes = $this->firstAttributesOnRenderPaths($element, 'tabindex');
        if ($attributes === null) {
            return null;
        }

        $hasIndeterminatePath = false;

        foreach ($attributes as $attribute) {
            if ($attribute === null) {
                $hasIndeterminatePath = true;

                continue;
            }

            if ($attribute->isDynamic()) {
                $hasIndeterminatePath = true;

                continue;
            }

            $tabindex = HtmlInteger::parse($attribute->decodedValueText() ?? '');
            if ($tabindex === null) {
                $hasIndeterminatePath = true;

                continue;
            }

            if ($tabindex >= 0) {
                return true;
            }
        }

        return $hasIndeterminatePath ? null : false;
    }

    private function inputTypeMayBeHidden(ElementNode $element): bool
    {
        return $element->attributeIsDynamic('type')
            || ($element->staticAttributeValueLower('type') ?? 'text') === 'hidden';
    }

    private function isNativelyFocusable(ElementNode $element, string $tagName): bool
    {
        if (! in_array($tagName, self::FOCUSABLE_ELEMENTS, true)) {
            return $tagName === 'details' && ! $this->detailsHasSummary($element);
        }

        if ($this->isUnconditionallyDisabled($element, $tagName)) {
            return false;
        }

        if ($tagName === 'a' || $tagName === 'area') {
            return $this->attributesInRenderStructure($element, 'href') !== [];
        }

        if (in_array($tagName, self::MEDIA_ELEMENTS, true)) {
            return $this->attributesInRenderStructure($element, 'controls') !== [];
        }

        if ($tagName === 'summary') {
            return $this->isPotentialSummaryForParentDetails($element);
        }

        return true;
    }

    private function isPotentialSummaryForParentDetails(ElementNode $summary): bool
    {
        $details = $this->accessibilityParentElement($summary);

        if ($details === null || ! $details->isTag('details')) {
            return false;
        }

        foreach ($this->renderedChildElements($details) as $child) {
            if (! $child->isTag('summary')) {
                continue;
            }

            if ($child === $summary) {
                return true;
            }

            // A summary behind Blade control flow might not render. Only an
            // unconditional earlier summary prevents this one being first.
            if ($child->getParent() === $details) {
                return false;
            }
        }

        return false;
    }

    private function detailsHasSummary(ElementNode $details): bool
    {
        foreach ($this->renderedChildElements($details) as $child) {
            if ($child->isTag('summary')) {
                return true;
            }
        }

        return false;
    }

    private function isUnconditionallyDisabled(ElementNode $element, string $tagName): bool
    {
        return in_array($tagName, self::DISABLABLE_ELEMENTS, true)
            && ($element->hasUnconditionallyPresentAttribute('disabled')
                || ($this->disabledByFieldset[spl_object_id($element)] ?? false));
    }

    private function isFirstRenderedLegend(ElementNode $element, ElementNode $fieldset): bool
    {
        foreach ($this->renderedChildElements($fieldset) as $child) {
            if (! $child->isTag('legend')) {
                continue;
            }

            if ($child === $element) {
                return true;
            }

            // A lexically earlier legend behind Blade control flow might not
            // render. It prevents this legend from becoming first only when
            // it is guaranteed on every path that renders this legend.
            if ($this->nodeRendersWhenever($child, $element)) {
                return false;
            }
        }

        return false;
    }

    private function isStaticallyContentEditable(ElementNode $element): bool
    {
        foreach ($this->attributesInRenderStructure($element, 'contenteditable') as $attribute) {
            if ($attribute->isDynamic()) {
                continue;
            }

            if (in_array(strtolower($attribute->decodedValueText() ?? ''), ['true', 'plaintext-only', ''], true)) {
                return true;
            }
        }

        return false;
    }

    private function accessibilityParentElement(ElementNode $element): ?ElementNode
    {
        $parent = $this->renderedParentElement($element);

        return $parent !== null && $parent->isTag('template') ? null : $parent;
    }
}
