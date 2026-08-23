<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Directives;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class ForelseEmptyArgumentsRule extends AbstractRule
{
    public function getId(): string
    {
        return 'blade-forelse-empty-arguments';
    }

    public function getDescription(): string
    {
        return '@empty branches inside @forelse cannot have arguments.';
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
                foreach ($forelse->children() as $child) {
                    if (! $child instanceof DirectiveNode) {
                        continue;
                    }

                    if (strtolower($child->nameText()) !== 'empty' || ! $child->isIntermediate()) {
                        continue;
                    }

                    if (! $child->hasArguments()) {
                        continue;
                    }

                    $arguments = $child->arguments() ?? '';

                    $context->report(
                        $child,
                        "@empty{$arguments} does not close this @forelse loop.",
                        $this->createBareEmptyFix($child)
                    );
                }
            });
    }

    private function createBareEmptyFix(DirectiveNode $directive): ?Fix
    {
        if ($directive->startOffset() < 0) {
            return null;
        }

        return Fix::dangerousFromNode($directive, '@empty');
    }
}
