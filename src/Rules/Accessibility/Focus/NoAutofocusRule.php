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
class NoAutofocusRule extends AbstractRule
{
    use DetectsOpaqueAttributes;
    use ReportsWithFix;

    public function getId(): string
    {
        return 'a11y-no-autofocus';
    }

    public function getDescription(): string
    {
        return 'Avoid autofocus attribute as it can cause accessibility issues.';
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
        $context->elements()
            ->each(function (ElementNode $element) use ($context): void {
                $autofocusAttributes = $this->attributesInRenderStructure($element, 'autofocus');
                if ($autofocusAttributes === []) {
                    return;
                }

                $autofocusAttr = count($autofocusAttributes) === 1 ? $autofocusAttributes[0] : null;

                $fix = $autofocusAttr !== null
                    && ! $autofocusAttr->isDynamic()
                    ? $this->createRemoveAttributeFix($autofocusAttr)
                    : null;

                $context->report(
                    $element,
                    'autofocus may move focus without user input.',
                    $fix
                );
            });
    }
}
