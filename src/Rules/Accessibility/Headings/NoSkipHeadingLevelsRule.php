<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Headings;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\TraversesRenderedTree;
use Forte\Sheath\Rules\Concerns\ValidatesHeadings;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\ReactiveAttributeSemantics;

/** @internal */
class NoSkipHeadingLevelsRule extends AbstractRule
{
    use TraversesRenderedTree;
    use ValidatesHeadings;

    public function getId(): string
    {
        return 'a11y-no-skip-heading-levels';
    }

    public function getDescription(): string
    {
        return 'Heading levels should only increase by one level at a time.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::ACCESSIBILITY;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    /** @var array<string, int> */
    private array $predicateLastUse = [];

    public function check(Document $document, RuleContext $context): void
    {
        $this->predicateLastUse = [];
        $this->recordPredicateUses($document->children(), null);

        $reported = [];
        $this->walkHeadingSequence(
            $document->children(),
            [['level' => 0, 'predicates' => []]],
            $context,
            $reported,
        );
    }

    /**
     * @param  iterable<mixed>  $nodes
     * @param  list<array{level: int, predicates: array<string, bool>}>  $states
     * @param  array<int, true>  $reported
     * @return list<array{level: int, predicates: array<string, bool>}>
     */
    private function walkHeadingSequence(
        iterable $nodes,
        array $states,
        RuleContext $context,
        array &$reported,
    ): array {
        foreach ($nodes as $node) {
            if ($node instanceof Node) {
                $states = $this->forgetSettledPredicates($states, $node->startOffset());
            }

            if ($node instanceof ElementNode) {
                if ($node->isTag('template')) {
                    if (ReactiveAttributeSemantics::isLocalTemplateRenderer($node)) {
                        $predicate = 'template:'.$node->index();
                        $visibleStates = $this->withHeadingPredicate($states, $predicate, true);
                        $afterOneRender = $this->walkHeadingSequence(
                            $node->children(),
                            $visibleStates,
                            $context,
                            $reported,
                        );

                        $outcomes = [
                            ...$this->withHeadingPredicate($states, $predicate, false),
                            ...$afterOneRender,
                        ];

                        if ($node->hasAttribute('x-for')) {
                            array_push(
                                $outcomes,
                                ...$this->walkHeadingSequence(
                                    $node->children(),
                                    $afterOneRender,
                                    $context,
                                    $reported,
                                ),
                            );
                        }

                        $states = $this->uniqueHeadingStates($outcomes);
                    }

                    continue;
                }

                $beforeElement = $states;
                $constraints = [];
                foreach ($this->attributesInRenderStructure($node) as $attribute) {
                    $constraint = ReactiveAttributeSemantics::visibilityConstraint($attribute, $node);
                    if ($constraint !== null) {
                        $constraints[$constraint] = true;
                    }
                }

                foreach (array_keys($constraints) as $constraint) {
                    $states = $this->withHeadingPredicate($states, $constraint, true);
                }

                $levels = $this->effectiveHeadingLevels($node);
                if ($levels !== []) {
                    $nextStates = [];
                    foreach ($states as $state) {
                        foreach ($levels as $level) {
                            $previous = $state['level'];
                            if ($previous > 0
                                && $level > $previous + 1
                                && ! isset($reported[$node->index()])) {
                                $context->report(
                                    $node,
                                    "Heading level jumps from h{$previous} to h{$level}."
                                );
                                $reported[$node->index()] = true;
                            }

                            $nextStates[] = [
                                'level' => $level,
                                'predicates' => $state['predicates'],
                            ];
                        }
                    }

                    $states = $this->uniqueHeadingStates($nextStates);
                }

                $states = $this->walkHeadingSequence(
                    $node->children(),
                    $states,
                    $context,
                    $reported,
                );

                foreach (array_keys($constraints) as $constraint) {
                    array_push(
                        $states,
                        ...$this->withHeadingPredicate($beforeElement, $constraint, false),
                    );
                }

                $states = $this->uniqueHeadingStates($states);

                continue;
            }

            if ($node instanceof DirectiveBlockNode) {
                $states = $this->walkDirectiveBlock(
                    $node,
                    $states,
                    $context,
                    $reported,
                );
            }
        }

        return $states;
    }

    /**
     * @param  list<array{level: int, predicates: array<string, bool>}>  $states
     * @param  array<int, true>  $reported
     * @return list<array{level: int, predicates: array<string, bool>}>
     */
    private function walkDirectiveBlock(
        DirectiveBlockNode $block,
        array $states,
        RuleContext $context,
        array &$reported,
    ): array {
        $start = $block->startDirective();
        if ($start === null || $this->isRenderedTreeBoundary($block)) {
            return $states;
        }

        $name = strtolower($block->nameText());
        if ($this->isNonOutputCaptureBlock($block)) {
            return $states;
        }
        if ($name === 'switch') {
            return $this->walkHeadingSwitch($start, $states, $context, $reported);
        }
        if (in_array($name, ['foreach', 'for', 'while'], true)) {
            $afterOneIteration = $this->walkHeadingSequence(
                $start->children(),
                $states,
                $context,
                $reported,
            );

            $afterRepeatedIteration = $this->walkHeadingSequence(
                $start->children(),
                $afterOneIteration,
                $context,
                $reported,
            );

            return $this->uniqueHeadingStates([
                ...$states,
                ...$afterOneIteration,
                ...$afterRepeatedIteration,
            ]);
        }

        $outcomes = [];
        $hasFallback = false;

        foreach ([$start, ...iterator_to_array($block->intermediateDirectives())] as $branch) {
            $branchName = strtolower($branch->nameText());
            $hasFallback = $hasFallback || in_array($branchName, ['else', 'empty', 'default'], true);
            array_push(
                $outcomes,
                ...$this->walkHeadingSequence(
                    $branch->children(),
                    $states,
                    $context,
                    $reported,
                ),
            );
        }

        if (! $hasFallback) {
            array_push($outcomes, ...$states);
        }

        return $this->uniqueHeadingStates($outcomes);
    }

    /**
     * @param  list<array{level: int, predicates: array<string, bool>}>  $states
     * @param  array<int, true>  $reported
     * @return list<array{level: int, predicates: array<string, bool>}>
     */
    private function walkHeadingSwitch(
        DirectiveNode $switch,
        array $states,
        RuleContext $context,
        array &$reported,
    ): array {
        $branches = [];
        $hasDefault = false;

        foreach ($switch->children() as $child) {
            if (! $child instanceof DirectiveNode
                || ! in_array(strtolower($child->nameText()), ['case', 'default'], true)) {
                continue;
            }

            $branches[] = $child;
            $hasDefault = $hasDefault || strtolower($child->nameText()) === 'default';
        }

        $outcomes = $hasDefault ? [] : $states;

        foreach (array_keys($branches) as $entry) {
            $branchStates = $states;

            for ($index = $entry; $index < count($branches); $index++) {
                $branchStates = $this->walkHeadingSequence(
                    $branches[$index]->children(),
                    $branchStates,
                    $context,
                    $reported,
                );

                if ($this->headingSwitchBranchHasUnconditionalBreak($branches[$index])) {
                    break;
                }
            }

            array_push($outcomes, ...$branchStates);
        }

        return $this->uniqueHeadingStates($outcomes);
    }

    /**
     * @param  list<array{level: int, predicates: array<string, bool>}>  $states
     * @return list<array{level: int, predicates: array<string, bool>}>
     */
    private function withHeadingPredicate(array $states, string $predicate, bool $value): array
    {
        $outcomes = [];

        foreach ($states as $state) {
            if (isset($state['predicates'][$predicate])
                && $state['predicates'][$predicate] !== $value) {
                continue;
            }

            $state['predicates'][$predicate] = $value;
            $outcomes[] = $state;
        }

        return $outcomes;
    }

    /**
     * @param  iterable<mixed>  $nodes
     */
    private function recordPredicateUses(iterable $nodes, ?int $loopEnd): void
    {
        foreach ($nodes as $node) {
            if (! $node instanceof Node) {
                continue;
            }

            // The walk revisits loop bodies, so a use inside a loop must stay
            // live until the outermost loop ends or a second pass reads a
            // forgotten predicate.
            $childLoopEnd = $loopEnd;

            if ($node instanceof ElementNode) {
                $keys = [];

                if ($node->isTag('template') && ReactiveAttributeSemantics::isLocalTemplateRenderer($node)) {
                    $keys[] = 'template:'.$node->index();

                    if ($node->hasAttribute('x-for')) {
                        $childLoopEnd ??= $this->settledAfter($node);
                    }
                }

                foreach ($this->attributesInRenderStructure($node) as $attribute) {
                    $constraint = ReactiveAttributeSemantics::visibilityConstraint($attribute, $node);
                    if ($constraint !== null) {
                        $keys[] = $constraint;
                    }
                }

                $useEnd = $loopEnd ?? $this->settledAfter($node);
                foreach ($keys as $key) {
                    $this->predicateLastUse[$key] = max($this->predicateLastUse[$key] ?? 0, $useEnd);
                }
            }

            if ($node instanceof DirectiveBlockNode
                && in_array(strtolower($node->nameText()), ['foreach', 'for', 'while'], true)) {
                $childLoopEnd ??= $this->settledAfter($node);
            }

            $this->recordPredicateUses($node->children(), $childLoopEnd);
        }
    }

    private function settledAfter(Node $node): int
    {
        $end = $node->endOffset();

        return $end < 0 ? PHP_INT_MAX : $end;
    }

    /**
     * Without this, every independent x-show doubles the state count.
     *
     * @param  list<array{level: int, predicates: array<string, bool>}>  $states
     * @return list<array{level: int, predicates: array<string, bool>}>
     */
    private function forgetSettledPredicates(array $states, int $position): array
    {
        if ($position < 0) {
            return $states;
        }

        $changed = false;

        foreach ($states as $index => $state) {
            foreach (array_keys($state['predicates']) as $predicate) {
                if (($this->predicateLastUse[$predicate] ?? PHP_INT_MAX) <= $position) {
                    unset($states[$index]['predicates'][$predicate]);
                    $changed = true;
                }
            }
        }

        return $changed ? $this->uniqueHeadingStates($states) : $states;
    }

    /**
     * @param  list<array{level: int, predicates: array<string, bool>}>  $states
     * @return list<array{level: int, predicates: array<string, bool>}>
     */
    private function uniqueHeadingStates(array $states): array
    {
        $unique = [];

        foreach ($states as $state) {
            ksort($state['predicates']);
            $key = $state['level'].'|'.json_encode($state['predicates'], JSON_THROW_ON_ERROR);
            $unique[$key] = $state;
        }

        return array_values($unique);
    }

    private function headingSwitchBranchHasUnconditionalBreak(DirectiveNode $branch): bool
    {
        foreach ($branch->children() as $child) {
            if ($child instanceof DirectiveNode && strtolower($child->nameText()) === 'break') {
                $arguments = trim($child->arguments() ?? '');
                if ($arguments === '' || (ctype_digit($arguments) && (int) $arguments > 0)) {
                    return true;
                }
            }
        }

        return false;
    }
}
