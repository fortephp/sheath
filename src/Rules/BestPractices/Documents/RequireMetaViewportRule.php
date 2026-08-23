<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\BestPractices\Documents;

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
use Forte\Support\ViewportValue;

/** @internal */
class RequireMetaViewportRule extends AbstractRule
{
    use ChecksRenderPathGuarantees;
    use DetectsOpaqueAttributes;
    use DetectsOpaqueHeadContent;
    use ValidatesDocumentStructure;

    protected array $options = [
        'validateContent' => true,
    ];

    public function getId(): string
    {
        return 'best-practices-require-meta-viewport';
    }

    public function getDescription(): string
    {
        return 'HTML documents should have a viewport meta tag for mobile responsiveness.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BEST_PRACTICES;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $validateContent = (bool) $this->getOption('validateContent', true);

        $headElement = $this->getHeadElement($document);
        if ($headElement === null) {
            return;
        }

        $hasDynamicName = false;
        $metaElements = $this->getHeadElementsByTagName($document, 'meta');

        foreach ($metaElements as $meta) {
            if ($this->elementHasUnmodelledAttributes($meta)
                && $this->firstAttributesOnRenderPaths($meta, 'name') === null) {
                $hasDynamicName = true;

                continue;
            }

            $paths = $this->explicitAttributeRenderPaths($meta, ['name', 'content']);
            if ($paths === null) {
                $hasDynamicName = true;

                continue;
            }

            foreach ($paths as $path) {
                $name = $this->firstAttributeOnRenderPath($path, 'name');
                if ($name !== null && $name->isDynamic()) {
                    $hasDynamicName = true;
                }
            }
        }

        if (! $this->everyRenderPathContains(
            $headElement->children(),
            fn ($node): bool => $node instanceof ElementNode
                && $node->isTag('meta')
                && $this->elementGuaranteesViewportMetadata($node),
            static fn (Node $node): bool => ! $node instanceof ElementNode
                || ! $node->isTag('template'),
        )) {
            if (! $hasDynamicName && ! $this->headContainsOpaqueContent($headElement)) {
                $context->report(
                    $headElement,
                    'HTML document is missing a viewport meta tag.'
                );
            }
        }

        if ($validateContent) {
            foreach ($metaElements as $viewportMeta) {
                if ($this->elementHasUnmodelledAttributes($viewportMeta)
                    && ($this->firstAttributesOnRenderPaths($viewportMeta, 'name') === null
                        || $this->firstAttributesOnRenderPaths($viewportMeta, 'content') === null)) {
                    continue;
                }

                $paths = $this->explicitAttributeRenderPaths($viewportMeta, ['name', 'content']);
                if ($paths === null) {
                    continue;
                }

                $reported = [];

                if ($this->attributeRenderPathsNeedIndependentConditionCorrelation($viewportMeta, ['name', 'content'])) {
                    if ($this->uniformAttributeRenderPathVerdict(
                        $viewportMeta,
                        ['name', 'content'],
                        fn (array $path): bool => $this->viewportPathHasContentViolation($path),
                    ) === true) {
                        foreach ($paths as $path) {
                            if (! $this->viewportPathHasContentViolation($path)) {
                                continue;
                            }

                            $this->validateViewportContent(
                                $viewportMeta,
                                $this->firstAttributeOnRenderPath($path, 'content'),
                                $context,
                                $reported,
                            );
                            break;
                        }
                    }

                    continue;
                }

                foreach ($paths as $path) {
                    $name = $this->firstAttributeOnRenderPath($path, 'name');
                    if ($name === null
                        || $name->isDynamic()
                        || strtolower($name->decodedValueText() ?? '') !== 'viewport') {
                        continue;
                    }

                    $content = $this->firstAttributeOnRenderPath($path, 'content');
                    if ($content !== null && $content->isDynamic()) {
                        continue;
                    }

                    $before = count($reported);
                    $this->validateViewportContent($viewportMeta, $content, $context, $reported);
                    if (count($reported) > $before) {
                        break;
                    }
                }
            }
        }
    }

    /** @param array<string, true> $reported */
    private function validateViewportContent(
        ElementNode $meta,
        ?Attribute $contentAttribute,
        RuleContext $context,
        array &$reported,
    ): void {
        $content = $contentAttribute?->decodedValueText();

        if ($content === null || ViewportValue::trimWhitespace($content) === '') {
            $this->reportOnce(
                $meta,
                'Viewport meta tag is missing a usable content attribute.',
                $context,
                $reported,
            );

            return;
        }

        $values = $this->parseViewportContent($content);

        if (! isset($values['width'])) {
            $this->reportOnce(
                $meta,
                'Viewport content is missing width=device-width.',
                $context,
                $reported,
            );
        } elseif ($values['width'] !== 'device-width' && ViewportValue::numberPrefix($values['width']) === null) {
            $this->reportOnce(
                $meta,
                'Invalid viewport width; expected "device-width" or a numeric value.',
                $context,
                $reported,
            );
        }

        if (isset($values['initial-scale'])) {
            $this->validateScale($meta, $values['initial-scale'], 'initial-scale', $context, $reported);
        }

        if (isset($values['maximum-scale'])) {
            $this->validateScale($meta, $values['maximum-scale'], 'maximum-scale', $context, $reported);
        }

        if (isset($values['minimum-scale'])) {
            $this->validateScale($meta, $values['minimum-scale'], 'minimum-scale', $context, $reported);
        }
    }

    /** @param array<string, true> $reported */
    private function validateScale(
        ElementNode $meta,
        string $value,
        string $name,
        RuleContext $context,
        array &$reported,
    ): void {
        $scale = ViewportValue::numberPrefix($value);
        if ($scale === null) {
            $this->reportOnce(
                $meta,
                "Invalid viewport {$name}; expected a number between 0.1 and 10.",
                $context,
                $reported,
            );

            return;
        }

        if ($scale < 0.1 || $scale > 10) {
            $this->reportOnce(
                $meta,
                "Viewport {$name} is outside the range 0.1 to 10.",
                $context,
                $reported,
            );
        }
    }

    private function elementGuaranteesViewportMetadata(ElementNode $meta): bool
    {
        if ($this->elementHasUnmodelledAttributes($meta)
            && $this->firstAttributesOnRenderPaths($meta, 'name') === null) {
            return true;
        }

        if ($this->attributeRenderPathsNeedIndependentConditionCorrelation($meta, ['name', 'content'])) {
            return $this->uniformAttributeRenderPathVerdict(
                $meta,
                ['name', 'content'],
                fn (array $path): bool => $this->pathProvidesViewportName($path),
            ) ?? true;
        }

        $paths = $this->explicitAttributeRenderPaths($meta, 'name');
        if ($paths === null) {
            return true;
        }

        foreach ($paths as $path) {
            if (! $this->pathProvidesViewportName($path)) {
                return false;
            }
        }

        return true;
    }

    /** @param list<Attribute> $path */
    private function pathProvidesViewportName(array $path): bool
    {
        $name = $this->firstAttributeOnRenderPath($path, 'name');

        return $name !== null
            && ($name->isDynamic() || strtolower($name->decodedValueText() ?? '') === 'viewport');
    }

    /** @param list<Attribute> $path */
    private function viewportPathHasContentViolation(array $path): bool
    {
        $name = $this->firstAttributeOnRenderPath($path, 'name');
        if ($name === null
            || $name->isDynamic()
            || strtolower($name->decodedValueText() ?? '') !== 'viewport') {
            return false;
        }

        $content = $this->firstAttributeOnRenderPath($path, 'content');
        if ($content !== null && $content->isDynamic()) {
            return false;
        }

        $value = $content?->decodedValueText();
        if ($value === null || ViewportValue::trimWhitespace($value) === '') {
            return true;
        }

        $values = $this->parseViewportContent($value);
        if (! isset($values['width'])
            || ($values['width'] !== 'device-width' && ViewportValue::numberPrefix($values['width']) === null)) {
            return true;
        }

        foreach (['initial-scale', 'maximum-scale', 'minimum-scale'] as $scaleName) {
            if (! isset($values[$scaleName])) {
                continue;
            }

            $scale = ViewportValue::numberPrefix($values[$scaleName]);
            if ($scale === null || $scale < 0.1 || $scale > 10) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, true> $reported */
    private function reportOnce(
        ElementNode $meta,
        string $message,
        RuleContext $context,
        array &$reported,
    ): void {
        if (isset($reported[$message])) {
            return;
        }

        $reported[$message] = true;
        $context->report($meta, $message);
    }

    /**
     * @return array<string, string>
     */
    private function parseViewportContent(string $content): array
    {
        $values = [];
        $pairs = preg_split('/[,;]/', $content);

        if ($pairs === false) {
            return $values;
        }

        foreach ($pairs as $pair) {
            $parts = explode('=', ViewportValue::trimWhitespace($pair), 2);
            if (count($parts) === 2) {
                $key = strtolower(ViewportValue::trimWhitespace($parts[0]));
                $values[$key] = ViewportValue::trimWhitespace($parts[1]);
            }
        }

        return $values;
    }
}
