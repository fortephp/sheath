<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\BestPractices\Markup;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\Concerns\ReportsWithFix;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Support\JavaScriptMimeType;

/** @internal */
class NoScriptStyleTypeRule extends AbstractRule
{
    use DetectsOpaqueAttributes;
    use ReportsWithFix;

    public function getId(): string
    {
        return 'best-practices-no-script-style-type';
    }

    public function getDescription(): string
    {
        return 'Omit unnecessary type attribute on script and style elements.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BEST_PRACTICES;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::INFO;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $document->queryElements('script')
            ->each(function (ElementNode $element) use ($context): void {
                $this->checkElement($element, 'script', $context);
            });

        $document->queryElements('style')
            ->each(function (ElementNode $element) use ($context): void {
                $this->checkElement($element, 'style', $context);
            });
    }

    private function checkElement(ElementNode $element, string $tagName, RuleContext $context): void
    {
        $typeAttributes = $this->firstAttributesOnRenderPaths($element, 'type');
        if ($typeAttributes === null) {
            return;
        }

        $seen = [];
        foreach ($typeAttributes as $typeAttr) {
            if ($typeAttr === null || isset($seen[spl_object_id($typeAttr)])) {
                continue;
            }
            $seen[spl_object_id($typeAttr)] = true;

            if ($typeAttr->isDynamic()) {
                continue;
            }

            $typeValue = JavaScriptMimeType::normalizeScriptType($typeAttr->decodedValueText() ?? '');
            $isUnnecessary = $tagName === 'script'
                ? $typeValue === '' || JavaScriptMimeType::isEssenceMatch($typeValue)
                : $typeValue === 'text/css';

            if (! $isUnnecessary) {
                continue;
            }

            $context->report(
                $element,
                $typeValue === ''
                    ? "The empty type attribute on <{$tagName}> is unnecessary."
                    : "The type attribute on <{$tagName}> is unnecessary for '{$typeValue}'.",
                count($this->attributesInRenderStructure($element, 'type')) === 1
                    ? $this->createRemoveAttributeFix($typeAttr, dangerous: false)
                    : null
            );
        }
    }
}
