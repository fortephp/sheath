<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Reactive;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Ast\TextNode;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\AlpineForExpression;
use Forte\Sheath\Support\ReactiveAttributeSemantics;

/** @internal */
final class ReactiveTemplateStructureRule extends AbstractReactiveRule
{
    private const STRUCTURAL_DIRECTIVES = ['x-if', 'x-for', 'x-teleport'];

    /** @var array<string, true> */
    private array $reportedAttributes = [];

    public function getId(): string
    {
        return 'blade-reactive-template-structure';
    }

    public function getDescription(): string
    {
        return 'Alpine and Livewire template directives require valid expressions and a single rendered root.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $this->reportedAttributes = [];
        $livewireInstalled = ReactiveAttributeSemantics::livewireCompilerIsInstalled();

        foreach ($this->clientElementsWithDirectives($context) as $element) {
            $isTemplate = $element->isTag('template');
            $paths = $this->explicitAttributeRenderPaths($element);
            if ($paths === null) {
                continue;
            }

            $hasStructuralDirective = false;
            $hasIfDirective = false;

            foreach ($paths as $path) {
                $path = $this->activeClientPath($path, $element, $livewireInstalled);
                $structuralAttributes = [];

                foreach ($path as $attribute) {
                    $name = ReactiveAttributeSemantics::directiveName($attribute);
                    $isStructural = in_array($name, self::STRUCTURAL_DIRECTIVES, true);
                    $isSimpleTemplateDirective = $isTemplate && ! $attribute->hasComplexValue();
                    $expression = $attribute->decodedValueText() ?? '';

                    if ($isStructural) {
                        $structuralAttributes[] = $attribute;
                        $hasStructuralDirective = true;
                        $hasIfDirective = $hasIfDirective || $name === 'x-if';
                    }

                    if ($isStructural && ! $isTemplate) {
                        $this->reportAttribute(
                            $document,
                            $attribute,
                            $context,
                            "{$name} requires a <template> element, not <{$element->tagNameText()}>.",
                        );
                    }

                    if ($isSimpleTemplateDirective && $name === 'x-for') {
                        if (! AlpineForExpression::isStructurallyValid($expression)) {
                            $this->reportAttribute(
                                $document,
                                $attribute,
                                $context,
                                'x-for requires an alias followed by in or of and a collection.',
                            );
                        }

                        if (AlpineForExpression::collectionExpression($expression) === '') {
                            $this->reportAttribute(
                                $document,
                                $attribute,
                                $context,
                                'x-for requires a non-empty collection expression.',
                            );
                        }

                        if (AlpineForExpression::collectionIsDefinitelyEmpty($expression)) {
                            $this->reportAttribute(
                                $document,
                                $attribute,
                                $context,
                                'x-for collection is statically empty.',
                            );
                        }

                        if (AlpineForExpression::collectionIsNonFiniteNumeric($expression)) {
                            $this->reportAttribute(
                                $document,
                                $attribute,
                                $context,
                                'x-for numeric collection is not finite.',
                            );
                        }
                    }

                    if ($isSimpleTemplateDirective && $name === 'x-if' && trim($expression) === '') {
                        $this->reportAttribute(
                            $document,
                            $attribute,
                            $context,
                            'x-if requires a condition.',
                        );
                    }

                    if ($isSimpleTemplateDirective && $name === 'x-teleport') {
                        $teleportSelector = trim($expression);

                        if ($teleportSelector === '') {
                            $this->reportAttribute(
                                $document,
                                $attribute,
                                $context,
                                'x-teleport requires a non-empty CSS selector for its destination.',
                            );
                        }

                        if ($this->isQuotedSelector($teleportSelector)) {
                            $this->reportAttribute(
                                $document,
                                $attribute,
                                $context,
                                'x-teleport requires an unquoted CSS selector.',
                            );
                        }
                    }

                    if ($isTemplate && in_array($name, ['x-show', 'wire:show'], true)) {
                        $this->reportAttribute(
                            $document,
                            $attribute,
                            $context,
                            "{$name} controls the inert <template>, not its rendered content.",
                        );
                    }
                }

                if (count($structuralAttributes) > 1) {
                    $this->reportAttribute(
                        $document,
                        $structuralAttributes[1],
                        $context,
                        'Only one of x-if, x-for, or x-teleport may appear on a <template>.',
                    );
                }
            }

            if (! $isTemplate || ! $hasStructuralDirective) {
                continue;
            }

            $outcomes = $this->rootOutcomes($element->children());
            if ($outcomes !== null && array_filter(
                $outcomes,
                static fn (array $outcome): bool => $outcome['roots'] !== 1 || $outcome['ignored'],
            ) !== []) {
                $context->report(
                    $element,
                    'Alpine template directives require exactly one rendered root on every Blade path.',
                );
            }

            if ($hasIfDirective) {
                $this->reportUnsupportedIfTransitions($element, $document, $context);
            }
        }

        if (! ReactiveAttributeSemantics::livewireCompilerIsInstalled()) {
            return;
        }

        $document->allOfType(DirectiveBlockNode::class)->each(
            function (DirectiveBlockNode $block) use ($context): void {
                if (! $block->isDirectiveNamed('teleport')) {
                    return;
                }

                $start = $block->startDirective();
                $outcomes = $start === null ? null : $this->rootOutcomes($start->children());
                if ($outcomes !== null && array_filter(
                    $outcomes,
                    static fn (array $outcome): bool => $outcome['roots'] !== 1 || $outcome['ignored'],
                ) !== []) {
                    $context->report(
                        $start ?? $block,
                        'Livewire @teleport requires exactly one rendered root on every Blade path.',
                    );
                }
            },
        );
    }

    private function isQuotedSelector(string $selector): bool
    {
        if (strlen($selector) < 2) {
            return false;
        }

        $quote = $selector[0];

        return in_array($quote, ["'", '"', '`'], true)
            && $selector[strlen($selector) - 1] === $quote;
    }

    private function reportUnsupportedIfTransitions(
        ElementNode $template,
        Document $document,
        RuleContext $context,
    ): void {
        foreach ($template->descendants() as $descendant) {
            if (! $descendant instanceof ElementNode) {
                continue;
            }

            foreach ($descendant->attributes() as $attribute) {
                if ($attribute->isBladeConstruct()
                    || ! str_starts_with(strtolower($attribute->name()->rawName()), 'x-transition')
                    || ! ReactiveAttributeSemantics::clientDirectiveRuns($attribute, $descendant)) {
                    continue;
                }

                $this->reportAttribute(
                    $document,
                    $attribute,
                    $context,
                    'x-if cannot be combined with x-transition.',
                );

                return;
            }
        }
    }

    protected function reportAttribute(
        Document $document,
        Attribute $attribute,
        RuleContext $context,
        string $message,
    ): void {
        $key = $attribute->startOffset().'|'.$message;
        if (isset($this->reportedAttributes[$key])) {
            return;
        }
        $this->reportedAttributes[$key] = true;

        $context->reportAt(
            Position::fromOffset($document, $attribute->startOffset()),
            Position::fromOffset($document, $attribute->endOffset()),
            $message,
        );
    }

    /**
     * @param  iterable<mixed>  $nodes
     * @return list<array{roots: int, ignored: bool}>|null
     */
    private function rootOutcomes(iterable $nodes): ?array
    {
        $states = [['roots' => 0, 'ignored' => false]];

        foreach ($nodes as $node) {
            $outcomes = $this->nodeRootOutcomes($node);
            if ($outcomes === null) {
                return null;
            }

            $next = [];
            foreach ($states as $state) {
                foreach ($outcomes as $outcome) {
                    $next[] = [
                        'roots' => min(2, $state['roots'] + $outcome['roots']),
                        'ignored' => $state['ignored'] || $outcome['ignored'],
                    ];
                }
            }

            $states = $this->uniqueRootOutcomes($next);
        }

        return $states;
    }

    /** @return list<array{roots: int, ignored: bool}>|null */
    private function nodeRootOutcomes(mixed $node): ?array
    {
        if ($node instanceof Node && $node->isComment()) {
            return [['roots' => 0, 'ignored' => false]];
        }

        if ($node instanceof ElementNode) {
            return [['roots' => 1, 'ignored' => false]];
        }

        if ($node instanceof TextNode) {
            return [[
                'roots' => 0,
                'ignored' => trim($node->getSemanticContent()) !== '',
            ]];
        }

        if ($node instanceof DirectiveBlockNode) {
            return $this->directiveBlockRootOutcomes($node);
        }

        if ($node instanceof DirectiveNode) {
            return in_array(strtolower($node->nameText()), ['break', 'continue'], true)
                ? [['roots' => 0, 'ignored' => false]]
                : null;
        }

        return null;
    }

    /** @return list<array{roots: int, ignored: bool}>|null */
    private function directiveBlockRootOutcomes(DirectiveBlockNode $block): ?array
    {
        $start = $block->startDirective();
        if ($start === null) {
            return null;
        }

        $name = strtolower($block->nameText());
        if (in_array($name, ['push', 'pushif', 'pushonce', 'prepend', 'prependonce', 'section'], true)) {
            return [['roots' => 0, 'ignored' => false]];
        }

        if (in_array($name, ['foreach', 'forelse', 'for', 'while'], true)) {
            $body = $this->rootOutcomes($start->children());
            if ($body === null) {
                return null;
            }

            $outcomes = [['roots' => 0, 'ignored' => false], ...$body];
            foreach ($body as $outcome) {
                $outcomes[] = [
                    'roots' => min(2, $outcome['roots'] * 2),
                    'ignored' => $outcome['ignored'],
                ];
            }

            if ($name === 'forelse') {
                foreach ($block->intermediateDirectives() as $branch) {
                    if ($branch->isDirectiveNamed('empty')) {
                        $empty = $this->rootOutcomes($branch->children());
                        if ($empty === null) {
                            return null;
                        }
                        array_push($outcomes, ...$empty);
                    }
                }
            }

            return $this->uniqueRootOutcomes($outcomes);
        }

        $outcomes = [];
        $hasFallback = false;
        foreach ([$start, ...iterator_to_array($block->intermediateDirectives())] as $branch) {
            $hasFallback = $hasFallback || in_array(strtolower($branch->nameText()), ['else', 'empty', 'default'], true);
            $branchOutcomes = $this->rootOutcomes($branch->children());
            if ($branchOutcomes === null) {
                return null;
            }
            array_push($outcomes, ...$branchOutcomes);
        }

        if (! $hasFallback) {
            $outcomes[] = ['roots' => 0, 'ignored' => false];
        }

        return $this->uniqueRootOutcomes($outcomes);
    }

    /**
     * @param  list<array{roots: int, ignored: bool}>  $outcomes
     * @return list<array{roots: int, ignored: bool}>
     */
    private function uniqueRootOutcomes(array $outcomes): array
    {
        $unique = [];

        foreach ($outcomes as $outcome) {
            $unique[$outcome['roots'].'|'.($outcome['ignored'] ? '1' : '0')] = $outcome;
        }

        return array_values($unique);
    }
}
