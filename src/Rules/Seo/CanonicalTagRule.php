<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Seo;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ChecksRenderPathGuarantees;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueHeadContent;
use Forte\Sheath\Rules\Concerns\ValidatesDocumentStructure;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class CanonicalTagRule extends AbstractRule
{
    use ChecksRenderPathGuarantees;
    use DetectsOpaqueAttributes;
    use DetectsOpaqueHeadContent;
    use ValidatesDocumentStructure;

    public function getId(): string
    {
        return 'seo-canonical-tag';
    }

    public function getDescription(): string
    {
        return 'HTML documents should have a canonical URL for SEO.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::SEO;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::INFO;
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

        foreach ($this->getHeadElementsByTagName($document, 'link') as $link) {
            if ($this->elementHasUnmodelledAttributes($link)
                && ($this->firstAttributesOnRenderPaths($link, 'rel') === null
                    || $this->firstAttributesOnRenderPaths($link, 'href') === null)) {
                continue;
            }

            $paths = $this->explicitAttributeRenderPaths($link, ['rel', 'href']);
            if ($paths === null) {
                continue;
            }

            if ($this->attributeRenderPathsNeedIndependentConditionCorrelation($link, ['rel', 'href'])) {
                if ($this->uniformAttributeRenderPathVerdict(
                    $link,
                    ['rel', 'href'],
                    fn (array $path): bool => $this->pathHasEmptyCanonicalHref($path),
                ) === true) {
                    $context->report(
                        $link,
                        'Canonical link has an empty href.'
                    );
                }

                continue;
            }

            foreach ($paths as $path) {
                $rel = $this->firstAttributeOnRenderPath($path, 'rel');
                if ($rel === null
                    || $rel->isDynamic()
                    || ! in_array('canonical', $rel->tokensLower(), true)) {
                    continue;
                }

                $href = $this->firstAttributeOnRenderPath($path, 'href');
                if ($href !== null && $href->isDynamic()) {
                    continue;
                }

                if ($href === null || trim($href->decodedValueText() ?? '') === '') {
                    $context->report(
                        $link,
                        'Canonical link has an empty href.'
                    );

                    break;
                }
            }
        }

        $hasCanonical = $this->everyRenderPathContains(
            $headElement->children(),
            fn (mixed $node): bool => ($node instanceof Node && $this->isOpaqueHeadContentNode($node))
                || ($node instanceof ElementNode && $this->mayProvideCanonical($node)),
            fn ($node): bool => ! $node instanceof ElementNode
                || ! $node->isTag('template'),
        );

        if (! $hasCanonical) {
            $context->report(
                $headElement,
                'HTML document is missing a canonical link.'
            );

            return;
        }
    }

    private function mayProvideCanonical(ElementNode $node): bool
    {
        if (! $node->isTag('link')) {
            return false;
        }

        if ($this->elementHasUnmodelledAttributes($node)
            && $this->firstAttributesOnRenderPaths($node, 'rel') === null) {
            return true;
        }

        if ($this->attributeRenderPathsNeedIndependentConditionCorrelation($node, ['rel', 'href'])) {
            return $this->uniformAttributeRenderPathVerdict(
                $node,
                ['rel', 'href'],
                fn (array $path): bool => $this->pathMayProvideCanonical($path),
            ) ?? true;
        }

        $paths = $this->explicitAttributeRenderPaths($node, ['rel', 'href']);
        if ($paths === null) {
            return true;
        }

        foreach ($paths as $path) {
            if (! $this->pathMayProvideCanonical($path)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<Attribute> $path */
    private function pathHasEmptyCanonicalHref(array $path): bool
    {
        $rel = $this->firstAttributeOnRenderPath($path, 'rel');
        if ($rel === null || $rel->isDynamic() || ! in_array('canonical', $rel->tokensLower(), true)) {
            return false;
        }

        $href = $this->firstAttributeOnRenderPath($path, 'href');

        return $href === null
            || (! $href->isDynamic() && trim($href->decodedValueText() ?? '') === '');
    }

    /** @param list<Attribute> $path */
    private function pathMayProvideCanonical(array $path): bool
    {
        $rel = $this->firstAttributeOnRenderPath($path, 'rel');
        if ($rel === null) {
            return false;
        }

        if (! $rel->isDynamic()) {
            return in_array('canonical', $rel->tokensLower(), true);
        }

        $href = $this->firstAttributeOnRenderPath($path, 'href');

        return $href !== null
            && ($href->isDynamic() || trim($href->decodedValueText() ?? '') !== '');
    }
}
