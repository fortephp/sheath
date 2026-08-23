<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Concerns;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Node;
use Forte\Support\LoopVariables;
use Forte\Support\LoopVariablesExtractor;

/** @internal */
trait ChecksLoopContext
{
    private const LOOP_DIRECTIVES = ['foreach', 'forelse', 'for', 'while'];

    protected function isInsideLoop(Node $node): bool
    {
        return $this->getContainingLoop($node) !== null;
    }

    protected function getContainingLoop(Node $node): ?DirectiveBlockNode
    {
        $ancestor = $node->getParent();

        while ($ancestor !== null) {
            if ($ancestor instanceof DirectiveBlockNode
                && $this->isLoopDirective($ancestor)
                && ! $this->isInForelseEmptyBranch($node, $ancestor)) {
                return $ancestor;
            }

            $ancestor = $ancestor->getParent();
        }

        return null;
    }

    protected function isLoopDirective(DirectiveBlockNode $block): bool
    {
        return $block->isAnyDirectiveNamed(self::LOOP_DIRECTIVES);
    }

    private function isInForelseEmptyBranch(Node $node, DirectiveBlockNode $loop): bool
    {
        if (! $loop->isDirectiveNamed('forelse')) {
            return false;
        }

        $ancestor = $node->getParent();

        while ($ancestor !== null && $ancestor->index() !== $loop->index()) {
            if ($ancestor instanceof DirectiveNode
                && $ancestor->isDirectiveNamed('empty')) {
                return true;
            }

            $ancestor = $ancestor->getParent();
        }

        return false;
    }

    protected function extractLoopVariables(DirectiveBlockNode $loop): LoopVariables
    {
        $args = $loop->arguments() ?? '';

        return (new LoopVariablesExtractor)->extractDetails($args);
    }

    protected function getLoopVariable(DirectiveBlockNode $loop): ?string
    {
        $variables = $this->extractLoopVariables($loop);

        return $variables->isValid ? $variables->alias : null;
    }

    protected function getLoopCollection(DirectiveBlockNode $loop): ?string
    {
        $variables = $this->extractLoopVariables($loop);

        return $variables->isValid ? $variables->variable : null;
    }

    /**
     * @return array<string>
     */
    protected function getLoopDirectiveNames(): array
    {
        return self::LOOP_DIRECTIVES;
    }
}
