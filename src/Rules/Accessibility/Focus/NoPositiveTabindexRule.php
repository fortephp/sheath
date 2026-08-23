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
use Forte\Support\HtmlInteger;

/** @internal */
class NoPositiveTabindexRule extends AbstractRule
{
    use DetectsOpaqueAttributes;
    use ReportsWithFix;

    public function getId(): string
    {
        return 'a11y-no-positive-tabindex';
    }

    public function getDescription(): string
    {
        return 'Disallow positive tabindex values as they disrupt the natural tab order.';
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
                $tabindexAttributes = $this->firstAttributesOnRenderPaths($element, 'tabindex');
                if ($tabindexAttributes === null) {
                    return;
                }

                foreach ($tabindexAttributes as $tabindexAttr) {
                    if ($tabindexAttr === null) {
                        continue;
                    }

                    if ($tabindexAttr->isDynamic()) {
                        continue;
                    }

                    $tabindex = HtmlInteger::parse($tabindexAttr->decodedValueText() ?? '');

                    if ($tabindex !== null && $tabindex > 0) {
                        $context->report(
                            $element,
                            "Positive tabindex ({$tabindex}) overrides the natural tab order.",
                            $this->createReplaceAttributeFix($tabindexAttr, 'tabindex="0"', dangerous: true)
                        );

                        return;
                    }
                }
            });
    }
}
