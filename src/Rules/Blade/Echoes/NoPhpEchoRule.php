<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Echoes;

use Forte\Ast\Document\Document;
use Forte\Ast\PhpBlockNode;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoPhpEchoRule extends AbstractRule
{
    public function getId(): string
    {
        return 'blade-no-php-echo';
    }

    public function getDescription(): string
    {
        return 'Prefer Blade echoes over echo-only @php blocks.';
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
        $document->queryPhpBlocks()->each(function (PhpBlockNode $node) use ($context): void {
            $body = $this->blockBody($node);

            if ($body === null) {
                return;
            }

            $expression = $this->soleEchoExpression($body);

            if ($expression === null) {
                return;
            }

            $context->report(
                $node,
                'Echo-only @php block can use Blade echo syntax.',
                new Fix($node->startOffset(), $node->endOffset(), '{!! '.$expression.' !!}')
            );
        });
    }

    private function blockBody(PhpBlockNode $node): ?string
    {
        $raw = $node->getDocumentContent();

        if (preg_match('/^@php\b/i', $raw) !== 1 || preg_match('/@endphp$/i', $raw) !== 1) {
            return null;
        }

        return substr($raw, strlen('@php'), strlen($raw) - strlen('@php') - strlen('@endphp'));
    }

    private function soleEchoExpression(string $body): ?string
    {
        $tokens = PhpSource::tokenize($body);

        if ($tokens === null || $tokens === [] || ! PhpSource::parses($body)) {
            return null;
        }

        foreach ($tokens as $token) {
            if (! is_array($token)) {
                continue;
            }

            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_START_HEREDOC], true)) {
                return null;
            }
        }

        $index = 0;

        while (isset($tokens[$index]) && is_array($tokens[$index]) && $tokens[$index][0] === T_WHITESPACE) {
            $index++;
        }

        $echo = $tokens[$index] ?? null;

        if (! is_array($echo) || $echo[0] !== T_ECHO) {
            return null;
        }

        $expression = '';
        $depth = 0;
        $terminated = false;

        foreach (array_slice($tokens, $index + 1) as $token) {
            if ($terminated) {
                if (! is_array($token) || $token[0] !== T_WHITESPACE) {
                    return null;
                }

                continue;
            }

            $depth += PhpSource::nestingDelta($token);

            if ($depth < 0) {
                return null;
            }

            if ($depth === 0 && $token === ';') {
                $terminated = true;

                continue;
            }

            if ($depth === 0 && $token === ',') {
                return null;
            }

            $expression .= is_array($token) ? $token[1] : $token;
        }

        if ($depth !== 0) {
            return null;
        }

        $expression = trim($expression);

        if ($expression === '' || str_contains($expression, '!!}')) {
            return null;
        }

        return $expression;
    }
}
