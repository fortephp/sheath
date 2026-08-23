<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Directives;

use Forte\Ast\Components\ComponentNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsDirectiveAttributeCollisions;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoDirectiveAttributeCollisionRule extends AbstractRule
{
    use DetectsDirectiveAttributeCollisions;

    public function getId(): string
    {
        return 'blade-no-directive-attribute-collision';
    }

    public function getDescription(): string
    {
        return 'Event-listener attributes cannot use Blade directive names.';
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
        $checkElement = function (ElementNode $element) use ($context, $document): void {
            $this->checkAttributes($element, $document, $context);
        };

        $context->elements()->each($checkElement);
        $document->queryComponents()->each($checkElement);
    }

    private function checkAttributes(ElementNode $element, Document $document, RuleContext $context): void
    {
        foreach ($element->attributes() as $attribute) {
            $directive = $this->directiveAttributeCollision(
                $attribute,
                $document,
                $element instanceof ComponentNode ? $element : null,
            );
            if ($directive === null) {
                continue;
            }

            $name = $directive->nameText();
            $context->report(
                $directive,
                "@{$name}=\"...\" collides with Blade directive @{$name}."
            );
        }
    }
}
