<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Concerns;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;

/** @internal */
trait ChecksRenderPathGuarantees
{
    private const FLOW_NORMAL = 1;

    private const FLOW_BREAK = 2;

    private const FLOW_CONTINUE = 4;

    private const FLOW_BLOCKED = 8;

    /**
     * @param  iterable<mixed>  $nodes
     * @param  callable(mixed): bool  $matches
     * @param  (callable(Node): bool)|null  $shouldDescend
     */
    protected function everyRenderPathContains(iterable $nodes, callable $matches, ?callable $shouldDescend = null): bool
    {
        return $this->unmatchedSequenceOutcomes($nodes, $matches, $shouldDescend, null) === 0;
    }

    /**
     * Determine whether every path encounters a match before encountering a
     * node that makes a later match too late to satisfy the requirement.
     *
     * @param  iterable<mixed>  $nodes
     * @param  callable(mixed): bool  $matches
     * @param  callable(mixed): bool  $blocks
     * @param  (callable(Node): bool)|null  $shouldDescend
     */
    protected function everyRenderPathContainsBefore(
        iterable $nodes,
        callable $matches,
        callable $blocks,
        ?callable $shouldDescend = null,
    ): bool {
        return $this->unmatchedSequenceOutcomes($nodes, $matches, $shouldDescend, $blocks) === 0;
    }

    /**
     * Return the ways an execution path can leave a sequence without first
     * encountering a matching node.
     *
     * @param  iterable<mixed>  $nodes
     * @param  callable(mixed): bool  $matches
     * @param  (callable(Node): bool)|null  $shouldDescend
     */
    private function unmatchedSequenceOutcomes(
        iterable $nodes,
        callable $matches,
        ?callable $shouldDescend,
        ?callable $blocks,
    ): int {
        $outcomes = self::FLOW_NORMAL;

        foreach ($nodes as $node) {
            if (($outcomes & self::FLOW_NORMAL) === 0) {
                continue;
            }

            if ($matches($node)) {
                $outcomes &= ~self::FLOW_NORMAL;

                continue;
            }

            if ($blocks !== null && $blocks($node)) {
                $outcomes = ($outcomes & ~self::FLOW_NORMAL) | self::FLOW_BLOCKED;

                continue;
            }

            if (! $node instanceof Node) {
                continue;
            }

            $outcomes = ($outcomes & ~self::FLOW_NORMAL)
                | $this->unmatchedNodeOutcomes($node, $matches, $shouldDescend, $blocks);
        }

        return $outcomes;
    }

    /**
     * @param  callable(mixed): bool  $matches
     * @param  (callable(Node): bool)|null  $shouldDescend
     */
    private function unmatchedNodeOutcomes(
        Node $node,
        callable $matches,
        ?callable $shouldDescend,
        ?callable $blocks,
    ): int {
        if ($shouldDescend !== null && ! $shouldDescend($node)) {
            return self::FLOW_NORMAL;
        }

        if ($node instanceof DirectiveNode) {
            $name = strtolower($node->nameText());

            if (in_array($name, ['break', 'continue'], true)) {
                $exit = $name === 'break' ? self::FLOW_BREAK : self::FLOW_CONTINUE;
                $arguments = trim($node->arguments() ?? '');

                return $arguments !== '' && ! ctype_digit($arguments)
                    ? self::FLOW_NORMAL | $exit
                    : $exit;
            }
        }

        if ($node instanceof ElementNode) {
            return $this->unmatchedSequenceOutcomes($node->children(), $matches, $shouldDescend, $blocks);
        }

        if ($node instanceof DirectiveBlockNode) {
            return $this->unmatchedDirectiveBlockOutcomes($node, $matches, $shouldDescend, $blocks);
        }

        return self::FLOW_NORMAL;
    }

    /**
     * @param  callable(mixed): bool  $matches
     * @param  (callable(Node): bool)|null  $shouldDescend
     */
    private function unmatchedDirectiveBlockOutcomes(
        DirectiveBlockNode $block,
        callable $matches,
        ?callable $shouldDescend,
        ?callable $blocks,
    ): int {
        $name = strtolower($block->nameText());
        $start = $block->startDirective();

        if ($start === null) {
            return self::FLOW_NORMAL;
        }

        if (in_array($name, ['push', 'pushif', 'pushonce', 'prepend', 'prependonce'], true)) {
            return self::FLOW_NORMAL;
        }

        if ($name === 'section') {
            if ($block->endDirective()?->isDirectiveNamed('show') ?? false) {
                return $this->unmatchedSequenceOutcomes($start->children(), $matches, $shouldDescend, $blocks);
            }

            foreach ($block->intermediateDirectives() as $directive) {
                if (strtolower($directive->nameText()) === 'show') {
                    return $this->unmatchedSequenceOutcomes($start->children(), $matches, $shouldDescend, $blocks);
                }
            }

            return self::FLOW_NORMAL;
        }

        if (in_array($name, ['foreach', 'for', 'while'], true)) {
            // These loops may execute zero times. Any unmatched loop exit also
            // continues normally after the loop.
            $bodyOutcomes = $this->unmatchedSequenceOutcomes(
                $start->children(),
                $matches,
                $shouldDescend,
                $blocks,
            );

            return self::FLOW_NORMAL | ($bodyOutcomes & self::FLOW_BLOCKED);
        }

        if ($name === 'forelse') {
            $emptyOutcomes = self::FLOW_NORMAL;
            $hasEmpty = false;

            foreach ($block->intermediateDirectives() as $branch) {
                if (strtolower($branch->nameText()) !== 'empty') {
                    continue;
                }

                $hasEmpty = true;
                $emptyOutcomes = $this->unmatchedSequenceOutcomes(
                    $branch->children(),
                    $matches,
                    $shouldDescend,
                    $blocks,
                );
            }

            if (! $hasEmpty) {
                return self::FLOW_NORMAL;
            }

            $nonEmptyOutcomes = $this->unmatchedSequenceOutcomes(
                $start->children(),
                $matches,
                $shouldDescend,
                $blocks,
            );
            if ($nonEmptyOutcomes !== 0) {
                $nonEmptyOutcomes = self::FLOW_NORMAL | ($nonEmptyOutcomes & self::FLOW_BLOCKED);
            }

            return $emptyOutcomes | $nonEmptyOutcomes;
        }

        if ($name === 'switch') {
            return $this->unmatchedSwitchOutcomes($start, $matches, $shouldDescend, $blocks);
        }

        $outcomes = 0;
        $hasElse = false;

        foreach ([$start, ...iterator_to_array($block->intermediateDirectives())] as $branch) {
            $hasElse = $hasElse || strtolower($branch->nameText()) === 'else';
            $outcomes |= $this->unmatchedSequenceOutcomes(
                $branch->children(),
                $matches,
                $shouldDescend,
                $blocks,
            );
        }

        if (! $hasElse) {
            $outcomes |= self::FLOW_NORMAL;
        }

        return $outcomes;
    }

    /**
     * @param  callable(mixed): bool  $matches
     * @param  (callable(Node): bool)|null  $shouldDescend
     */
    private function unmatchedSwitchOutcomes(
        DirectiveNode $start,
        callable $matches,
        ?callable $shouldDescend,
        ?callable $blocks,
    ): int {
        $branches = [];
        $hasDefault = false;

        foreach ($start->children() as $child) {
            if (! $child instanceof DirectiveNode
                || ! in_array(strtolower($child->nameText()), ['case', 'default'], true)) {
                continue;
            }

            $branches[] = $child;
            $hasDefault = $hasDefault || strtolower($child->nameText()) === 'default';
        }

        $outcomes = $hasDefault ? 0 : self::FLOW_NORMAL;

        foreach (array_keys($branches) as $entryIndex) {
            $path = [];

            for ($index = $entryIndex; $index < count($branches); $index++) {
                array_push($path, ...iterator_to_array($branches[$index]->children()));
            }

            $branchOutcomes = $this->unmatchedSequenceOutcomes($path, $matches, $shouldDescend, $blocks);

            // Normal fallthrough and break both resume after this switch.
            if (($branchOutcomes & (self::FLOW_NORMAL | self::FLOW_BREAK)) !== 0) {
                $outcomes |= self::FLOW_NORMAL;
            }

            $outcomes |= $branchOutcomes & (self::FLOW_CONTINUE | self::FLOW_BLOCKED);
        }

        return $outcomes;
    }
}
