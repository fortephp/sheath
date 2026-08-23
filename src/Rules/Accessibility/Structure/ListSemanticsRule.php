<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Structure;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ChecksElements;
use Forte\Sheath\Rules\Concerns\TraversesRenderedTree;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class ListSemanticsRule extends AbstractRule
{
    use ChecksElements;
    use TraversesRenderedTree;

    public function getId(): string
    {
        return 'a11y-list-semantics';
    }

    public function getDescription(): string
    {
        return 'List items must be inside proper list containers.';
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
        $elements = $document->elementsGroupedByName(['ul', 'ol', 'menu', 'li', 'dl', 'dt', 'dd']);

        /** @var array<int, bool> $validLiOffsets */
        $validLiOffsets = [];

        foreach (['ul', 'ol', 'menu'] as $container) {
            foreach ($elements[$container] as $list) {
                $this->collectValidListItems($list, $validLiOffsets);
            }
        }

        foreach ($elements['li'] as $li) {
            if (isset($validLiOffsets[$li->startOffset()])) {
                continue;
            }

            if ($this->isTemplateFragmentRoot($li)) {
                continue;
            }

            $context->report(
                $li,
                '<li> is not a direct child of <ul>, <ol>, or <menu>.'
            );
        }

        /** @var array<int, bool> $validDtDdOffsets */
        $validDtDdOffsets = [];

        foreach ($elements['dl'] as $dl) {
            $this->collectValidDlItems($dl, $validDtDdOffsets);
        }

        foreach (['dt', 'dd'] as $item) {
            foreach ($elements[$item] as $element) {
                if (isset($validDtDdOffsets[$element->startOffset()])) {
                    continue;
                }

                if ($this->isTemplateFragmentRoot($element)) {
                    continue;
                }

                $context->report(
                    $element,
                    "<{$item}> is not a direct child of <dl>."
                );
            }
        }
    }

    /**
     * @param  array<int, bool>  $validLiOffsets
     */
    private function collectValidListItems(ElementNode $list, array &$validLiOffsets): void
    {
        foreach ($this->renderedChildElements($list) as $child) {
            if ($child->isTag('li')) {
                $validLiOffsets[$child->startOffset()] = true;
            }
        }
    }

    /**
     * @param  array<int, bool>  $validDtDdOffsets
     */
    private function collectValidDlItems(ElementNode $dl, array &$validDtDdOffsets): void
    {
        foreach ($this->renderedChildElements($dl) as $child) {
            $tagName = strtolower($child->tagNameText());

            if ($tagName === 'dt' || $tagName === 'dd') {
                $validDtDdOffsets[$child->startOffset()] = true;

                continue;
            }

            if ($tagName === 'div') {
                foreach ($this->renderedChildElements($child) as $grandchild) {
                    $grandchildTag = strtolower($grandchild->tagNameText());
                    if ($grandchildTag === 'dt' || $grandchildTag === 'dd') {
                        $validDtDdOffsets[$grandchild->startOffset()] = true;
                    }
                }
            }
        }
    }
}
