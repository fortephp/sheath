<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Focus;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\Concerns\ReportsWithFix;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoAccesskeyRule extends AbstractRule
{
    use DetectsOpaqueAttributes;
    use ReportsWithFix;

    public function getId(): string
    {
        return 'a11y-no-accesskey';
    }

    public function getDescription(): string
    {
        return 'The accesskey attribute should not be used.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::ACCESSIBILITY;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $context->elements()->each(function (ElementNode $element) use ($context): void {
            $accesskeyAttributes = $this->attributesInRenderStructure($element, 'accesskey');

            if ($accesskeyAttributes === []) {
                return;
            }

            $accesskeyAttr = count($accesskeyAttributes) === 1 ? $accesskeyAttributes[0] : null;

            $fix = $accesskeyAttr !== null
                && ! $accesskeyAttr->isDynamic()
                ? $this->createRemoveAttributeFix($accesskeyAttr)
                : null;

            $context->report(
                $element,
                'accesskey may conflict with assistive technology or browser shortcuts.',
                $fix
            );
        });
    }
}
