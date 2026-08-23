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
use Forte\Sheath\Support\ReactiveAttributeSemantics;

/** @internal */
class AnchorContentRule extends AbstractRule
{
    use ChecksAccessibility;

    public function getId(): string
    {
        return 'a11y-anchor-content';
    }

    public function getDescription(): string
    {
        return 'Anchor elements must have accessible content (text, aria-label, or aria-labelledby).';
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
        $document->queryElements('a')
            ->each(function (ElementNode $anchor) use ($context): void {
                if ($this->isUnconditionallyExcludedFromAccessibilityTree($anchor)) {
                    return;
                }

                if ($this->hasTextContent($anchor)) {
                    return;
                }

                $nameAttributes = [
                    'aria-label', 'aria-labelledby', 'title',
                    ...ReactiveAttributeSemantics::CLIENT_TEXT_DIRECTIVES,
                ];
                $paths = $this->explicitAttributeRenderPaths(
                    $anchor,
                    ['href', ...$nameAttributes, 'hidden', 'inert', 'aria-hidden'],
                );
                if ($paths === null) {
                    return;
                }

                foreach ($paths as $path) {
                    if ($this->accessibilityAttributePathIsExcluded($path)
                        || $this->firstAccessibilityAttributeOnPath($path, 'href') === null
                        || $this->attributePathProvidesAccessibleName($anchor, $path, $nameAttributes)) {
                        continue;
                    }

                    $context->report(
                        $anchor,
                        'Anchor has no accessible content.'
                    );

                    return;
                }
            });
    }
}
