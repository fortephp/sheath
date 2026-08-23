<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Concerns;

use Forte\Ast\DirectiveNode;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Ast\PhpBlockNode;
use Forte\Ast\PhpTagNode;
use Forte\Ast\TextNode;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Support\ReactiveAttributeSemantics;

/** @internal */
trait TraversesRenderedTree
{
    /**
     * @return iterable<Node>
     */
    protected function renderedChildren(Node $node): iterable
    {
        foreach ($node->children() as $child) {
            if ($child instanceof ElementNode) {
                if (ReactiveAttributeSemantics::isLocalTemplateRenderer($child)) {
                    yield from $this->renderedChildElements($child);

                    continue;
                }

                yield $child;

                continue;
            }

            yield $child;

            if ($this->isRenderedTreeBoundary($child)) {
                continue;
            }

            yield from $this->renderedChildren($child);
        }
    }

    /**
     * @return iterable<ElementNode>
     */
    protected function renderedChildElements(Node $node): iterable
    {
        foreach ($this->renderedChildren($node) as $child) {
            if ($child instanceof ElementNode) {
                yield $child;
            }
        }
    }

    protected function renderedParentElement(Node $node): ?ElementNode
    {
        $parent = $node->getParent();

        while ($parent !== null) {
            if ($this->isRenderedTreeBoundary($parent)) {
                return null;
            }

            if ($parent instanceof ElementNode) {
                if (ReactiveAttributeSemantics::isLocalTemplateRenderer($parent)) {
                    $parent = $parent->getParent();

                    continue;
                }

                return $parent;
            }

            if ($this->isNonOutputCaptureBlock($parent)) {
                return null;
            }

            $parent = $parent->getParent();
        }

        return null;
    }

    protected function isTemplateFragmentRoot(Node $node): bool
    {
        return $this->renderedParentElement($node) === null;
    }

    /**
     * Determine whether potentially rendered content follows a node inside an
     * ancestor. Whitespace, comments, and explicitly ignored element subtrees
     * do not count as rendered content.
     *
     * @param  array<string>  $ignoredElementNames
     */
    protected function hasRenderedContentAfter(
        Node $node,
        ElementNode $within,
        array $ignoredElementNames = [],
    ): bool {
        $ignoredElementNames = array_map(strtolower(...), $ignoredElementNames);

        foreach ($within->descendants() as $descendant) {
            if ($descendant->startOffset() < $node->endOffset()) {
                continue;
            }

            if ($descendant->isComment()) {
                continue;
            }

            if ($descendant instanceof TextNode && trim($descendant->getDocumentContent()) === '') {
                continue;
            }

            if ($this->isNonRenderingNode($descendant)) {
                continue;
            }

            if ($this->isWithinIgnoredTrailingElement($descendant, $node, $within, $ignoredElementNames)) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Find the last potentially rendered node while ignoring complete element
     * subtrees. This lets callers answer many "is this last?" queries after a
     * single traversal of the container.
     *
     * @param  array<string>  $ignoredElementNames
     */
    protected function lastRenderedContentStartOffset(
        ElementNode $within,
        array $ignoredElementNames = [],
    ): ?int {
        $ignoredElementNames = array_map(strtolower(...), $ignoredElementNames);
        $lastOffset = null;

        foreach ($within->descendants() as $descendant) {
            if ($descendant->isComment()) {
                continue;
            }

            if ($descendant instanceof TextNode && trim($descendant->getDocumentContent()) === '') {
                continue;
            }

            if ($this->isNonRenderingNode($descendant)) {
                continue;
            }

            if ($this->isWithinIgnoredElement($descendant, $within, $ignoredElementNames)) {
                continue;
            }

            $lastOffset = max($lastOffset ?? -1, $descendant->startOffset());
        }

        return $lastOffset;
    }

    /** @param array<string> $ignoredElementNames */
    private function isWithinIgnoredTrailingElement(
        Node $descendant,
        Node $after,
        ElementNode $within,
        array $ignoredElementNames,
    ): bool {
        $candidate = $descendant;

        while ($candidate !== $within) {
            if ($candidate instanceof ElementNode
                && $candidate->startOffset() >= $after->endOffset()
                && $candidate->isTag($ignoredElementNames)) {
                return true;
            }

            $parent = $candidate->getParent();
            if ($parent === null) {
                break;
            }

            $candidate = $parent;
        }

        return false;
    }

    private function isNonRenderingNode(Node $node): bool
    {
        if ($node instanceof PhpTagNode) {
            return ! $node->isShortEcho() && ! PhpSource::mayProduceOutput($node->code());
        }

        if ($node instanceof PhpBlockNode) {
            return ! PhpSource::mayProduceOutput($node->code());
        }

        if ($node instanceof DirectiveNode) {
            $name = strtolower($node->nameText());

            if ($name === 'php') {
                return ! PhpSource::mayProduceOutput(PhpSource::innerArguments($node->arguments()) ?? '');
            }

            return $node->isOpening()
                || $node->isIntermediate()
                || $node->isClosing()
                || in_array($name, ['break', 'continue'], true);
        }

        return false;
    }

    /** @param array<string> $ignoredElementNames */
    private function isWithinIgnoredElement(
        Node $descendant,
        ElementNode $within,
        array $ignoredElementNames,
    ): bool {
        $candidate = $descendant;

        while ($candidate !== $within) {
            if ($candidate instanceof ElementNode
                && $candidate->isTag($ignoredElementNames)) {
                return true;
            }

            $parent = $candidate->getParent();
            if ($parent === null) {
                break;
            }

            $candidate = $parent;
        }

        return false;
    }
}
