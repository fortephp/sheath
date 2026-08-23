<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Echoes;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Parser\NodeKind;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Support\AttributeQuoting;

/** @internal */
class NoUnquotedEchoAttributeRule extends AbstractRule
{
    public function getId(): string
    {
        return 'blade-no-unquoted-echo-attribute';
    }

    public function getDescription(): string
    {
        return 'Echoed HTML attribute values must be quoted.';
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
        $checkElement = function (ElementNode $element) use ($document, $context): void {
            foreach ($element->attributes() as $attribute) {
                $this->checkAttribute($attribute, $element, $document, $context);
            }
        };

        $context->elements()->each($checkElement);
        $document->queryComponents()->each($checkElement);
    }

    private function checkAttribute(Attribute $attribute, ElementNode $element, Document $document, RuleContext $context): void
    {

        if ($attribute->isBladeConstruct() || $attribute->isBoolean()) {
            return;
        }

        if ($attribute->isBound() && $element->isComponent()) {
            return;
        }

        if ($attribute->quote() !== null || $attribute->valueText() === null) {
            return;
        }

        $echo = $attribute->firstRenderedEcho();

        if ($echo === null) {
            return;
        }

        $raw = $document->getText($attribute->startOffset(), $attribute->endOffset());

        $context->report(
            $echo,
            "Unquoted echoed attribute value: {$raw}.",
            $echo->kind() === NodeKind::RawEcho
                ? null
                : $this->createQuoteValueFix($attribute, $document)
        );
    }

    private function createQuoteValueFix(Attribute $attribute, Document $document): ?Fix
    {
        $start = $attribute->startOffset();
        $end = $attribute->endOffset();

        if ($start < 0 || $end <= $start) {
            return null;
        }

        $raw = $document->getText($start, $end);
        $equalsAt = strpos($raw, '=');

        if ($equalsAt === false) {
            return null;
        }

        $valueStart = $start + $equalsAt + 1;
        $value = $document->getText($valueStart, $end);

        if ($value === '') {
            return null;
        }

        $quote = AttributeQuoting::preferredQuote($value);

        if ($quote === null) {
            return null;
        }

        return new Fix($valueStart, $end, $quote.$value.$quote);
    }
}
