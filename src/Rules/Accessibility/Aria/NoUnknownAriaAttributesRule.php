<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Aria;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoUnknownAriaAttributesRule extends AbstractRule
{
    use ChecksAriaConformance;

    public function getId(): string
    {
        return 'a11y-no-unknown-aria-attributes';
    }

    public function getDescription(): string
    {
        return 'ARIA attribute names must be defined by WAI-ARIA 1.2.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::ACCESSIBILITY;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $context->elements()->each(function (ElementNode $element) use ($context): void {
            foreach ($this->explicitAriaAttributes($element) as $item) {
                if (Aria12Data::property($item['name']) !== null) {
                    continue;
                }

                $context->report(
                    $element,
                    "Unknown ARIA attribute \"{$item['name']}\"."
                );
            }
        });
    }
}
