<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Security;

use Forte\Ast\Document\Document;
use Forte\Ast\EchoNode;
use Forte\Ast\Elements\ElementNode;
use Forte\Parser\NodeKind;
use Forte\Sheath\Parsing\ComponentAttributeBagExpression;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoRawEchoRule extends AbstractRule
{
    protected array $options = [
        'allowed' => [],
    ];

    public function getId(): string
    {
        return 'security-no-raw-echo';
    }

    public function getDescription(): string
    {
        return 'Avoid using raw echo {!! !!} which can introduce XSS vulnerabilities.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::SECURITY;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        if (! str_contains($document->source(), '{!!')) {
            return;
        }

        $allowedOption = $this->getOption('allowed', []);
        /** @var array<string> $allowed */
        $allowed = is_array($allowedOption) ? $allowedOption : [];

        $document->allEchoes(true)->each(function (EchoNode $echo) use ($allowed, $context): void {
            if ($echo->kind() !== NodeKind::RawEcho) {
                return;
            }

            if ($this->isEscapedComponentAttributeBag($echo)) {
                return;
            }

            if ($this->isAllowed($echo->expression(), $allowed)) {
                return;
            }

            $context->report(
                $echo,
                'Raw Blade echo may expose unescaped content.'
            );
        });
    }

    /** @param array<string> $patterns */
    private function isAllowed(string $expression, array $patterns): bool
    {
        $expression = trim($expression);

        foreach ($patterns as $pattern) {
            $pattern = trim($pattern);
            if ($expression === $pattern) {
                return true;
            }

            if (str_contains($pattern, '*')) {
                $regex = '/\A'.str_replace('\*', '.*', preg_quote($pattern, '/')).'\z/s';
                if (preg_match($regex, $expression) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isEscapedComponentAttributeBag(EchoNode $echo): bool
    {
        if (! $echo->getParent() instanceof ElementNode) {
            return false;
        }

        return ComponentAttributeBagExpression::isEscapedAttributeBagChain($echo->expression());
    }
}
