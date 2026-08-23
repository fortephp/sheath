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
class RequireOpenGraphRule extends AbstractRule
{
    use ChecksRenderPathGuarantees;
    use DetectsOpaqueAttributes;
    use DetectsOpaqueHeadContent;
    use ValidatesDocumentStructure;

    protected array $options = [
        'checkRecommended' => false,
        'checkImageProperties' => false,
    ];

    private const REQUIRED_OG_PROPERTIES = [
        'og:title',
        'og:type',
        'og:image',
        'og:url',
    ];

    private const RECOMMENDED_OG_PROPERTIES = [
        'og:description',
        'og:site_name',
    ];

    private const IMAGE_OG_PROPERTIES = [
        'og:image:width',
        'og:image:height',
        'og:image:alt',
    ];

    public function getId(): string
    {
        return 'seo-require-open-graph';
    }

    public function getDescription(): string
    {
        return 'Pages should have Open Graph meta tags for social sharing.';
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
        $checkRecommended = (bool) $this->getOption('checkRecommended', false);
        $checkImageProperties = (bool) $this->getOption('checkImageProperties', false);

        if (! $this->hasHeadElement($document)) {
            return;
        }

        $headElement = $this->getHeadElement($document);

        if ($headElement === null) {
            return;
        }

        $foundOgProperties = [];

        foreach ($this->getHeadElementsByTagName($document, 'meta') as $meta) {
            foreach ([...self::REQUIRED_OG_PROPERTIES, ...self::RECOMMENDED_OG_PROPERTIES, ...self::IMAGE_OG_PROPERTIES] as $propertyToken) {
                if ($this->mayProvideProperty($meta, $propertyToken)) {
                    $foundOgProperties[$propertyToken] = true;
                }
            }
        }

        $missingProperties = [];
        foreach (self::REQUIRED_OG_PROPERTIES as $required) {
            if (! $this->everyRenderPathContains(
                $headElement->children(),
                fn (mixed $node): bool => $node instanceof Node && $this->mayProvideProperty($node, $required),
                fn (Node $node): bool => ! $node instanceof ElementNode
                    || ! $node->isTag('template'),
            )) {
                $missingProperties[] = $required;
            }
        }

        if (! empty($missingProperties)) {
            $missing = implode(', ', $missingProperties);
            $context->report(
                $headElement,
                "Missing Open Graph properties: {$missing}."
            );

            return;
        }

        if ($checkRecommended) {
            $missingRecommended = [];
            foreach (self::RECOMMENDED_OG_PROPERTIES as $recommended) {
                if (! $this->everyRenderPathContains(
                    $headElement->children(),
                    fn (mixed $node): bool => $node instanceof Node && $this->mayProvideProperty($node, $recommended),
                    fn (Node $node): bool => ! $node instanceof ElementNode
                        || ! $node->isTag('template'),
                )) {
                    $missingRecommended[] = $recommended;
                }
            }

            if (! empty($missingRecommended)) {
                $missing = implode(', ', $missingRecommended);
                $context->report(
                    $headElement,
                    "Missing recommended Open Graph properties: {$missing}."
                );
            }
        }

        if ($checkImageProperties && isset($foundOgProperties['og:image'])) {
            $missingImageProps = [];
            foreach (self::IMAGE_OG_PROPERTIES as $imageProp) {
                if (! $this->everyRenderPathContains(
                    $headElement->children(),
                    fn (mixed $node): bool => $node instanceof Node && $this->mayProvideProperty($node, $imageProp),
                    fn (Node $node): bool => ! $node instanceof ElementNode
                        || ! $node->isTag('template'),
                )) {
                    $missingImageProps[] = $imageProp;
                }
            }

            if (! empty($missingImageProps)) {
                $missing = implode(', ', $missingImageProps);
                $context->report(
                    $headElement,
                    "Missing Open Graph image properties: {$missing}."
                );
            }
        }
    }

    private function mayProvideProperty(Node $node, string $property): bool
    {
        if ($this->isOpaqueHeadContentNode($node)) {
            return true;
        }

        if (! $node instanceof ElementNode || ! $node->isTag('meta')) {
            return false;
        }

        if ($this->elementHasUnmodelledAttributes($node)
            && ($this->firstAttributesOnRenderPaths($node, 'property') === null
                || $this->firstAttributesOnRenderPaths($node, 'content') === null)) {
            return true;
        }

        $paths = $this->explicitAttributeRenderPaths($node, ['property', 'content']);
        if ($paths === null) {
            return true;
        }

        foreach ($paths as $path) {
            if (! $this->pathMayProvideProperty($path, $property)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<Attribute> $path */
    private function pathMayProvideProperty(array $path, string $property): bool
    {
        $propertyAttribute = $this->firstAttributeOnRenderPath($path, 'property');
        if ($propertyAttribute === null) {
            return false;
        }

        if (! $propertyAttribute->isDynamic()
            && ! in_array($property, $propertyAttribute->tokensLower(), true)) {
            return false;
        }

        $contentAttribute = $this->firstAttributeOnRenderPath($path, 'content');

        return $contentAttribute !== null
            && $this->attributeMayHaveNonEmptyValue($contentAttribute);
    }
}
