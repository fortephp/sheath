<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Content;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ChecksAccessibility;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class FormLabelRule extends AbstractRule
{
    use ChecksAccessibility;

    private const UNLABELABLE_TYPES = [
        'hidden', 'submit', 'reset', 'button', 'image',
    ];

    private const INPUT_TYPES = [
        'hidden', 'text', 'search', 'tel', 'url', 'email', 'password',
        'date', 'month', 'week', 'time', 'datetime-local', 'number', 'range',
        'color', 'checkbox', 'radio', 'file', 'submit', 'image', 'reset', 'button',
    ];

    public function getId(): string
    {
        return 'a11y-form-label';
    }

    public function getDescription(): string
    {
        return 'Form inputs must have associated labels for accessibility.';
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
        $elements = $document->elementsGroupedByName(['label', 'input', 'select', 'textarea']);
        $controlsNamedByLabels = $this->controlsNamedByLabels(
            $elements['label'],
            $this->dynamicControlsById($context),
        );

        foreach ($elements['input'] as $input) {
            $this->checkInput($input, $context, $controlsNamedByLabels);
        }

        foreach ($elements['select'] as $select) {
            $this->checkFormControl($select, $context, $controlsNamedByLabels, 'Select');
        }

        foreach ($elements['textarea'] as $textarea) {
            $this->checkFormControl($textarea, $context, $controlsNamedByLabels, 'Textarea');
        }
    }

    /**
     * @param  array<int, true>  $controlsNamedByLabels
     */
    private function checkInput(ElementNode $input, RuleContext $context, array $controlsNamedByLabels): void
    {
        if ($this->elementHasUnmodelledAttributes($input)
            || $this->isUnconditionallyExcludedFromAccessibilityTree($input)
            || isset($controlsNamedByLabels[$input->index()])) {
            return;
        }

        $nameAttributes = ['aria-label', 'aria-labelledby', 'title'];
        $paths = $this->explicitAttributeRenderPaths(
            $input,
            ['type', ...$nameAttributes, 'hidden', 'inert', 'aria-hidden'],
        );
        if ($paths === null) {
            return;
        }

        foreach ($paths as $path) {
            if ($this->accessibilityAttributePathIsExcluded($path)) {
                continue;
            }

            $typeAttribute = $this->firstAccessibilityAttributeOnPath($path, 'type');
            if ($typeAttribute === null) {
                $type = 'text';
            } elseif ($typeAttribute->isDynamic()) {
                continue;
            } else {
                $type = strtolower($typeAttribute->decodedValueText() ?? '');
                if (! in_array($type, self::INPUT_TYPES, true)) {
                    $type = 'text';
                }
            }

            if (in_array($type, self::UNLABELABLE_TYPES, true)
                || $this->attributePathProvidesAccessibleName($input, $path, $nameAttributes)) {
                continue;
            }

            $context->report(
                $input,
                "Input of type '{$type}' has no accessible label."
            );

            return;
        }
    }

    /**
     * @param  array<int, true>  $controlsNamedByLabels
     */
    private function checkFormControl(ElementNode $element, RuleContext $context, array $controlsNamedByLabels, string $controlName): void
    {
        if ($this->elementHasUnmodelledAttributes($element)
            || $this->isUnconditionallyExcludedFromAccessibilityTree($element)
            || isset($controlsNamedByLabels[$element->index()])) {
            return;
        }

        $nameAttributes = ['aria-label', 'aria-labelledby', 'title'];
        $paths = $this->explicitAttributeRenderPaths(
            $element,
            [...$nameAttributes, 'hidden', 'inert', 'aria-hidden'],
        );
        if ($paths === null) {
            return;
        }

        foreach ($paths as $path) {
            if ($this->accessibilityAttributePathIsExcluded($path)
                || $this->attributePathProvidesAccessibleName($element, $path, $nameAttributes)) {
                continue;
            }

            $context->report(
                $element,
                "{$controlName} has no accessible label."
            );

            return;
        }
    }

    /**
     * @param  array<int, ElementNode>  $labels
     * @param  array<string, ElementNode>  $dynamicControlsById
     * @return array<int, true>
     */
    private function controlsNamedByLabels(array $labels, array $dynamicControlsById): array
    {
        $named = [];

        foreach ($labels as $label) {
            if (! $this->hasTextContent($label)) {
                continue;
            }

            $control = $this->labeledControl($label, $dynamicControlsById);
            if ($control !== null && $this->accessibleNameSourceRendersWhenever($label, $control)) {
                $named[$control->index()] = true;
            }
        }

        return $named;
    }

    /** @param array<string, ElementNode> $dynamicControlsById */
    private function labeledControl(ElementNode $label, array $dynamicControlsById): ?ElementNode
    {
        if ($label->hasAttribute('for')) {
            if ($label->attributeIsDynamic('for')) {
                $key = $this->elementTreeKey($label)."\0".($label->attribute('for')?->valueText() ?? '');

                return $dynamicControlsById[$key] ?? null;
            }

            $for = $label->staticAttributeValue('for');
            if ($for === null || $for === '') {
                return null;
            }

            $target = $this->elementByIdInTree($label, $for);

            return $target !== null && $this->isLabelableControl($target) ? $target : null;
        }

        foreach ($label->descendants() as $descendant) {
            if ($descendant instanceof ElementNode
                && ! $this->crossesRenderedTreeBoundary($descendant, $label)
                && $this->isLabelableControl($descendant)) {
                return $descendant;
            }
        }

        return null;
    }

    /** @return array<string, ElementNode> */
    private function dynamicControlsById(RuleContext $context): array
    {
        $controls = [];

        foreach ($context->elements() as $element) {
            if (! $element->hasAttribute('id')
                || ! $element->attributeIsDynamic('id')
                || ! $this->isLabelableControl($element)) {
                continue;
            }

            $id = $element->attribute('id')?->valueText() ?? '';

            if ($id !== '') {
                $controls[$this->elementTreeKey($element)."\0".$id] ??= $element;
            }
        }

        return $controls;
    }

    private function isLabelableControl(ElementNode $element): bool
    {
        $tagName = strtolower($element->tagNameText());

        if ($tagName === 'input') {
            return ($element->staticAttributeValueLower('type') ?? 'text') !== 'hidden';
        }

        return in_array($tagName, ['button', 'meter', 'output', 'progress', 'select', 'textarea'], true);
    }
}
