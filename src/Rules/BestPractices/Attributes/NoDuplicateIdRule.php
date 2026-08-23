<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\BestPractices\Attributes;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ChecksLoopContext;
use Forte\Sheath\Rules\Concerns\DetectsExclusiveBranches;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\ReactiveAttributeSemantics;

/** @internal */
class NoDuplicateIdRule extends AbstractRule
{
    use ChecksLoopContext;
    use DetectsExclusiveBranches;
    use DetectsOpaqueAttributes;

    public function getId(): string
    {
        return 'best-practices-no-duplicate-id';
    }

    public function getDescription(): string
    {
        return 'Disallow duplicate id attributes across the document.';
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
        /** @var array<string, array<string, array<int, ElementNode>>> $groups */
        $groups = [];

        foreach ($context->elements() as $element) {
            if ($this->isInsideNonOutputCapture($element)) {
                continue;
            }

            $idAttributes = $this->firstAttributesOnRenderPaths($element, 'id');
            if ($idAttributes === null) {
                continue;
            }

            foreach ($idAttributes as $idAttribute) {
                if ($idAttribute === null) {
                    continue;
                }

                if ($idAttribute->isDynamic()) {
                    continue;
                }

                $id = $idAttribute->decodedValueText() ?? '';
                if ($id !== '') {
                    $groups[$this->elementTreeKey($element)][$id][$element->index()] = $element;
                }
            }
        }

        $reportedOffsets = [];

        foreach ($groups as $groupsById) {
            foreach ($groupsById as $duplicateId => $elementsByIndex) {
                $elements = array_values($elementsByIndex);
                if (count($elements) < 2) {
                    continue;
                }

                foreach ($this->conflictingDuplicates($elements) as $element) {
                    $context->report(
                        $element,
                        "Duplicate id '{$duplicateId}'."
                    );
                    $reportedOffsets[$element->startOffset()] = true;
                }
            }
        }

        foreach ($context->elements() as $element) {
            $insideAlpineLoop = $this->isInsideAlpineForTemplate($element);

            if ($this->shouldSkipLoopRepetitionCheck($element, $insideAlpineLoop, $reportedOffsets)) {
                continue;
            }

            $idAttributes = $this->firstAttributesOnRenderPaths($element, 'id');
            if ($idAttributes === null) {
                continue;
            }

            foreach ($idAttributes as $idAttribute) {
                if ($idAttribute === null) {
                    continue;
                }

                if ($idAttribute->isDynamic()) {
                    continue;
                }

                $id = $idAttribute->decodedValueText() ?? '';
                if ($id === '') {
                    continue;
                }

                $context->report(
                    $element,
                    $insideAlpineLoop
                        ? "Static id '{$id}' is repeated when the surrounding Alpine x-for renders more than once."
                        : "Static id '{$id}' is repeated when the surrounding Blade loop renders more than once."
                );

                break;
            }
        }
    }

    /** @param array<int, true> $reportedOffsets */
    private function shouldSkipLoopRepetitionCheck(
        ElementNode $element,
        bool $insideAlpineLoop,
        array $reportedOffsets,
    ): bool {
        if ($this->isInsideNonOutputCapture($element)
            || isset($reportedOffsets[$element->startOffset()])) {
            return true;
        }

        if ($element->hasAncestorElement('template') && ! $insideAlpineLoop) {
            return true;
        }

        return ! $insideAlpineLoop && ! $this->isInsideLoop($element);
    }

    private function isInsideAlpineForTemplate(ElementNode $element): bool
    {
        foreach ($element->ancestors() as $ancestor) {
            if ($ancestor instanceof ElementNode
                && ReactiveAttributeSemantics::isLocalTemplateRenderer($ancestor)
                && $ancestor->hasAttribute('x-for')) {
                return true;
            }
        }

        return false;
    }
}
