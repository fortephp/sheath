<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Directives;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\Document\Document;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class PreferEndsectionRule extends AbstractRule
{
    private const ALIASES = ['stop'];

    public function getId(): string
    {
        return 'blade-prefer-endsection';
    }

    public function getDescription(): string
    {
        return 'Close a @section with @endsection rather than its @stop alias.';
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
            if (strtolower($block->nameText()) !== 'section') {
                return;
            }

            $end = $block->endDirective();

            if ($end === null) {
                return;
            }

            $terminator = strtolower($end->nameText());

            if (! in_array($terminator, self::ALIASES, true)) {
                return;
            }

            $context->report(
                $end,
                "Close @section with @endsection rather than @{$terminator}.",
                new Fix($end->startOffset(), $end->endOffset(), '@endsection')
            );
        });
    }
}
