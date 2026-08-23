<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Directives;

use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Sheath\Parsing\ExpressionScanner;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoElseConditionRule extends AbstractRule
{
    public function getId(): string
    {
        return 'blade-no-else-condition';
    }

    public function getDescription(): string
    {
        return '@else does not accept a condition.';
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
        if (stripos($document->source(), '@else') === false) {
            return;
        }

        $document->allOfType(DirectiveNode::class, true)->each(function (DirectiveNode $directive) use ($document, $context): void {
            if (strtolower($directive->nameText()) !== 'else') {
                return;
            }

            if ($directive->hasArguments()) {
                $this->reportElseWithArguments($directive, $context);

                return;
            }

            $this->checkElseSpaceIf($directive, $document, $context);
        });
    }

    private function reportElseWithArguments(DirectiveNode $directive, RuleContext $context): void
    {
        $arguments = $directive->arguments() ?? '';

        $context->report(
            $directive,
            "@else{$arguments} is unconditional; the condition is ignored.",
            $this->createElseifFix($directive, '@elseif'.$arguments)
        );
    }

    private function checkElseSpaceIf(DirectiveNode $directive, Document $document, RuleContext $context): void
    {
        $end = $directive->startOffset() + 1 + strlen($directive->nameText());

        if ($directive->startOffset() < 0) {
            return;
        }

        $tail = substr($document->source(), $end);
        if (preg_match('/\A[\x09\x0A\x0C\x0D\x20]+if[\x09\x0A\x0C\x0D\x20]*\(/i', $tail, $matches) !== 1) {
            return;
        }

        $arguments = substr($tail, strlen($matches[0]) - 1);
        $closingOffset = null;

        foreach (ExpressionScanner::topLevel($arguments) as [$character, $offset, $depth]) {
            if ($character === ')' && $depth === 0) {
                $closingOffset = $offset;

                break;
            }
        }

        if ($closingOffset === null) {
            return;
        }

        $arguments = substr($arguments, 0, $closingOffset + 1);
        if (PhpSource::parseError("<?php if{$arguments}: endif;") !== null) {
            return;
        }

        $context->report(
            $directive,
            '@else if(...) renders if(...) as text.'
        );
    }

    private function createElseifFix(DirectiveNode $directive, string $replacement): ?Fix
    {
        if ($directive->startOffset() < 0) {
            return null;
        }

        return Fix::dangerousFromNode($directive, $replacement);
    }
}
