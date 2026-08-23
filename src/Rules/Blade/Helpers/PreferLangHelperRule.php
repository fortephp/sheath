<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Helpers;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class PreferLangHelperRule extends AbstractRule
{
    public function getId(): string
    {
        return 'blade-prefer-lang-helper';
    }

    public function getDescription(): string
    {
        return 'Prefer {{ __() }} over the @lang directive.';
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
        $document->allOfType(DirectiveNode::class, true)->each(function (DirectiveNode $node) use ($context): void {
            if (! $node->isDirectiveNamed('lang')) {
                return;
            }

            if ($node->getParent() instanceof DirectiveBlockNode) {
                return;
            }

            $arguments = $node->arguments();

            if ($arguments === null) {
                return;
            }

            $inner = PhpSource::innerArguments($arguments);

            if ($inner === null || $this->opensTranslationBlock($arguments, $inner)) {
                return;
            }

            if (str_contains($inner, '}}')) {
                return;
            }

            $context->report(
                $node,
                '@lang output is unescaped.',
                Fix::dangerous($node->startOffset(), $node->endOffset(), '{{ __('.$inner.') }}')
            );
        });
    }

    private function opensTranslationBlock(string $arguments, string $inner): bool
    {
        return str_starts_with($inner, '[')
            || str_starts_with(ltrim($arguments), '([');
    }
}
