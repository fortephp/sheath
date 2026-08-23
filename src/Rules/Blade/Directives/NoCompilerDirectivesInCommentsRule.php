<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Directives;

use Forte\Ast\BladeCommentNode;
use Forte\Ast\Document\Document;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoCompilerDirectivesInCommentsRule extends AbstractRule
{
    private const RAW_BLOCK_NAMES = 'php|endphp|verbatim|endverbatim';

    public function getId(): string
    {
        return 'blade-no-compiler-directives-in-comments';
    }

    public function getDescription(): string
    {
        return 'Blade comments cannot contain raw block directives.';
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
            ->getBladeComments()
            ->each(function (BladeCommentNode $comment) use ($context): void {
                if (preg_match_all('/(?<!@)@('.self::RAW_BLOCK_NAMES.')\b/', $comment->content(), $matches) === 0) {
                    return;
                }

                foreach (array_unique($matches[1]) as $name) {
                    $context->report(
                        $comment,
                        "Blade comment contains raw block directive @{$name}."
                    );
                }
            });
    }
}
