<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\BestPractices\Markup;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ChecksElements;
use Forte\Sheath\Rules\Concerns\TraversesRenderedTree;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoNestedInteractiveRule extends AbstractRule
{
    use ChecksElements;
    use TraversesRenderedTree;

    public function getId(): string
    {
        return 'best-practices-no-nested-interactive';
    }

    public function getDescription(): string
    {
        return 'Interactive elements must not be nested inside other interactive elements.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BEST_PRACTICES;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void
    {
        /** @var array<int, true> $reportedDynamicLabels */
        $reportedDynamicLabels = [];

        $context->elements()
            ->each(function (ElementNode $element) use ($context, &$reportedDynamicLabels): void {
                if ($this->isInteractiveElement($element)) {
                    $this->checkForNestedInteractive($element, $context, $reportedDynamicLabels);

                    return;
                }

                if ($this->isLabelableControl($element)) {
                    $this->checkForNestedLabelableControl($element, $context, $reportedDynamicLabels);
                }

                if ($element->isTag('a') && $this->checkForNestedAnchor($element, $context)) {
                    return;
                }

                if ($this->hasAttributeOnAnyRenderPath($element, 'tabindex')) {
                    $this->checkForProhibitedTabindexDescendant($element, $context);
                }
            });
    }

    private const LABELABLE_CONTROLS = ['button', 'input', 'meter', 'output', 'progress', 'select', 'textarea'];

    /** @param array<int, true> $reportedDynamicLabels */
    private function checkForNestedInteractive(
        ElementNode $root,
        RuleContext $context,
        array &$reportedDynamicLabels,
    ): void {
        $ancestor = $this->renderedParentElement($root);

        while ($ancestor !== null) {
            if ($ancestor->isTag('template')) {
                return;
            }

            if (! $this->prohibitsInteractiveDescendants($ancestor)) {
                $ancestor = $this->renderedParentElement($ancestor);

                continue;
            }

            if ($ancestor->isTag('label')) {
                if ($this->labelAllowsControl($ancestor, $root, $context, $reportedDynamicLabels)) {
                    return;
                }
            }

            $context->report(
                $root,
                "Interactive element <{$root->tagNameText()}> is nested inside <{$ancestor->tagNameText()}>."
            );

            return;
        }
    }

    private function prohibitsInteractiveDescendants(ElementNode $element): bool
    {
        return $element->isTag(['a', 'button', 'label']);
    }

    private function checkForNestedAnchor(ElementNode $anchor, RuleContext $context): bool
    {
        $ancestor = $this->renderedParentElement($anchor);

        while ($ancestor !== null) {
            if ($ancestor->isTag('template')) {
                return false;
            }

            if ($ancestor->isTag('a')) {
                $context->report(
                    $anchor,
                    'Anchor element <a> is nested inside another <a>.'
                );

                return true;
            }

            $ancestor = $this->renderedParentElement($ancestor);
        }

        return false;
    }

    private function checkForProhibitedTabindexDescendant(ElementNode $element, RuleContext $context): void
    {
        $ancestor = $this->renderedParentElement($element);

        while ($ancestor !== null) {
            if ($ancestor->isTag('template')) {
                return;
            }

            if ($ancestor->isTag(['a', 'button'])) {
                $context->report(
                    $element,
                    "Element <{$element->tagNameText()}> with tabindex is nested inside <{$ancestor->tagNameText()}>."
                );

                return;
            }

            $ancestor = $this->renderedParentElement($ancestor);
        }
    }

    private function isLabeledControl(ElementNode $label, ElementNode $candidate): bool
    {
        if (! $this->isLabelableControl($candidate)) {
            return false;
        }

        if ($label->hasAttribute('for')) {
            if ($label->attributeIsDynamic('for')) {
                return true;
            }

            $for = $label->staticAttributeValue('for') ?? '';

            return $for !== ''
                && ! $candidate->attributeIsDynamic('id')
                && $candidate->staticAttributeValue('id') === $for;
        }

        foreach ($label->descendants() as $descendant) {
            if ($descendant instanceof ElementNode
                && ! $this->crossesRenderedTreeBoundary($descendant, $label)
                && $this->isLabelableControl($descendant)) {
                return $descendant === $candidate;
            }
        }

        return false;
    }

    /** @param array<int, true> $reportedDynamicLabels */
    private function labelAllowsControl(
        ElementNode $label,
        ElementNode $control,
        RuleContext $context,
        array &$reportedDynamicLabels,
    ): bool {
        if (! $label->attributeIsDynamic('for')) {
            return $this->isLabeledControl($label, $control);
        }

        if (count($this->nestedLabelableControls($label)) > 1
            && ! isset($reportedDynamicLabels[$label->index()])) {
            $reportedDynamicLabels[$label->index()] = true;
            $context->report(
                $label,
                'Label with a dynamic for attribute contains multiple labelable controls.'
            );
        }

        return true;
    }

    /** @param array<int, true> $reportedDynamicLabels */
    private function checkForNestedLabelableControl(
        ElementNode $control,
        RuleContext $context,
        array &$reportedDynamicLabels,
    ): void {
        $ancestor = $this->renderedParentElement($control);

        while ($ancestor !== null) {
            if ($ancestor->isTag('template')) {
                return;
            }

            if (! $ancestor->isTag('label')) {
                $ancestor = $this->renderedParentElement($ancestor);

                continue;
            }

            if ($this->labelAllowsControl($ancestor, $control, $context, $reportedDynamicLabels)) {
                return;
            }

            $context->report(
                $control,
                "Nested <{$control->tagNameText()}> is not the label's labeled control."
            );

            return;
        }
    }

    /** @return list<ElementNode> */
    private function nestedLabelableControls(ElementNode $label): array
    {
        $controls = [];

        foreach ($label->descendants() as $descendant) {
            if ($descendant instanceof ElementNode
                && ! $this->crossesRenderedTreeBoundary($descendant, $label)
                && $this->isLabelableControl($descendant)) {
                $controls[] = $descendant;
            }
        }

        return $controls;
    }

    private function isLabelableControl(ElementNode $element): bool
    {
        if (! $this->matchesTagName($element, self::LABELABLE_CONTROLS)) {
            return false;
        }

        if (! $element->isTag('input')) {
            return true;
        }

        $typeAttributes = $this->firstAttributesOnRenderPaths($element, 'type');
        if ($typeAttributes === null) {
            return false;
        }

        foreach ($typeAttributes as $typeAttribute) {
            if ($typeAttribute === null) {
                return true;
            }

            if (! $typeAttribute->isDynamic()
                && strcasecmp($typeAttribute->decodedValueText() ?? '', 'hidden') !== 0) {
                return true;
            }
        }

        return false;
    }
}
