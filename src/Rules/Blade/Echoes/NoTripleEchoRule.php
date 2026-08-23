<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Echoes;

use Forte\Ast\Document\Document;
use Forte\Ast\EchoNode;
use Forte\Parser\NodeKind;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoTripleEchoRule extends AbstractRule
{
    public function getId(): string
    {
        return 'blade-no-triple-echo';
    }

    public function getDescription(): string
    {
        return 'Disallow legacy triple-brace echoes.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {

        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        if (! str_contains($document->source(), '{{{')) {
            return;
        }

        $document->allEchoes(true)->each(function (EchoNode $echo) use ($context): void {
            if ($echo->kind() !== NodeKind::TripleEcho) {
                return;
            }

            $context->report(
                $echo,
                'Legacy triple-brace echo syntax found.',
                $this->createConvertToDoubleEchoFix($echo)
            );
        });
    }

    private function createConvertToDoubleEchoFix(EchoNode $echo): ?Fix
    {
        $startOffset = $echo->startOffset();
        $endOffset = $echo->endOffset();

        if ($startOffset < 0) {
            return null;
        }

        $content = trim($echo->content());

        // Triple echoes and regular echoes do not have the same closing
        // delimiter. Converting an expression which itself contains `}}`
        // would make Blade stop the replacement early and can turn valid PHP
        // into a broken compiled view.
        if (str_contains($content, '}}')) {
            return null;
        }

        $replacement = '{{ '.$content.' }}';

        return new Fix($startOffset, $endOffset, $replacement);
    }
}
