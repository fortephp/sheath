<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Headings;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ChecksAccessibility;
use Forte\Sheath\Rules\Concerns\ValidatesHeadings;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoEmptyHeadingsRule extends AbstractRule
{
    use ChecksAccessibility;
    use ValidatesHeadings;

    public function getId(): string
    {
        return 'a11y-no-empty-headings';
    }

    public function getDescription(): string
    {
        return 'Headings must have text content.';
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
        foreach ($context->elements() as $heading) {
            if (! $heading instanceof ElementNode
                || ! $this->isHeading($heading)) {
                continue;
            }

            if (! $this->hasAccessibleContent($heading)) {
                $context->report(
                    $heading,
                    'Heading has no accessible content.'
                );
            }
        }
    }
}
