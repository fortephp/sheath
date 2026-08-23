<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Echoes;

use Forte\Ast\Document\Document;
use Forte\Ast\EchoNode;
use Forte\Parser\NodeKind;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class ValidEchoExpressionRule extends AbstractRule
{
    public function getId(): string
    {
        return 'blade-valid-echo-expression';
    }

    public function getDescription(): string
    {
        return 'Echo contents must be a parseable PHP expression without the echo terminator embedded in it.';
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
        $source = $document->source();
        if (! str_contains($source, '{{') && ! str_contains($source, '{!!')) {
            return;
        }

        $document->allEchoes(true)->each(function (EchoNode $echo) use ($context): void {
            $this->checkEcho($echo, $context);
        });
    }

    private function checkEcho(EchoNode $echo, RuleContext $context): void
    {
        $close = $this->closingDelimiter($echo);

        $content = $echo->content();

        if (trim($content) === '') {
            $context->report(
                $echo,
                'Empty echo.'
            );

            return;
        }

        if (str_contains($content, $close)) {
            $context->report(
                $echo,
                "Echo ends at the first `{$close}`, before the expression is complete."
            );

            return;
        }

        // Laravel's echo handler intentionally strips one trailing semicolon.
        // Validate the expression Blade will actually place in compiled PHP.
        $compiledContent = preg_replace('/;\s*\z/', '', $content, 1) ?? $content;
        $parseError = PhpSource::parseError('<?php ('.$compiledContent.');');

        if ($parseError !== null) {
            $context->report(
                $echo,
                rtrim($parseError, '.').'.'
            );
        }
    }

    private function closingDelimiter(EchoNode $echo): string
    {
        return match ($echo->kind()) {
            NodeKind::RawEcho => '!!}',
            NodeKind::TripleEcho => '}}}',
            default => '}}',
        };
    }
}
