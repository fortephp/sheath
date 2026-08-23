<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Directives;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class ForelseHasEmptyRule extends AbstractRule
{
    public function getId(): string
    {
        return 'blade-forelse-has-empty';
    }

    public function getDescription(): string
    {
        return '@forelse requires an @empty clause.';
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
            ->filter(fn (DirectiveBlockNode $block) => $block->isForelse())
            ->each(function (DirectiveBlockNode $forelse) use ($context): void {
                if ($this->hasEmptyClause($forelse)) {
                    return;
                }

                $context->report(
                    $forelse,
                    '@forelse is missing an @empty clause.'
                );
            });
    }

    private function hasEmptyClause(DirectiveBlockNode $forelse): bool
    {
        foreach ($forelse->children() as $child) {
            if ($child instanceof DirectiveNode && $child->nameText() === 'empty') {
                return true;
            }
        }

        return false;
    }
}
