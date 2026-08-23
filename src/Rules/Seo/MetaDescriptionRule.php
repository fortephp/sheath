<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Seo;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Sheath\Exceptions\ConfigurationException;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ChecksRenderPathGuarantees;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueHeadContent;
use Forte\Sheath\Rules\Concerns\ValidatesDocumentStructure;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class MetaDescriptionRule extends AbstractRule
{
    use ChecksRenderPathGuarantees;
    use DetectsOpaqueAttributes;
    use DetectsOpaqueHeadContent;
    use ValidatesDocumentStructure;

    private const MIN_LENGTH = 50;

    private const MAX_LENGTH = 160;

    protected array $options = [
        'minLength' => self::MIN_LENGTH,
        'maxLength' => self::MAX_LENGTH,
    ];

    protected array $optionRules = [
        'minLength' => 'non-negative-integer',
        'maxLength' => 'non-negative-integer',
    ];

    protected function validateResolvedOptions(array $options): void
    {
        if ($options['minLength'] > $options['maxLength']) {
            throw ConfigurationException::invalidRuleOption(
                $this->getId(),
                'minLength',
                'a value less than or equal to maxLength',
            );
        }
    }

    public function getId(): string
    {
        return 'seo-meta-description';
    }

    public function getDescription(): string
    {
        return 'HTML documents should have a meta description for SEO.';
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

        /** @var int $minLength */
        $minLength = $this->getOption('minLength', self::MIN_LENGTH);
        /** @var int $maxLength */
        $maxLength = $this->getOption('maxLength', self::MAX_LENGTH);

        foreach ($this->getHeadElementsByTagName($document, 'meta') as $meta) {
            if ($this->elementHasUnmodelledAttributes($meta)
                && ($this->firstAttributesOnRenderPaths($meta, 'name') === null
                    || $this->firstAttributesOnRenderPaths($meta, 'content') === null)) {
                continue;
            }

            $paths = $this->explicitAttributeRenderPaths($meta, ['name', 'content']);
            if ($paths === null) {
                continue;
            }

            if ($this->attributeRenderPathsNeedIndependentConditionCorrelation($meta, ['name', 'content'])) {
                if ($this->uniformAttributeRenderPathVerdict(
                    $meta,
                    ['name', 'content'],
                    fn (array $path): bool => $this->descriptionPathIssue($path, $minLength, $maxLength) !== null,
                ) === true) {
                    foreach ($paths as $path) {
                        $message = $this->descriptionPathIssue($path, $minLength, $maxLength);
                        if ($message !== null) {
                            $context->report($meta, $message);
                            break;
                        }
                    }
                }

                continue;
            }

            $reportedAttributes = [];
            $reportedMissing = false;

            foreach ($paths as $path) {
                $name = $this->firstAttributeOnRenderPath($path, 'name');
                if ($name === null
                    || $name->isDynamic()
                    || strtolower($name->decodedValueText() ?? '') !== 'description') {
                    continue;
                }

                $contentAttribute = $this->firstAttributeOnRenderPath($path, 'content');
                if ($contentAttribute !== null && $contentAttribute->isDynamic()) {
                    continue;
                }

                if ($contentAttribute !== null) {
                    $attributeId = spl_object_id($contentAttribute);
                    if (isset($reportedAttributes[$attributeId])) {
                        continue;
                    }
                    $reportedAttributes[$attributeId] = true;
                } elseif ($reportedMissing) {
                    continue;
                } else {
                    $reportedMissing = true;
                }

                $content = $contentAttribute?->decodedValueText() ?? '';
                if (trim($content) === '') {
                    $context->report(
                        $meta,
                        'Meta description is empty.'
                    );

                    break;
                }

                $length = mb_strlen(trim($content));

                if ($length < $minLength) {
                    $context->report(
                        $meta,
                        "Meta description length {$length} is below the configured minimum {$minLength}."
                    );
                    break;
                } elseif ($length > $maxLength) {
                    $context->report(
                        $meta,
                        "Meta description length {$length} exceeds the configured maximum {$maxLength}."
                    );
                    break;
                }
            }
        }

        $hasDescription = $this->everyRenderPathContains(
            $headElement->children(),
            fn (mixed $node): bool => ($node instanceof Node && $this->isOpaqueHeadContentNode($node))
                || ($node instanceof ElementNode && $this->mayProvideDescription($node)),
            fn ($node): bool => ! $node instanceof ElementNode
                || ! $node->isTag('template'),
        );

        if (! $hasDescription) {
            $context->report(
                $headElement,
                'HTML document is missing a meta description.'
            );

            return;
        }
    }

    private function mayProvideDescription(ElementNode $node): bool
    {
        if (! $node->isTag('meta')) {
            return false;
        }

        if ($this->elementHasUnmodelledAttributes($node)
            && $this->firstAttributesOnRenderPaths($node, 'name') === null) {
            return true;
        }

        if ($this->attributeRenderPathsNeedIndependentConditionCorrelation($node, ['name', 'content'])) {
            return $this->uniformAttributeRenderPathVerdict(
                $node,
                ['name', 'content'],
                fn (array $path): bool => $this->pathMayProvideDescription($path),
            ) ?? true;
        }

        $paths = $this->explicitAttributeRenderPaths($node, ['name', 'content']);
        if ($paths === null) {
            return true;
        }

        foreach ($paths as $path) {
            if (! $this->pathMayProvideDescription($path)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<Attribute> $path */
    private function pathMayProvideDescription(array $path): bool
    {
        $name = $this->firstAttributeOnRenderPath($path, 'name');
        if ($name === null) {
            return false;
        }

        if ($name->isDynamic()) {
            return $this->pathMayProvideNonEmptyContent($path);
        }

        return strtolower($name->decodedValueText() ?? '') === 'description';
    }

    /** @param list<Attribute> $path */
    private function descriptionPathIssue(array $path, int $minLength, int $maxLength): ?string
    {
        $name = $this->firstAttributeOnRenderPath($path, 'name');
        if ($name === null
            || $name->isDynamic()
            || strtolower($name->decodedValueText() ?? '') !== 'description') {
            return null;
        }

        $content = $this->firstAttributeOnRenderPath($path, 'content');
        if ($content !== null && $content->isDynamic()) {
            return null;
        }

        $value = trim($content?->decodedValueText() ?? '');
        if ($value === '') {
            return 'Meta description is empty.';
        }

        $length = mb_strlen($value);
        if ($length < $minLength) {
            return "Meta description length {$length} is below the configured minimum {$minLength}.";
        }

        if ($length > $maxLength) {
            return "Meta description length {$length} exceeds the configured maximum {$maxLength}.";
        }

        return null;
    }

    /** @param list<Attribute> $path */
    private function pathMayProvideNonEmptyContent(array $path): bool
    {
        $content = $this->firstAttributeOnRenderPath($path, 'content');

        return $content !== null
            && ($content->isDynamic() || trim($content->decodedValueText() ?? '') !== '');
    }
}
