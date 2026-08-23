<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Performance;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\Concerns\ReportsWithFix;
use Forte\Sheath\Rules\Concerns\TraversesRenderedTree;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Support\JavaScriptMimeType;

/** @internal */
class NoRenderBlockingResourcesRule extends AbstractRule
{
    use DetectsOpaqueAttributes;
    use ReportsWithFix;
    use TraversesRenderedTree;

    protected array $options = [
        'excludePatterns' => [],
    ];

    public function getId(): string
    {
        return 'perf-no-render-blocking';
    }

    public function getDescription(): string
    {
        return 'Scripts should use async or defer to avoid blocking page rendering.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::PERFORMANCE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        /** @var array<string> $excludePatterns */
        $excludePatterns = $this->getOption('excludePatterns', []);

        $elements = $document->elementsGroupedByName(['script', 'body']);
        $lastBodyContentOffsets = [];

        foreach ($elements['body'] as $body) {
            $lastBodyContentOffsets[spl_object_id($body)] = $this->lastRenderedContentStartOffset($body, ['script', 'template']);
        }

        foreach ($elements['script'] as $script) {
            if ($script->hasAncestorElement('template') || $this->isInsideNonOutputCapture($script)) {
                continue;
            }

            $srcAttribute = $script->attribute('src');
            $src = $srcAttribute?->isStatic() === true && ! $srcAttribute->hasComplexValue()
                ? $srcAttribute->decodedValueText()
                : $srcAttribute?->valueText();
            if ($src === null || $src === '') {
                continue;
            }

            if ($this->elementHasUnmodelledAttributes($script)) {
                continue;
            }

            $paths = $this->explicitAttributeRenderPaths($script, ['type', 'async', 'defer', 'blocking']);
            if ($paths === null) {
                continue;
            }

            $explicitlyRenderBlocking = false;
            $hasBlockingPath = false;
            foreach ($paths as $path) {
                $typeAttribute = $this->firstAttributeOnRenderPath($path, 'type');
                if ($typeAttribute !== null && $typeAttribute->isDynamic()) {
                    continue;
                }

                $type = JavaScriptMimeType::normalizeScriptType($typeAttribute?->decodedValueText() ?? '');
                if ($type !== '' && $type !== 'module' && ! JavaScriptMimeType::isEssenceMatch($type)) {
                    continue;
                }

                $blocking = $this->firstAttributeOnRenderPath($path, 'blocking');
                $pathExplicitlyBlocks = $this->canRequestRenderBlocking($script)
                    && $blocking !== null
                    && ! $blocking->isDynamic()
                    && in_array('render', $blocking->tokensLower(), true);
                if ($pathExplicitlyBlocks) {
                    $explicitlyRenderBlocking = true;
                    $hasBlockingPath = true;

                    continue;
                }

                if ($type === 'module'
                    || $this->firstAttributeOnRenderPath($path, 'async') !== null
                    || $this->firstAttributeOnRenderPath($path, 'defer') !== null) {
                    continue;
                }

                $hasBlockingPath = true;
            }

            if (! $hasBlockingPath) {
                continue;
            }

            if ($this->matchesAnyPattern($src, $excludePatterns)) {
                continue;
            }

            if ($this->isAtEndOfBody($script, $lastBodyContentOffsets)) {
                continue;
            }

            $context->report(
                $script,
                $explicitlyRenderBlocking
                    ? 'External script explicitly requests render blocking.'
                    : 'External script blocks rendering without async or defer.',

                $explicitlyRenderBlocking
                    ? null
                    : $this->createInsertAttributeFix($script, 'defer', dangerous: true)
            );
        }
    }

    private function canRequestRenderBlocking(ElementNode $script): bool
    {
        if ($script->closestElement('body') !== null) {
            return false;
        }

        foreach ($script->getDocument()->queryElements('body') as $body) {
            if ($body->startOffset() < $script->startOffset()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<mixed>  $patterns
     */
    private function matchesAnyPattern(string $src, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && str_contains($src, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, int|null> $lastBodyContentOffsets */
    private function isAtEndOfBody(ElementNode $script, array $lastBodyContentOffsets): bool
    {
        if ($script->hasAncestorElement('head')) {
            return false;
        }

        $body = $script->closestElement('body');

        if ($body === null) {
            return false;
        }

        $lastContentOffset = $lastBodyContentOffsets[spl_object_id($body)] ?? null;

        return $lastContentOffset === null || $lastContentOffset < $script->endOffset();
    }
}
