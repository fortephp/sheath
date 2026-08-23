<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Seo;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ChecksAccessibility;
use Forte\Sheath\Rules\Concerns\ChecksRenderPathGuarantees;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueHeadContent;
use Forte\Sheath\Rules\Concerns\ValidatesDocumentStructure;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class RequireTitleRule extends AbstractRule
{
    use ChecksAccessibility;
    use ChecksRenderPathGuarantees;
    use DetectsOpaqueHeadContent;
    use ValidatesDocumentStructure;

    public function getId(): string
    {
        return 'seo-require-title';
    }

    public function getDescription(): string
    {
        return 'HTML documents should have a <title> element in the <head>.';
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
        if (! $this->hasHeadElement($document)) {
            return;
        }

        $headElement = $this->getHeadElement($document);
        if ($headElement === null) {
            return;
        }

        $titleElements = $this->getHeadElementsByTagName($document, 'title');

        $hasGuaranteedTitle = $this->everyRenderPathContains(
            $headElement->children(),
            fn (mixed $node): bool => ($node instanceof Node && $this->isOpaqueHeadContentNode($node))
                || ($node instanceof ElementNode
                    && $node->isTag('title')),
            fn ($node): bool => ! $node instanceof ElementNode
                || ! $node->isTag('template'),
        );

        if (! $hasGuaranteedTitle) {
            $context->report(
                $headElement,
                'HTML document is missing a <title> element.'
            );
        }

        foreach ($titleElements as $title) {
            if (! $this->hasTextContent($title)) {
                $context->report(
                    $title,
                    '<title> element is empty.'
                );
            }
        }
    }
}
