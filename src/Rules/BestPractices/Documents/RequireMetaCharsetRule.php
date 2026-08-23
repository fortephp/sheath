<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\BestPractices\Documents;

use Forte\Ast\BladeCommentNode;
use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Ast\PhpBlockNode;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ChecksRenderPathGuarantees;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueHeadContent;
use Forte\Sheath\Rules\Concerns\RecognizesCharsetDeclarations;
use Forte\Sheath\Rules\Concerns\ValidatesDocumentStructure;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class RequireMetaCharsetRule extends AbstractRule
{
    use ChecksRenderPathGuarantees;
    use DetectsOpaqueHeadContent;
    use RecognizesCharsetDeclarations;
    use ValidatesDocumentStructure;

    /** @var list<array{int, int}> */
    private array $omittedRanges = [];

    public function getId(): string
    {
        return 'best-practices-require-meta-charset';
    }

    public function getDescription(): string
    {
        return 'HTML documents should have a charset meta tag.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BEST_PRACTICES;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $this->omittedRanges = $this->omittedRanges($document);

        if (! $this->hasHeadElement($document)) {
            return;
        }

        $headElement = $this->getHeadElement($document);

        if ($headElement === null) {
            return;
        }

        $hasCharset = $this->everyRenderPathContains(
            $headElement->children(),
            fn ($node): bool => $node instanceof ElementNode
                && $node->isTag('meta')
                && $this->charsetDeclarationStatus($node) !== false
                && $this->serializedEndOffset($node) <= 1024,
            static fn (Node $node): bool => ! $node instanceof ElementNode
                || ! $node->isTag('template'),
        );
        $lateCharset = null;
        $metaElements = $this->getHeadElementsByTagName($document, 'meta');

        foreach ($metaElements as $meta) {
            if ($this->charsetDeclarationStatus($meta) === false) {
                continue;
            }

            if ($this->serializedEndOffset($meta) > 1024) {
                $lateCharset ??= $meta;
            }
        }

        if (! $hasCharset && $lateCharset !== null) {
            $context->report(
                $lateCharset,
                'Character encoding declaration ends after the first 1024 bytes.'
            );

            return;
        }

        if (! $hasCharset && ! $this->headContainsOpaqueContent($headElement)) {
            $context->report(
                $headElement,
                'HTML document is missing a character encoding declaration.'
            );
        }
    }

    private function serializedEndOffset(ElementNode $element): int
    {
        $offset = $element->endOffset();

        foreach ($this->omittedRanges as [$start, $end]) {
            if ($start >= $element->endOffset()) {
                break;
            }

            $offset -= min($end, $element->endOffset()) - $start;
        }

        return $offset;
    }

    /** @return list<array{int, int}> */
    private function omittedRanges(Document $document): array
    {
        $ranges = [];

        foreach ($document->findAll(static fn (Node $node): bool => $node instanceof BladeCommentNode
            || $node instanceof PhpBlockNode
            || $node instanceof DirectiveBlockNode
            || $node instanceof DirectiveNode) as $node) {
            if ($node instanceof PhpBlockNode && PhpSource::mayProduceOutput($node->code())) {
                continue;
            }

            if ($node instanceof DirectiveBlockNode && ! $this->isNonOutputCaptureBlock($node)) {
                continue;
            }

            if ($node instanceof DirectiveNode && ! $this->directiveProducesNoOutput($node)) {
                continue;
            }

            $ranges[] = [$node->startOffset(), $node->endOffset()];
        }

        usort($ranges, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

        $merged = [];
        foreach ($ranges as $range) {
            if ($merged === []) {
                $merged[] = $range;

                continue;
            }

            $previous = array_pop($merged);
            if ($range[0] <= $previous[1]) {
                $merged[] = [$previous[0], max($previous[1], $range[1])];

                continue;
            }

            $merged[] = $previous;
            $merged[] = $range;
        }

        return $merged;
    }

    private function directiveProducesNoOutput(DirectiveNode $directive): bool
    {
        if ($directive->isOpening() || $directive->isIntermediate() || $directive->isClosing()) {
            return true;
        }

        return $directive->isAnyDirectiveNamed([
            'break',
            'continue',
            'inject',
            'php',
            'props',
            'aware',
            'unset',
            'use',
        ]);
    }
}
