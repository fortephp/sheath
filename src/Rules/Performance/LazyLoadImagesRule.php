<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Performance;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ChecksLoopContext;
use Forte\Sheath\Rules\Concerns\DetectsExclusiveBranches;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\Concerns\ReportsWithFix;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\ReactiveAttributeSemantics;

/** @internal */
class LazyLoadImagesRule extends AbstractRule
{
    use ChecksLoopContext;
    use DetectsExclusiveBranches;
    use DetectsOpaqueAttributes;
    use ReportsWithFix;

    protected array $options = [
        'skipAboveFold' => true,
        'excludePatterns' => [],
    ];

    public function getId(): string
    {
        return 'perf-lazy-load-images';
    }

    public function getDescription(): string
    {
        return 'Images should use loading="lazy" for better performance.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::PERFORMANCE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::INFO;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $skipAboveFold = (bool) $this->getOption('skipAboveFold', true);

        /** @var array<string> $excludePatterns */
        $excludePatterns = $this->getOption('excludePatterns', []);

        $aboveFoldCandidates = [];
        $candidateConditionalBlock = null;
        $candidateBranches = [];
        $canUseConditionalBuckets = true;

        $document->queryElements('img')->each(function (ElementNode $img) use (
            $context,
            $skipAboveFold,
            $excludePatterns,
            &$aboveFoldCandidates,
            &$candidateConditionalBlock,
            &$candidateBranches,
            &$canUseConditionalBuckets,
        ): void {
            if ($this->isInsideNonOutputCapture($img)) {
                return;
            }

            if ($this->unmodelledAttributesAffectDecision($img, $excludePatterns)) {
                return;
            }

            $branch = $this->conditionalBranchIdentity($img);
            $isAboveFoldCandidate = false;

            if ($skipAboveFold && ! $this->isInsideLoop($img)) {
                $isAboveFoldCandidate = $aboveFoldCandidates === []
                    || ($canUseConditionalBuckets
                        && $branch !== null
                        && $branch['block'] === $candidateConditionalBlock
                        && ! isset($candidateBranches[$branch['branch']]))
                    || $this->isExclusiveFromEvery($img, $aboveFoldCandidates);
            }

            if ($isAboveFoldCandidate) {
                $aboveFoldCandidates[] = $img;

                if (count($aboveFoldCandidates) === 1 && $branch !== null) {
                    $candidateConditionalBlock = $branch['block'];
                    $candidateBranches[$branch['branch']] = true;
                } elseif ($canUseConditionalBuckets
                    && $branch !== null
                    && $branch['block'] === $candidateConditionalBlock) {
                    $candidateBranches[$branch['branch']] = true;
                } else {
                    $canUseConditionalBuckets = false;
                }
            }

            $paths = $this->explicitAttributeRenderPaths($img, ['loading', 'fetchpriority'], true);
            if ($paths === null) {
                return;
            }

            $allPathsExempt = true;
            foreach ($paths as $path) {
                $loading = $this->firstAttributeOnRenderPath($path, 'loading');
                $fetchPriority = $this->firstAttributeOnRenderPath($path, 'fetchpriority');
                $isExempt = ($loading !== null && (ReactiveAttributeSemantics::valueIsDynamic($loading)
                        || strtolower($loading->decodedValueText() ?? '') === 'lazy'))
                    || ($fetchPriority !== null && ! ReactiveAttributeSemantics::valueIsDynamic($fetchPriority)
                        && strtolower($fetchPriority->decodedValueText() ?? '') === 'high');
                if (! $isExempt) {
                    $allPathsExempt = false;
                    break;
                }
            }

            if ($allPathsExempt) {
                return;
            }

            if ($isAboveFoldCandidate) {
                return;
            }

            $src = $img->attribute('src')?->decodedValueText() ?? '';
            foreach ($excludePatterns as $pattern) {
                if (is_string($pattern) && str_contains($src, $pattern)) {
                    return;
                }
            }

            $context->report(
                $img,
                'Image is missing loading="lazy".',
                $this->createLoadingFix($img)
            );
        });
    }

    /** @param array<string> $excludePatterns */
    private function unmodelledAttributesAffectDecision(
        ElementNode $image,
        array $excludePatterns,
    ): bool {
        if (! $this->elementHasUnmodelledAttributes($image)) {
            return false;
        }

        if ($this->firstAttributesOnRenderPaths($image, 'loading') === null
            || $this->firstAttributesOnRenderPaths($image, 'fetchpriority') === null) {
            return true;
        }

        return $excludePatterns !== []
            && $this->firstAttributesOnRenderPaths($image, 'src') === null;
    }

    private function createLoadingFix(ElementNode $img): ?Fix
    {
        $attributes = $this->attributesInRenderStructure($img, 'loading');
        if ($attributes === []) {
            return $this->createAddAttributeFix($img, 'loading', 'lazy', dangerous: true);
        }

        if (count($attributes) !== 1) {
            return null;
        }

        $attribute = $attributes[0];
        if ($attribute->isDynamic() || $attribute->hasComplexValue() || ! $attribute->isUnconditionallyPresent()) {
            return null;
        }

        return $this->createReplaceAttributeFix($attribute, 'loading="lazy"', dangerous: true);
    }

    /** @param array<ElementNode> $candidates */
    private function isExclusiveFromEvery(ElementNode $img, array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if (! $this->nodesAreMutuallyExclusive($candidate, $img)) {
                return false;
            }
        }

        return true;
    }
}
