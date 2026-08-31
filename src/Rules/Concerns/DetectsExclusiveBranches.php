<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Concerns;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Node;
use Forte\Sheath\Parsing\ExpressionScanner;

/** @internal */
trait DetectsExclusiveBranches
{
    /**
     * @template TNode of Node
     *
     * @param  array<TNode>  $group
     * @param  (callable(TNode, TNode): bool)|null  $cannotCorrelate
     * @return list<TNode>
     */
    protected function conflictingDuplicates(
        array $group,
        ?callable $cannotCorrelate = null,
    ): array {
        /** @var list<TNode> $seen */
        $seen = [];
        $conflicting = [];
        /** @var array<int, array<int, Node>> $ancestries */
        $ancestries = [];
        /** @var array<int, array{positions: array<int, int>, nextBreak: array<int, int|null>, segments: array<int, int>}> $switches */
        $switches = [];

        $buckets = $this->singleConditionalBuckets($group);
        if ($buckets !== null) {
            /** @var array<int, list<TNode>> $seenByBranch */
            $seenByBranch = [];

            foreach ($group as $node) {
                $nodeId = spl_object_id($node);
                $ancestries[$nodeId] = $this->ancestryOf($node);
                $branch = $buckets[$nodeId];

                foreach ($seenByBranch[$branch] ?? [] as $earlier) {
                    if ($cannotCorrelate !== null && $cannotCorrelate($earlier, $node)) {
                        continue;
                    }

                    if (! $this->nodesAreMutuallyExclusiveFromChains(
                        $earlier,
                        $node,
                        $ancestries[spl_object_id($earlier)],
                        $ancestries[$nodeId],
                        $switches,
                    )) {
                        $conflicting[] = $node;
                        break;
                    }
                }

                $seenByBranch[$branch][] = $node;
            }

            return $conflicting;
        }

        $buckets = $this->singleSwitchBuckets($group, $switches);
        if ($buckets !== null) {
            /** @var array<int, list<TNode>> $seenBySegment */
            $seenBySegment = [];

            foreach ($group as $node) {
                $nodeId = spl_object_id($node);
                $ancestries[$nodeId] = $this->ancestryOf($node);
                $segment = $buckets[$nodeId];

                foreach ($seenBySegment[$segment] ?? [] as $earlier) {
                    if ($cannotCorrelate !== null && $cannotCorrelate($earlier, $node)) {
                        continue;
                    }

                    if (! $this->nodesAreMutuallyExclusiveFromChains(
                        $earlier,
                        $node,
                        $ancestries[spl_object_id($earlier)],
                        $ancestries[$nodeId],
                        $switches,
                    )) {
                        $conflicting[] = $node;
                        break;
                    }
                }

                $seenBySegment[$segment][] = $node;
            }

            return $conflicting;
        }

        foreach ($group as $node) {
            $nodeId = spl_object_id($node);
            $ancestries[$nodeId] = $this->ancestryOf($node);

            foreach ($seen as $earlier) {
                if ($cannotCorrelate !== null && $cannotCorrelate($earlier, $node)) {
                    continue;
                }

                if (! $this->nodesAreMutuallyExclusiveFromChains(
                    $earlier,
                    $node,
                    $ancestries[spl_object_id($earlier)],
                    $ancestries[$nodeId],
                    $switches,
                )) {
                    $conflicting[] = $node;
                    break;
                }
            }

            $seen[] = $node;
        }

        return $conflicting;
    }

    protected function nodesAreMutuallyExclusive(Node $a, Node $b): bool
    {
        $switches = [];

        return $this->nodesAreMutuallyExclusiveFromChains(
            $a,
            $b,
            $this->ancestryOf($a),
            $this->ancestryOf($b),
            $switches,
        );
    }

    /** @return array{block: int, branch: int}|null */
    protected function conditionalBranchIdentity(Node $node): ?array
    {
        $ancestor = $node->getParent();

        while ($ancestor !== null) {
            $parent = $ancestor->getParent();
            $branch = $this->nonSwitchConditionalBranch($ancestor, $parent);

            if ($branch !== null) {
                return [
                    'block' => spl_object_id($branch['block']),
                    'branch' => $branch['directive']->index(),
                ];
            }

            $ancestor = $parent;
        }

        return null;
    }

    /** @return array{expression: string, when: bool}|null */
    protected function conditionalPredicateIdentity(Node|Attribute $node): ?array
    {
        return $this->conditionalPredicateIdentities($node)[0] ?? null;
    }

    /** @return list<array{expression: string, when: bool}> */
    protected function conditionalPredicateIdentities(Node|Attribute $node): array
    {
        $identities = [];
        if ($node instanceof Attribute) {
            $parentIndex = $node->getFlatNode()['parent'];
            $ancestor = $parentIndex >= 0 ? $node->getDocument()->getNode($parentIndex) : null;
        } else {
            $ancestor = $node instanceof DirectiveNode ? $node : $node->getParent();
        }

        while ($ancestor !== null) {
            $parent = $ancestor->getParent();
            $branch = $this->nonSwitchConditionalBranch($ancestor, $parent);

            if ($branch !== null) {
                $identity = $this->branchPredicateIdentity($branch['directive'], $branch['block']);
                if ($identity !== null) {
                    $identities[] = $identity;
                }
            }

            $ancestor = $parent;
        }

        return $identities;
    }

    /** @return array{directive: DirectiveNode, block: DirectiveBlockNode}|null */
    private function nonSwitchConditionalBranch(
        Node $ancestor,
        ?Node $parent,
    ): ?array {
        if (! $ancestor instanceof DirectiveNode
            || (! $ancestor->isOpening() && ! $ancestor->isIntermediate())) {
            return null;
        }

        if (! $parent instanceof DirectiveBlockNode
            || strtolower($parent->nameText()) === 'switch') {
            return null;
        }

        return ['directive' => $ancestor, 'block' => $parent];
    }

    protected function nodesHaveComplementaryConditionalPredicates(Node $a, Node $b): bool
    {
        foreach ($this->conditionalPredicateIdentities($a) as $predicateA) {
            foreach ($this->conditionalPredicateIdentities($b) as $predicateB) {
                if ($predicateA['expression'] === $predicateB['expression']
                    && $predicateA['when'] !== $predicateB['when']) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{expression: string, when: bool}|null */
    private function branchPredicateIdentity(DirectiveNode $branch, DirectiveBlockNode $block): ?array
    {
        if ($branch->isOpening()) {
            return $this->atomicPredicateIdentity($branch, openingBranch: true);
        }

        if (strtolower($branch->nameText()) !== 'else'
            || count(iterator_to_array($block->intermediateDirectives())) !== 1) {
            return null;
        }

        $opening = $block->startDirective();

        return $opening === null
            ? null
            : $this->atomicPredicateIdentity($opening, openingBranch: false);
    }

    /** @return array{expression: string, when: bool}|null */
    private function atomicPredicateIdentity(DirectiveNode $directive, bool $openingBranch): ?array
    {
        $name = strtolower($directive->nameText());
        $arguments = $directive->arguments();
        if (! in_array($name, ['if', 'unless'], true) || $arguments === null) {
            return null;
        }

        $expression = ExpressionScanner::stripOuterParentheses(trim($arguments));
        $when = $name === 'if';
        if (! $openingBranch) {
            $when = ! $when;
        }

        if (preg_match('/^!\s*(\$[A-Za-z_][A-Za-z0-9_]*)$/', $expression, $matches) === 1) {
            return ['expression' => $matches[1], 'when' => ! $when];
        }

        if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $expression) !== 1) {
            return null;
        }

        return ['expression' => $expression, 'when' => $when];
    }

    /**
     * @param  array<int, Node>  $chainA
     * @param  array<int, Node>  $chainB
     * @param  array<int, array{positions: array<int, int>, nextBreak: array<int, int|null>, segments: array<int, int>}>  $switches
     */
    private function nodesAreMutuallyExclusiveFromChains(
        Node $a,
        Node $b,
        array $chainA,
        array $chainB,
        array &$switches,
    ): bool {

        $sharedDepth = min(count($chainA), count($chainB));
        $depth = 0;

        while ($depth < $sharedDepth && $chainA[$depth]->index() === $chainB[$depth]->index()) {
            $depth++;
        }

        if ($depth >= $sharedDepth) {
            return false;
        }

        $branchA = $chainA[$depth];
        $branchB = $chainB[$depth];

        if (! $this->isBranchContainer($branchA) || ! $this->isBranchContainer($branchB)) {
            return false;
        }

        if ($this->areSwitchBranches($branchA, $branchB)) {
            return $this->switchBranchesAreSeparatedByBreak($branchA, $branchB, $switches);
        }

        return true;
    }

    private function isBranchContainer(Node $node): bool
    {
        return $node instanceof DirectiveNode
            && ($node->isOpening() || $node->isIntermediate());
    }

    private function areSwitchBranches(Node $a, Node $b): bool
    {
        if (! $a instanceof DirectiveNode || ! $b instanceof DirectiveNode) {
            return false;
        }

        if (! in_array(strtolower($a->nameText()), ['case', 'default'], true)
            || ! in_array(strtolower($b->nameText()), ['case', 'default'], true)) {
            return false;
        }

        $parent = $a->getParent();

        return $parent !== null
            && $parent->index() === $b->getParent()?->index()
            && $parent instanceof DirectiveNode
            && strtolower($parent->nameText()) === 'switch';
    }

    /**
     * @param  array<int, array{positions: array<int, int>, nextBreak: array<int, int|null>, segments: array<int, int>}>  $switches
     */
    private function switchBranchesAreSeparatedByBreak(Node $a, Node $b, array &$switches): bool
    {
        if (! $a instanceof DirectiveNode || ! $b instanceof DirectiveNode) {
            return false;
        }

        $earlier = $a->startOffset() <= $b->startOffset() ? $a : $b;
        $later = $earlier->index() === $a->index() ? $b : $a;
        $parent = $earlier->getParent();

        if (! $parent instanceof DirectiveNode) {
            return false;
        }

        $parentId = spl_object_id($parent);
        $switches[$parentId] ??= $this->indexSwitchBranches($parent);

        $switch = $switches[$parentId];
        $earlierPosition = $switch['positions'][$earlier->index()] ?? null;
        $laterPosition = $switch['positions'][$later->index()] ?? null;

        if ($earlierPosition === null || $laterPosition === null) {
            return false;
        }

        $nextBreak = $switch['nextBreak'][$earlierPosition] ?? null;

        return $nextBreak !== null && $nextBreak < $laterPosition;
    }

    /**
     * @template TNode of Node
     *
     * @param  array<TNode>  $group
     * @param  array<int, array{positions: array<int, int>, nextBreak: array<int, int|null>, segments: array<int, int>}>  $switches
     * @return array<int, int>|null
     */
    private function singleSwitchBuckets(array $group, array &$switches): ?array
    {
        $switchId = null;
        $buckets = [];

        foreach ($group as $node) {
            $branch = $this->containingSwitchBranch($node);
            if ($branch === null) {
                return null;
            }

            $parent = $branch->getParent();
            if (! $parent instanceof DirectiveNode || strtolower($parent->nameText()) !== 'switch') {
                return null;
            }

            $parentId = spl_object_id($parent);
            if ($switchId !== null && $switchId !== $parentId) {
                return null;
            }

            $switchId = $parentId;
            $switches[$parentId] ??= $this->indexSwitchBranches($parent);

            $segment = $switches[$parentId]['segments'][$branch->index()] ?? null;
            if ($segment === null) {
                return null;
            }

            $buckets[spl_object_id($node)] = $segment;
        }

        return $buckets;
    }

    /**
     * @template TNode of Node
     *
     * @param  array<TNode>  $group
     * @return array<int, int>|null
     */
    private function singleConditionalBuckets(array $group): ?array
    {
        $blockId = null;
        $buckets = [];

        foreach ($group as $node) {
            $identity = $this->conditionalBranchIdentity($node);
            if ($identity === null) {
                return null;
            }

            if ($blockId !== null && $identity['block'] !== $blockId) {
                return null;
            }

            $blockId = $identity['block'];
            $buckets[spl_object_id($node)] = $identity['branch'];
        }

        return $buckets;
    }

    private function containingSwitchBranch(Node $node): ?DirectiveNode
    {
        $ancestor = $node->getParent();

        while ($ancestor !== null) {
            if ($ancestor instanceof DirectiveNode
                && in_array(strtolower($ancestor->nameText()), ['case', 'default'], true)
                && $ancestor->getParent() instanceof DirectiveNode
                && strtolower($ancestor->getParent()->nameText()) === 'switch') {
                return $ancestor;
            }

            $ancestor = $ancestor->getParent();
        }

        return null;
    }

    /** @return array{positions: array<int, int>, nextBreak: array<int, int|null>, segments: array<int, int>} */
    private function indexSwitchBranches(DirectiveNode $parent): array
    {
        $branches = [];

        foreach ($parent->children() as $child) {
            if ($child instanceof DirectiveNode
                && in_array(strtolower($child->nameText()), ['case', 'default'], true)) {
                $branches[] = $child;
            }
        }

        $positions = [];
        $segments = [];
        $segment = 0;
        foreach ($branches as $position => $branch) {
            $positions[$branch->index()] = $position;
            $segments[$branch->index()] = $segment;

            if ($this->branchHasUnconditionalBreak($branch)) {
                $segment++;
            }
        }

        $nextBreak = [];
        $nearestBreak = null;

        for ($position = count($branches) - 1; $position >= 0; $position--) {
            if ($this->branchHasUnconditionalBreak($branches[$position])) {
                $nearestBreak = $position;
            }

            $nextBreak[$position] = $nearestBreak;
        }

        return ['positions' => $positions, 'nextBreak' => $nextBreak, 'segments' => $segments];
    }

    private function branchHasUnconditionalBreak(DirectiveNode $branch): bool
    {
        foreach ($branch->children() as $child) {
            if ($child instanceof DirectiveNode
                && strtolower($child->nameText()) === 'break'
                && ! $child->hasArguments()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, Node>
     */
    private function ancestryOf(Node $node): array
    {
        $chain = [$node];
        $parent = $node->getParent();

        while ($parent !== null) {
            $chain[] = $parent;
            $parent = $parent->getParent();
        }

        return array_reverse($chain);
    }
}
