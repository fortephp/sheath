<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Directives;

use Forte\Ast\BladeCommentNode;
use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Node;
use Forte\Ast\TextNode;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\TextExcerpt;

/** @internal */
class SwitchStructureRule extends AbstractRule
{
    public function getId(): string
    {
        return 'blade-switch-structure';
    }

    public function getDescription(): string
    {
        return '@switch requires at least one @case before @default.';
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
        $document
            ->queryBlockDirectives()
            ->filter(fn (DirectiveBlockNode $block) => $block->isSwitch())
            ->each(function (DirectiveBlockNode $block) use ($context): void {
                $this->checkSwitchOpening($block, $context);
            });

        $document->queryDirectives()->each(function (DirectiveNode $directive) use ($context): void {
            $this->checkOrphanBranch($directive, $context);
        });
    }

    private function checkSwitchOpening(DirectiveBlockNode $block, RuleContext $context): void
    {
        $start = $block->startDirective();

        if ($start === null) {
            return;
        }

        foreach ($start->children() as $child) {
            if (! $child instanceof Node) {
                continue;
            }

            if ($child instanceof TextNode && $child->isWhitespace()) {
                continue;
            }

            if ($child instanceof BladeCommentNode) {
                continue;
            }

            if ($child instanceof DirectiveNode) {
                $name = strtolower($child->nameText());

                if ($name === 'case') {
                    return;
                }

                if ($name === 'default') {
                    $context->report(
                        $child,
                        '@default appears before any @case branch.'
                    );

                    return;
                }
            }

            $this->reportContentBeforeCase($block, $child, $context);

            return;
        }

        $context->report(
            $start,
            sprintf(
                '@switch%s requires at least one @case branch.',
                $block->arguments() ?? ''
            )
        );
    }

    private function reportContentBeforeCase(DirectiveBlockNode $block, Node $child, RuleContext $context): void
    {
        $message = sprintf(
            "Unexpected content before the first @case in @switch%s: '%s'.",
            $block->arguments() ?? '',
            $this->excerpt($child)
        );

        $start = $child->startOffset();
        $end = $child->endOffset();

        if ($child instanceof TextNode && $start >= 0) {
            $content = $child->getContent();
            $leading = strlen($content) - strlen(ltrim($content));
            $trailing = strlen($content) - strlen(rtrim($content));
            $start += $leading;
            $end -= $trailing;
        }

        if ($start < 0 || $end <= $start) {
            $context->report($child, $message);

            return;
        }

        $document = $child->getDocument();

        $context->reportAt(
            Position::fromOffset($document, $start),
            Position::fromOffset($document, $end),
            $message
        );
    }

    private function checkOrphanBranch(DirectiveNode $directive, RuleContext $context): void
    {
        $name = strtolower($directive->nameText());

        if ($name !== 'case' && $name !== 'default') {
            return;
        }

        if ($this->hasSwitchAncestor($directive)) {
            return;
        }

        if ($name === 'case') {
            $arguments = $directive->arguments() ?? '';

            $context->report(
                $directive,
                "@case{$arguments} is outside @switch."
            );

            return;
        }

        $context->report(
            $directive,
            '@default is outside @switch.'
        );
    }

    private function hasSwitchAncestor(DirectiveNode $directive): bool
    {

        return $directive->hasAncestorWhere(
            static fn (Node $ancestor): bool => ($ancestor instanceof DirectiveNode && strtolower($ancestor->nameText()) === 'switch')
                || ($ancestor instanceof DirectiveBlockNode && $ancestor->isSwitch())
        );
    }

    private function excerpt(Node $node): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $node->getDocumentContent()));

        return TextExcerpt::bytes($text, 40, 37);
    }
}
