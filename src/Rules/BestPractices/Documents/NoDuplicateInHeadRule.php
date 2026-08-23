<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\BestPractices\Documents;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ChecksLoopContext;
use Forte\Sheath\Rules\Concerns\DetectsExclusiveBranches;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\Concerns\RecognizesCharsetDeclarations;
use Forte\Sheath\Rules\Concerns\ValidatesDocumentStructure;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoDuplicateInHeadRule extends AbstractRule
{
    use ChecksLoopContext;
    use DetectsExclusiveBranches;
    use DetectsOpaqueAttributes;
    use RecognizesCharsetDeclarations;
    use ValidatesDocumentStructure;

    protected array $options = [
        'uniqueMetaNames' => [
            'viewport',
            'description',
            'author',
            'robots',
            'theme-color',
        ],
    ];

    public function getId(): string
    {
        return 'best-practices-no-duplicate-in-head';
    }

    public function getDescription(): string
    {
        return 'Certain head elements should not be duplicated.';
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
        if (! $this->hasHeadElement($document)) {
            return;
        }

        $titles = $this->getHeadElementsByTagName($document, 'title');
        $metas = $this->getHeadElementsByTagName($document, 'meta');
        $reportedOffsets = $this->emptyOffsetSet();

        $this->checkDuplicateTitles($titles, $context, $reportedOffsets);
        $this->checkDuplicateCharset($metas, $context, $reportedOffsets);
        $this->checkDuplicateMetaNames($metas, $context, $reportedOffsets);
        $this->checkLoopRepeatedElements($titles, $metas, $context, $reportedOffsets);
    }

    /**
     * @param  array<int, ElementNode>  $titles
     * @param  array<int, true>  $reportedOffsets
     */
    private function checkDuplicateTitles(array $titles, RuleContext $context, array &$reportedOffsets): void
    {
        foreach ($this->conflictingDuplicates(
            $titles,
            cannotCorrelate: fn (ElementNode $a, ElementNode $b): bool => $this->nodesHaveComplementaryConditionalPredicates($a, $b),
        ) as $title) {
            $context->report(
                $title,
                'Duplicate <title> element.'
            );
            $reportedOffsets[$title->startOffset()] = true;
        }
    }

    /**
     * @param  array<int, ElementNode>  $metas
     * @param  array<int, true>  $reportedOffsets
     */
    private function checkDuplicateCharset(array $metas, RuleContext $context, array &$reportedOffsets): void
    {
        $charsetMetas = [];

        foreach ($metas as $meta) {
            if ($this->charsetDeclarationCandidateStatus($meta) === true) {
                $charsetMetas[] = $meta;
            }
        }

        if (count($charsetMetas) > 1) {
            foreach ($this->conflictingDuplicates(
                $charsetMetas,
                cannotCorrelate: fn (ElementNode $a, ElementNode $b): bool => $this->nodesHaveComplementaryConditionalPredicates($a, $b),
            ) as $meta) {
                $context->report(
                    $meta,
                    'Duplicate character encoding declaration.'
                );
                $reportedOffsets[$meta->startOffset()] = true;
            }
        }
    }

    /**
     * @param  array<int, ElementNode>  $metas
     * @param  array<int, true>  $reportedOffsets
     */
    private function checkDuplicateMetaNames(array $metas, RuleContext $context, array &$reportedOffsets): void
    {
        $metaByName = [];
        $uniqueMetaNamesOption = $this->getOption('uniqueMetaNames', []);
        $uniqueMetaNames = array_map(
            strtolower(...),
            is_array($uniqueMetaNamesOption) ? array_filter($uniqueMetaNamesOption, is_string(...)) : [],
        );

        foreach ($metas as $meta) {
            if ($this->elementHasUnmodelledAttributes($meta)
                && $this->firstAttributesOnRenderPaths($meta, 'name') === null) {
                continue;
            }

            $paths = $this->explicitAttributeRenderPaths($meta, ['name', 'media']);
            if ($paths === null) {
                continue;
            }

            $keysForElement = [];

            foreach ($paths as $path) {
                $nameAttribute = $this->firstAttributeOnRenderPath($path, 'name');
                if ($nameAttribute === null || $nameAttribute->isDynamic()) {
                    continue;
                }

                $nameLower = strtolower($nameAttribute->decodedValueText() ?? '');
                if ($nameLower === '' || ! in_array($nameLower, $uniqueMetaNames, true)) {
                    continue;
                }

                if ($nameLower === 'theme-color') {
                    if ($this->elementHasUnmodelledAttributes($meta)
                        && $this->firstAttributesOnRenderPaths($meta, 'media') === null) {
                        continue;
                    }

                    $media = $this->firstAttributeOnRenderPath($path, 'media');
                    if ($media !== null && $media->isDynamic()) {
                        continue;
                    }

                    $mediaKey = $media === null
                        ? "\0missing"
                        : "\0value\0".($media->decodedValueText() ?? '');
                    $keysForElement[$nameLower.$mediaKey] = true;

                    continue;
                }

                $keysForElement[$nameLower] = true;
            }

            foreach (array_keys($keysForElement) as $key) {
                $metaByName[$key][] = $meta;
            }
        }

        foreach ($metaByName as $key => $metas) {
            if (count($metas) > 1) {
                $name = explode("\0", $key, 2)[0];

                foreach ($this->conflictingDuplicates(
                    $metas,
                    cannotCorrelate: fn (ElementNode $a, ElementNode $b): bool => $this->metaDuplicatesAreProvenExclusive($a, $b, $name),
                ) as $meta) {
                    $context->report(
                        $meta,
                        "Duplicate <meta name=\"{$name}\"> element."
                    );
                    $reportedOffsets[$meta->startOffset()] = true;
                }
            }
        }
    }

    private function metaDuplicatesAreProvenExclusive(ElementNode $a, ElementNode $b, string $name): bool
    {
        if ($this->nodesHaveComplementaryConditionalPredicates($a, $b)) {
            return true;
        }

        if ($name === 'theme-color') {
            return false;
        }

        return $this->predicateChainsAreComplementary(
            $this->conditionalMetaNamePredicates($a, $name),
            $this->conditionalMetaNamePredicates($b, $name),
        );
    }

    /** @return list<array{expression: string, when: bool}>|null */
    private function conditionalMetaNamePredicates(ElementNode $meta, string $name): ?array
    {
        $attributes = $this->firstAttributesOnRenderPaths($meta, 'name');
        if ($attributes === null) {
            return null;
        }

        $providers = [];

        foreach ($attributes as $attribute) {
            if ($attribute === null
                || $attribute->isDynamic()
                || strtolower($attribute->decodedValueText() ?? '') !== $name) {
                continue;
            }

            $predicates = $this->conditionalPredicateIdentities($attribute);
            if ($predicates === []) {
                return null;
            }

            $providers[spl_object_id($attribute)] = $predicates;
        }

        return count($providers) === 1 ? array_values($providers)[0] : null;
    }

    /**
     * @param  list<array{expression: string, when: bool}>|null  $a
     * @param  list<array{expression: string, when: bool}>|null  $b
     */
    private function predicateChainsAreComplementary(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }

        foreach ($a as $predicateA) {
            foreach ($b as $predicateB) {
                if ($predicateA['expression'] === $predicateB['expression']
                    && $predicateA['when'] !== $predicateB['when']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, ElementNode>  $titles
     * @param  array<int, ElementNode>  $metas
     * @param  array<int, true>  $reportedOffsets
     */
    private function checkLoopRepeatedElements(
        array $titles,
        array $metas,
        RuleContext $context,
        array $reportedOffsets,
    ): void {
        foreach ($titles as $title) {
            if ($this->isInsideLoop($title) && ! isset($reportedOffsets[$title->startOffset()])) {
                $context->report(
                    $title,
                    'A <title> inside a Blade loop may render more than once.'
                );
            }
        }

        foreach ($metas as $meta) {
            if (! $this->isInsideLoop($meta) || isset($reportedOffsets[$meta->startOffset()])) {
                continue;
            }

            if ($this->charsetDeclarationCandidateStatus($meta) === true) {
                $context->report(
                    $meta,
                    'A charset declaration inside a Blade loop may render more than once.'
                );

                continue;
            }

            $uniqueMetaNamesOption = $this->getOption('uniqueMetaNames', []);
            $uniqueMetaNames = array_map(
                strtolower(...),
                is_array($uniqueMetaNamesOption) ? array_filter($uniqueMetaNamesOption, is_string(...)) : [],
            );

            foreach ($this->possibleStaticMetaNames($meta) as $name) {
                if (! in_array($name, $uniqueMetaNames, true)) {
                    continue;
                }

                $context->report(
                    $meta,
                    "A <meta name=\"{$name}\"> inside a Blade loop may render more than once."
                );

                break;
            }
        }
    }

    /** @return list<string> */
    private function possibleStaticMetaNames(ElementNode $meta): array
    {
        if ($this->elementHasUnmodelledAttributes($meta)
            && $this->firstAttributesOnRenderPaths($meta, 'name') === null) {
            return [];
        }

        $attributes = $this->firstAttributesOnRenderPaths($meta, 'name');
        if ($attributes === null) {
            return [];
        }

        $names = [];

        foreach ($attributes as $attribute) {
            if ($attribute === null || $attribute->isDynamic()) {
                continue;
            }

            $name = strtolower($attribute->decodedValueText() ?? '');
            if ($name !== '') {
                $names[$name] = true;
            }
        }

        return array_keys($names);
    }

    /** @return array<int, true> */
    private function emptyOffsetSet(): array
    {
        return [];
    }
}
