<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Content;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Accessibility\Aria\NoInvalidRoleRule;
use Forte\Sheath\Rules\Concerns\ChecksAccessibility;
use Forte\Sheath\Rules\Concerns\DetectsExclusiveBranches;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\ReactiveAttributeSemantics;

/** @internal */
class ButtonAccessibleNameRule extends AbstractRule
{
    use ChecksAccessibility;
    use DetectsExclusiveBranches;

    public function getId(): string
    {
        return 'a11y-button-accessible-name';
    }

    public function getDescription(): string
    {
        return 'Buttons must have an accessible name for screen readers.';
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
        $controlsNamedByLabels = $this->controlsNamedByLabels($document, $context);

        $document->queryElements('button')->each(function (ElementNode $button) use ($context, $controlsNamedByLabels): void {
            $this->checkButtonName($button, $context, $controlsNamedByLabels);
        });

        $context->elements()->each(function (ElementNode $element) use ($context): void {
            $tagName = strtolower($element->tagNameText());
            if ($tagName === 'button' || $tagName === 'input') {
                return;
            }

            $this->checkPossibleRoleButtonName($element, $context);
        });

        $document->queryElements('input')->each(function (ElementNode $input) use ($context, $controlsNamedByLabels): void {
            if ($this->elementHasUnmodelledAttributes($input)
                || $this->isUnconditionallyExcludedFromAccessibilityTree($input)) {
                return;
            }

            $paths = $this->explicitAttributeRenderPaths($input, [
                'type', 'aria-label', 'aria-labelledby', 'title', 'alt', 'value',
                'hidden', 'inert', 'aria-hidden',
            ]);
            if ($paths === null) {
                return;
            }

            foreach ($paths as $path) {
                if ($this->accessibilityAttributePathIsExcluded($path)) {
                    continue;
                }

                $type = strtolower($this->firstStaticPathValue($path, 'type') ?? '');
                if (! in_array($type, ['button', 'submit', 'reset', 'image'], true)
                    || isset($controlsNamedByLabels[$input->index()])
                    || $this->inputPathHasAccessibleName($input, $path, $type)) {
                    continue;
                }

                $context->report(
                    $input,
                    'Input button has no accessible name.'
                );

                return;
            }
        });
    }

    /** @param array<int, true> $controlsNamedByLabels */
    private function checkButtonName(ElementNode $button, RuleContext $context, array $controlsNamedByLabels): void
    {
        if ($this->elementHasUnmodelledAttributes($button)
            || $this->isUnconditionallyExcludedFromAccessibilityTree($button)) {
            return;
        }

        if ($this->hasTextContent($button) || isset($controlsNamedByLabels[$button->index()])) {
            return;
        }

        $nameAttributes = [
            'aria-label', 'aria-labelledby', 'title',
            ...ReactiveAttributeSemantics::CLIENT_TEXT_DIRECTIVES,
        ];
        $paths = $this->explicitAttributeRenderPaths(
            $button,
            [...$nameAttributes, 'hidden', 'inert', 'aria-hidden'],
        );
        if ($paths === null) {
            return;
        }

        foreach ($paths as $path) {
            if ($this->accessibilityAttributePathIsExcluded($path)
                || $this->attributePathProvidesAccessibleName($button, $path, $nameAttributes)) {
                continue;
            }

            $context->report(
                $button,
                'Button has no accessible name.'
            );

            return;
        }
    }

    private function checkPossibleRoleButtonName(ElementNode $element, RuleContext $context): void
    {
        if ($this->attributesInRenderStructure($element, 'role') === []
            || $this->elementHasUnmodelledAttributes($element)
            || $this->isUnconditionallyExcludedFromAccessibilityTree($element)) {
            return;
        }

        if ($this->hasTextContent($element)) {
            return;
        }

        $paths = $this->explicitAttributeRenderPaths($element, [
            'role', 'aria-label', 'aria-labelledby', 'title',
            ...ReactiveAttributeSemantics::CLIENT_TEXT_DIRECTIVES,
            'hidden', 'inert', 'aria-hidden',
        ]);
        if ($paths === null) {
            return;
        }

        foreach ($paths as $path) {
            if ($this->accessibilityAttributePathIsExcluded($path)
                || $this->effectiveRoleOnPath($path) !== 'button'
                || $this->attributePathProvidesAccessibleName(
                    $element,
                    $path,
                    [
                        'aria-label', 'aria-labelledby', 'title',
                        ...ReactiveAttributeSemantics::CLIENT_TEXT_DIRECTIVES,
                    ],
                )) {
                continue;
            }

            $context->report(
                $element,
                'Button has no accessible name.'
            );

            return;
        }
    }

    /** @param list<Attribute> $path */
    private function effectiveRoleOnPath(array $path): ?string
    {
        $role = $this->firstPathAttribute($path, 'role');
        if ($role === null
            || $role->isDynamic()) {
            return null;
        }

        foreach ($role->tokensLower() as $candidate) {
            if (NoInvalidRoleRule::isValidRole($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @param list<Attribute> $path */
    private function inputPathHasAccessibleName(ElementNode $input, array $path, string $type): bool
    {
        $names = $type === 'image'
            ? ['aria-label', 'aria-labelledby', 'title', 'alt']
            : ['aria-label', 'aria-labelledby', 'title'];

        if ($this->attributePathProvidesAccessibleName($input, $path, $names)) {
            return true;
        }

        if ($type === 'image') {
            return false;
        }

        $value = $this->firstPathAttribute($path, 'value');

        return in_array($type, ['submit', 'reset'], true)
            ? $value === null || $this->attributeMayHaveNonEmptyValue($value)
            : $value !== null && $this->attributeMayHaveNonEmptyValue($value);
    }

    /** @param list<Attribute> $path */
    private function firstStaticPathValue(array $path, string $name): ?string
    {
        $attribute = $this->firstPathAttribute($path, $name);
        if ($attribute === null) {
            return null;
        }

        if ($attribute->isDynamic()) {
            return null;
        }

        return $attribute->decodedValueText();
    }

    /** @param list<Attribute> $path */
    private function firstPathAttribute(array $path, string $name): ?Attribute
    {
        foreach ($path as $attribute) {
            if ($attribute->isNamed($name)) {
                return $attribute;
            }
        }

        return null;
    }

    /** @return array<int, true> */
    private function controlsNamedByLabels(Document $document, RuleContext $context): array
    {
        $named = [];
        $dynamicControlsById = [];

        foreach ($context->elements() as $element) {
            if (! $this->isPotentialButtonControl($element)
                || ! $element->hasAttribute('id')
                || ! $element->attributeIsDynamic('id')) {
                continue;
            }

            $id = $element->attribute('id')?->valueText() ?? '';
            if ($id !== '') {
                $dynamicControlsById[$this->elementTreeKey($element)."\0".$id] ??= $element;
            }
        }

        foreach ($document->queryElements('label') as $label) {
            if ($this->isUnconditionallyExcludedFromAccessibilityTree($label)
                || ! $this->hasTextContent($label)) {
                continue;
            }

            foreach ($this->buttonControlsForLabel($label, $dynamicControlsById) as $control) {
                if ($this->accessibleNameSourceRendersWhenever($label, $control)) {
                    $named[$control->index()] = true;
                }
            }
        }

        return $named;
    }

    /**
     * @param  array<string, ElementNode>  $dynamicControlsById
     * @return list<ElementNode>
     */
    private function buttonControlsForLabel(ElementNode $label, array $dynamicControlsById): array
    {
        if ($label->hasAttribute('for')) {
            if ($label->attributeIsDynamic('for')) {
                $key = $this->elementTreeKey($label)."\0".($label->attribute('for')?->valueText() ?? '');
                $target = $dynamicControlsById[$key] ?? null;

                return $target !== null && $this->isPotentialButtonControl($target) ? [$target] : [];
            }

            $for = $label->staticAttributeValue('for');
            $target = $for === null || $for === '' ? null : $this->elementByIdInTree($label, $for);

            return $target !== null && $this->isPotentialButtonControl($target) ? [$target] : [];
        }

        $earlierLabelable = [];
        $buttons = [];
        foreach ($label->descendants() as $descendant) {
            if (! $descendant instanceof ElementNode
                || $this->crossesRenderedTreeBoundary($descendant, $label)
                || ! $this->isLabelableControl($descendant)) {
                continue;
            }

            if ($this->isPotentialButtonControl($descendant)) {
                $canBeFirst = true;
                foreach ($earlierLabelable as $earlier) {
                    if (! $this->nodesAreMutuallyExclusive($earlier, $descendant)) {
                        $canBeFirst = false;
                        break;
                    }
                }

                if ($canBeFirst) {
                    $buttons[] = $descendant;
                }
            }

            $earlierLabelable[] = $descendant;
        }

        return $buttons;
    }

    private function isPotentialButtonControl(ElementNode $element): bool
    {
        if ($element->isTag('button')) {
            return true;
        }

        if (! $element->isTag('input')) {
            return false;
        }

        if ($element->attributeIsDynamic('type')) {
            return true;
        }

        return in_array($element->staticAttributeValueLower('type') ?? 'text', [
            'button', 'submit', 'reset', 'image',
        ], true);
    }

    private function isLabelableControl(ElementNode $element): bool
    {
        if ($element->isTag('input')) {
            return ($element->staticAttributeValueLower('type') ?? 'text') !== 'hidden';
        }

        return $element->isTag(['button', 'meter', 'output', 'progress', 'select', 'textarea']);
    }
}
