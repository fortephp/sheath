<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Concerns;

use Forte\Ast\DoctypeNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;

/** @internal */
trait ValidatesDocumentStructure
{
    protected function isFullDocument(Document $document): bool
    {
        return $this->hasHtmlElement($document)
            || $this->hasHeadElement($document)
            || $this->hasBodyElement($document);
    }

    protected function isPartialTemplate(Document $document): bool
    {
        return ! $this->isFullDocument($document);
    }

    protected function hasHtmlElement(Document $document): bool
    {
        return $document->hasElement('html');
    }

    protected function hasHeadElement(Document $document): bool
    {
        return $document->hasElement('head');
    }

    protected function hasBodyElement(Document $document): bool
    {
        return $document->hasElement('body');
    }

    protected function getHtmlElement(Document $document): ?ElementNode
    {
        return $document->firstElement('html');
    }

    protected function getHeadElement(Document $document): ?ElementNode
    {
        return $document->firstElement('head');
    }

    protected function getBodyElement(Document $document): ?ElementNode
    {
        return $document->firstElement('body');
    }

    /**
     * @return list<ElementNode>
     */
    protected function getHeadElementsByTagName(Document $document, string $tagName): array
    {
        $head = $this->getHeadElement($document);
        if ($head === null) {
            return [];
        }

        $tagName = strtolower($tagName);
        $elements = [];

        foreach ($head->descendants() as $descendant) {
            if ($descendant instanceof ElementNode
                && $descendant->isTag($tagName)
                && ! $this->crossesRenderedTreeBoundary($descendant, $head)
                && ! $this->isInsideNonOutputCapture($descendant)) {
                $elements[] = $descendant;
            }
        }

        return $elements;
    }

    protected function hasDoctype(Document $document): bool
    {
        return $this->getDoctypeNodes($document) !== [];
    }

    /**
     * @return array<DoctypeNode>
     */
    protected function getDoctypeNodes(Document $document): array
    {
        $nodes = [];

        foreach ($document->allOfType(DoctypeNode::class, true) as $node) {
            if ($node instanceof DoctypeNode) {
                $nodes[] = $node;
            }
        }

        usort($nodes, static fn (DoctypeNode $a, DoctypeNode $b): int => $a->startOffset() <=> $b->startOffset());

        return $nodes;
    }

    protected function getDoctypeBefore(Document $document, int $offset): ?DoctypeNode
    {
        $found = null;

        foreach ($this->getDoctypeNodes($document) as $node) {
            if ($node->startOffset() < $offset) {
                $found = $node;
            }
        }

        return $found;
    }
}
