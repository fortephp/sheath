<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Content;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ChecksAccessibility;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class RequireFrameTitleRule extends AbstractRule
{
    use ChecksAccessibility;

    public function getId(): string
    {
        return 'a11y-require-frame-title';
    }

    public function getDescription(): string
    {
        return 'Iframe elements must have a title attribute or another accessible name describing their content.';
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
        $document->queryElements('iframe')->each(function (ElementNode $iframe) use ($context): void {
            $this->validateFrameTitle($iframe, $context);
        });

        $document->queryElements('frame')->each(function (ElementNode $frame) use ($context): void {
            $this->validateFrameTitle($frame, $context);
        });
    }

    private function validateFrameTitle(ElementNode $element, RuleContext $context): void
    {
        if ($this->elementHasUnmodelledAttributes($element)
            || $this->isUnconditionallyExcludedFromAccessibilityTree($element)) {
            return;
        }

        $paths = $this->explicitAttributeRenderPaths(
            $element,
            ['aria-label', 'aria-labelledby', 'title', 'hidden', 'inert', 'aria-hidden'],
            true,
        );
        if ($paths === null) {
            return;
        }

        foreach ($paths as $path) {
            if ($this->accessibilityAttributePathIsExcluded($path)) {
                continue;
            }

            if ($this->attributePathProvidesAccessibleName(
                $element,
                $path,
                ['aria-label', 'aria-labelledby'],
            )) {
                continue;
            }

            $titleAttribute = $this->firstAccessibilityAttributeOnPath($path, 'title');
            if ($titleAttribute === null) {
                $context->report(
                    $element,
                    'Iframe has no accessible name.'
                );

                return;
            }

            if (! $this->attributeMayHaveNonEmptyValue($titleAttribute)) {
                $context->report(
                    $element,
                    'Iframe has an empty accessible name.'
                );

                return;
            }
        }
    }
}
