<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\BestPractices\Markup;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ReportsWithFix;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class SelfClosingVoidElementsRule extends AbstractRule
{
    use ReportsWithFix;

    public const STYLE_NEVER = 'never';

    public const STYLE_ALWAYS = 'always';

    protected array $options = [
        'style' => self::STYLE_NEVER,
    ];

    protected array $optionRules = [
        'style' => 'one-of:never,always',
    ];

    public function getId(): string
    {
        return 'best-practices-self-closing-void-elements';
    }

    public function getDescription(): string
    {
        return 'Void elements should be written consistently, with or without a trailing slash.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BEST_PRACTICES;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $style = $this->resolveStyle();

        $document->walk(function (Node $node) use ($context, $style): void {

            if (! $node instanceof ElementNode || ! $node->isVoid()) {
                return;
            }

            $tagName = strtolower($node->tagNameText());

            if ($style === self::STYLE_ALWAYS && ! $node->isSelfClosing()) {
                $context->report(
                    $node,
                    sprintf('Void element <%s> is missing a trailing slash.', $tagName),
                    $this->createSelfClosingFix($node)
                );

                return;
            }

            if ($style === self::STYLE_NEVER && $node->isSelfClosing()) {
                $context->report(
                    $node,
                    sprintf('Void element <%s> has an unwanted trailing slash.', $tagName),
                    $this->createRemoveSelfClosingFix($node)
                );
            }
        });
    }

    private function resolveStyle(): string
    {
        $style = $this->getOption('style', self::STYLE_NEVER);

        return $style === self::STYLE_ALWAYS ? self::STYLE_ALWAYS : self::STYLE_NEVER;
    }

    private function createRemoveSelfClosingFix(ElementNode $element): ?Fix
    {
        $gtOffset = $this->openingTagEndOffset($element);
        $insertOffset = $this->attributeInsertOffset($element);

        if ($gtOffset === null || $insertOffset === null || $insertOffset >= $gtOffset) {
            return null;
        }

        return new Fix($insertOffset, $gtOffset, '');
    }
}
