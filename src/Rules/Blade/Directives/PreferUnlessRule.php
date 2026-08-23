<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Directives;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\Document\Document;
use Forte\Sheath\Parsing\ExpressionScanner;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class PreferUnlessRule extends AbstractRule
{
    public function getId(): string
    {
        return 'blade-prefer-unless';
    }

    public function getDescription(): string
    {
        return 'Prefer @unless over @if with negation for improved readability.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::INFO;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $document->queryBlockDirectives()->each(function (DirectiveBlockNode $block) use ($context): void {
            if (! $block->isIf()) {
                return;
            }

            $args = $block->arguments();
            if ($args === null) {
                return;
            }

            if ($this->negatesEntireCondition(trim($args))) {
                $startDirective = $block->startDirective();
                $context->report(
                    $startDirective ?? $block,
                    'Negated @if can be expressed with @unless.'
                );
            }
        });
    }

    private function negatesEntireCondition(string $condition): bool
    {
        $condition = ExpressionScanner::stripOuterParentheses($condition);

        if (! str_starts_with($condition, '!')) {
            return false;
        }

        $next = substr($condition, 1, 1);

        if ($next === '=' || $next === '!') {
            return false;
        }

        return ! $this->hasTopLevelCombinator(substr($condition, 1));
    }

    private function hasTopLevelCombinator(string $expression): bool
    {
        foreach (ExpressionScanner::topLevel($expression) as [$char, $index, $depth]) {
            if ($depth > 0) {
                continue;
            }

            if ($char === '&' && ($expression[$index + 1] ?? '') === '&') {
                return true;
            }

            if ($char === '|' && ($expression[$index + 1] ?? '') === '|') {
                return true;
            }

            if ($char === '?') {
                if (substr($expression, $index + 1, 2) === '->') {
                    continue;
                }

                return true;
            }

            if (! in_array(strtolower($char), ['a', 'o', 'x'], true)) {
                continue;
            }

            if (preg_match('/\G(?:and|or|xor)\b/i', $expression, $matches, 0, $index) === 1
                && ($index === 0 || preg_match('/[\w$]/', $expression[$index - 1]) !== 1)) {
                return true;
            }
        }

        return false;
    }
}
