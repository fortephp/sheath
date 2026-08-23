<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Seo;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ChecksLoopContext;
use Forte\Sheath\Rules\Concerns\DetectsExclusiveBranches;
use Forte\Sheath\Rules\Concerns\ValidatesHeadings;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\ReactiveAttributeSemantics;

/** @internal */
class NoMultipleH1Rule extends AbstractRule
{
    use ChecksLoopContext;
    use DetectsExclusiveBranches;
    use ValidatesHeadings;

    public function getId(): string
    {
        return 'seo-no-multiple-h1';
    }

    public function getDescription(): string
    {
        return 'Disallow multiple <h1> elements for better SEO and document structure.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::SEO;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $h1Elements = array_values(array_filter(
            $this->collectHeadingsOfLevel($document, 1),
            fn (ElementNode $h1): bool => ! $this->isInsideInertTemplate($h1)
                && ! $this->isInsideNonOutputCapture($h1),
        ));
        $reportedOffsets = [];

        if (count($h1Elements) > 1) {
            foreach ($this->conflictingDuplicates($h1Elements) as $h1) {
                $context->report(
                    $h1,
                    'Document has multiple <h1> elements.'
                );
                $reportedOffsets[$h1->startOffset()] = true;
            }
        }

        foreach ($h1Elements as $h1) {
            $insideAlpineLoop = $this->isInsideAlpineForTemplate($h1);
            if ((! $this->isInsideLoop($h1) && ! $insideAlpineLoop)
                || isset($reportedOffsets[$h1->startOffset()])) {
                continue;
            }

            $context->report(
                $h1,
                $insideAlpineLoop
                    ? '<h1> inside Alpine x-for may render more than once.'
                    : '<h1> inside a Blade loop may render more than once.'
            );
        }
    }

    private function isInsideInertTemplate(ElementNode $element): bool
    {
        foreach ($element->ancestors() as $ancestor) {
            if ($ancestor instanceof ElementNode
                && $ancestor->isTag('template')
                && ! ReactiveAttributeSemantics::rendersTemplateContent($ancestor)) {
                return true;
            }
        }

        return false;
    }

    private function isInsideAlpineForTemplate(ElementNode $element): bool
    {
        foreach ($element->ancestors() as $ancestor) {
            if ($ancestor instanceof ElementNode
                && $ancestor->isTag('template')
                && $ancestor->hasAttribute('x-for')) {
                return true;
            }
        }

        return false;
    }
}
