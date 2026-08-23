<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Directives;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Ast\TextNode;
use Forte\Parser\Directives\Directives;
use Forte\Sheath\Contracts\SharesRuleState;
use Forte\Sheath\Parsing\ExpressionScanner;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsDirectiveAttributeCollisions;
use Forte\Sheath\Rules\Concerns\DetectsExclusiveBranches;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class UnclosedDirectivesRule extends AbstractRule implements SharesRuleState
{
    use DetectsDirectiveAttributeCollisions;
    use DetectsExclusiveBranches;

    protected array $options = [
        'directives' => null,
    ];

    protected array $optionRules = [
        'directives' => 'nullable-string-list',
    ];

    /**
     * @var array<string>
     */
    private const RAW_BLOCK_DIRECTIVES = ['php', 'verbatim'];

    /**
     * @var array<string>
     */
    private const SECTION_TERMINATORS = ['endsection', 'stop', 'show', 'append', 'overwrite'];

    public function __construct(
        private readonly ?Directives $directives = null,
    ) {
        parent::__construct();
    }

    public function getId(): string
    {
        return 'blade-unclosed-directives';
    }

    public function getDescription(): string
    {
        return 'Block directives require matching closing directives.';
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
        $directivesService = $this->getDirectivesService();
        $blockDirectives = $this->getBlockDirectiveNames($directivesService);
        $rawBlockDirectives = self::RAW_BLOCK_DIRECTIVES;

        $directiveOption = $this->getOption('directives');
        $orphanIntermediateNames = array_keys(array_filter(
            $directivesService->getDirectiveMetadata(),
            static fn (array $metadata): bool => in_array($metadata['role'], ['mixed', 'intermediate'], true)
                && ($metadata['name'] === 'else' || str_starts_with($metadata['name'], 'else')),
        ));

        if (is_array($directiveOption)) {
            /** @var array<string> $directiveOption */
            $blockDirectives = array_map(strtolower(...), array_filter($directiveOption, is_string(...)));
            $rawBlockDirectives = array_values(array_intersect(self::RAW_BLOCK_DIRECTIVES, $blockDirectives));
            $orphanIntermediateNames = array_values(array_intersect($orphanIntermediateNames, $blockDirectives));
        }

        /** @var array<int, true> $pairedDirectiveIndexes */
        $pairedDirectiveIndexes = [];
        /** @var list<DirectiveBlockNode> $blocks */
        $blocks = [];
        /** @var list<DirectiveNode> $directives */
        $directives = [];
        /** @var list<TextNode> $textNodes */
        $textNodes = array_values(iterator_to_array($document->allOfType(TextNode::class)));
        /** @var list<ElementNode> $elements */
        $elements = [];

        foreach ($document->allOfType(Node::class, true) as $node) {
            if ($node instanceof DirectiveBlockNode) {
                $blocks[] = $node;
            } elseif ($node instanceof DirectiveNode) {
                $directives[] = $node;
            }

            if ($node instanceof ElementNode) {
                $elements[] = $node;
            }
        }

        $attributeCollisionIndexes = $this->directiveAttributeCollisionIndexesForElements($document, $elements);

        foreach ($blocks as $block) {
            $name = strtolower($block->nameText());

            foreach ([$block->startDirective(), ...iterator_to_array($block->intermediateDirectives()), $block->endDirective()] as $member) {
                if ($member !== null) {
                    $pairedDirectiveIndexes[$member->index()] = true;
                }
            }

            if (! in_array($name, $blockDirectives, true)) {
                return;
            }

            if ($block->endDirective() === null) {
                $startDirective = $block->startDirective();
                if ($startDirective !== null && isset($attributeCollisionIndexes[$startDirective->index()])) {
                    return;
                }

                $expectedEnd = $directivesService->getTerminator($name);

                $context->report(
                    $startDirective ?? $block,
                    "@{$name} is missing @{$expectedEnd}."
                );
            }
        }

        foreach ($directives as $directive) {
            if (isset($pairedDirectiveIndexes[$directive->index()])
                || isset($attributeCollisionIndexes[$directive->index()])) {
                continue;
            }

            $name = strtolower($directive->nameText());

            if (in_array($name, $orphanIntermediateNames, true)) {
                $context->report(
                    $directive,
                    "@{$name} has no matching block."
                );

                continue;
            }

            if (! str_starts_with($name, 'end')) {
                continue;
            }

            $openingName = substr($name, 3);

            if (in_array($openingName, $rawBlockDirectives, true)) {
                $context->report(
                    $directive,
                    "@{$name} has no matching @{$openingName}."
                );

                continue;
            }

            if (in_array($openingName, $blockDirectives, true)) {
                $context->report(
                    $directive,
                    "@{$name} has no matching @{$openingName}."
                );
            }
        }

        if (in_array('verbatim', $rawBlockDirectives, true)) {
            $this->checkStrayEndverbatim($document, $context, $textNodes);
        }

        if (in_array('section', $blockDirectives, true)) {
            $this->checkOverlappingSections($context, $directives);
        }
    }

    /** @param list<TextNode> $textNodes */
    private function checkStrayEndverbatim(Document $document, RuleContext $context, array $textNodes): void
    {
        $source = $document->source();

        foreach ($textNodes as $text) {
            $start = $text->startOffset();

            if ($start < 0) {
                continue;
            }

            if (preg_match_all('/@endverbatim\b/', $text->getContent(), $matches, PREG_OFFSET_CAPTURE) === 0) {
                continue;
            }

            foreach ($matches[0] as $match) {
                $offset = $start + $match[1];
                $before = $offset > 0 ? $source[$offset - 1] : '';

                if ($before === '@' || preg_match('/\w/', $before) === 1) {
                    continue;
                }

                $this->reportSpan(
                    $document,
                    $context,
                    $offset,
                    $offset + strlen($match[0][0]),
                    '@endverbatim has no matching @verbatim.'
                );
            }
        }
    }

    /** @param list<DirectiveNode> $directives */
    private function checkOverlappingSections(RuleContext $context, array $directives): void
    {
        $sectionFlow = [];

        foreach ($directives as $directive) {
            $name = strtolower($directive->nameText());

            if ($name === 'section' || in_array($name, self::SECTION_TERMINATORS, true)) {
                $sectionFlow[] = $directive;
            }
        }

        usort($sectionFlow, static fn (DirectiveNode $a, DirectiveNode $b): int => $a->startOffset() <=> $b->startOffset());

        /** @var array<DirectiveNode> $openStack */
        $openStack = [];
        $openers = [];

        foreach ($sectionFlow as $directive) {
            $name = strtolower($directive->nameText());

            if ($name !== 'section') {
                if ($openStack === [] && $name !== 'endsection') {
                    $context->report(
                        $directive,
                        "@{$name} has no matching @section."
                    );

                    continue;
                }

                array_pop($openStack);

                continue;
            }

            if (! $directive->hasArguments()
                || ExpressionScanner::hasCommaAtDepth($directive->arguments() ?? '', 1)) {
                continue;
            }

            $openStack[] = $directive;
            $openers[] = $directive;
        }

        foreach ($openStack as $unclosed) {
            $next = $this->nextSectionOpenerAfter($openers, $unclosed);

            if ($next === null || $this->nodesAreMutuallyExclusive($unclosed, $next)) {
                continue;
            }

            $unclosedArgs = $unclosed->arguments() ?? '';
            $nextArgs = $next->arguments() ?? '';

            $context->report(
                $next,
                "@section{$nextArgs} opens before @section{$unclosedArgs} is closed."
            );
        }
    }

    /**
     * @param  array<DirectiveNode>  $openers
     */
    private function nextSectionOpenerAfter(array $openers, DirectiveNode $directive): ?DirectiveNode
    {
        foreach ($openers as $candidate) {
            if ($candidate->startOffset() > $directive->startOffset()) {
                return $candidate;
            }
        }

        return null;
    }

    private function reportSpan(Document $document, RuleContext $context, int $start, int $end, string $message): void
    {
        $context->reportAt(
            Position::fromOffset($document, $start),
            Position::fromOffset($document, $end),
            $message
        );
    }

    private function getDirectivesService(): Directives
    {
        return $this->directives ?? Directives::withDefaults();
    }

    /**
     * @return array<string>
     */
    private function getBlockDirectiveNames(Directives $directives): array
    {
        $names = [];

        foreach ($directives->getDirectiveMetadata() as $name => $meta) {
            if ($meta['role'] === 'open' || $meta['role'] === 'mixed') {
                $names[] = $name;
            }
        }

        return $names;
    }
}
