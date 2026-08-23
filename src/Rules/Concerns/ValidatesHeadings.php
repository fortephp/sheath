<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Concerns;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Rules\Accessibility\Aria\AriaData;
use Forte\Sheath\Rules\Accessibility\Aria\NoInvalidRoleRule;
use Forte\Sheath\Support\ReactiveAttributeSemantics;
use Forte\Support\HtmlInteger;
use Forte\Support\HtmlWhitespace;

/** @internal */
trait ValidatesHeadings
{
    use ResolvesAccessibilityTree;

    private const UNKNOWN_HEADING_LEVEL = 0;

    private const HEADING_TAGS = ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];

    protected function isHeading(ElementNode $element): bool
    {
        return $this->effectiveHeadingLevels($element) !== [];
    }

    protected function getHeadingLevel(ElementNode $element): ?int
    {
        $tagName = strtolower($element->tagNameText());

        if (! str_starts_with($tagName, 'h') || strlen($tagName) !== 2) {
            return null;
        }

        $level = (int) $tagName[1];

        return ($level >= 1 && $level <= 6) ? $level : null;
    }

    /** @return list<int> */
    protected function effectiveHeadingLevels(ElementNode $element): array
    {
        $nativeLevel = $this->getHeadingLevel($element);
        if ($nativeLevel === null
            && $this->attributesInRenderStructure($element, 'role') === []) {
            return [];
        }

        if ($this->isUnconditionallyExcludedFromAccessibilityTree($element)
            || $this->elementHasUnmodelledAttributes($element)) {
            return [];
        }

        $names = array_values(array_unique([
            'role', 'aria-level', 'hidden', 'inert', 'aria-hidden', 'tabindex', 'contenteditable',
            ...AriaData::globalProperties(),
        ]));
        if ($this->attributeRenderPathsNeedIndependentConditionCorrelation($element, $names)) {
            return [];
        }

        $paths = $this->explicitAttributeRenderPaths($element, $names, true);
        if ($paths === null || $paths === []) {
            return [];
        }

        $levels = [];

        foreach ($paths as $path) {
            if ($this->accessibilityAttributePathIsExcluded($path)) {
                continue;
            }

            $roleAttribute = $this->firstAttributeOnRenderPath($path, 'role');
            $role = $roleAttribute !== null && ! $roleAttribute->isDynamic()
                ? $this->recognizedHeadingRole($roleAttribute)
                : null;

            if (in_array($role, ['none', 'presentation'], true)) {
                if ($nativeLevel !== null && $this->presentationRoleConflictsOnHeadingPath($path)) {
                    $levels[$nativeLevel] = true;
                }

                continue;
            }

            if ($role !== null && $role !== 'heading') {
                continue;
            }

            if ($role !== 'heading') {
                if ($nativeLevel !== null) {
                    $levels[$nativeLevel] = true;
                }

                continue;
            }

            $levelAttribute = $this->firstAttributeOnRenderPath($path, 'aria-level');
            if ($levelAttribute !== null && $levelAttribute->isDynamic()) {
                $levels[self::UNKNOWN_HEADING_LEVEL] = true;

                continue;
            }

            $level = HtmlWhitespace::trim($levelAttribute?->decodedValueText() ?? '');
            $parsedLevel = preg_match('/^\+?[0-9]+$/D', $level) === 1 ? (int) $level : null;
            $resolved = $parsedLevel !== null && $parsedLevel > 0 ? $parsedLevel : ($nativeLevel ?? 2);
            $levels[$resolved] = true;
        }

        $result = array_keys($levels);
        sort($result);

        return $result;
    }

    protected function isInsideTemplate(ElementNode $element): bool
    {
        $ancestor = $element->getParent();

        while ($ancestor !== null) {
            if ($ancestor instanceof ElementNode
                && $ancestor->isTag('template')
                && ! ReactiveAttributeSemantics::isLocalTemplateRenderer($ancestor)) {
                return true;
            }

            $ancestor = $ancestor->getParent();
        }

        return false;
    }

    private function recognizedHeadingRole(Attribute $attribute): ?string
    {
        foreach ($attribute->tokensLower() as $role) {
            if (NoInvalidRoleRule::isValidRole($role)) {
                return $role;
            }
        }

        return null;
    }

    /** @param list<Attribute> $path */
    private function presentationRoleConflictsOnHeadingPath(array $path): bool
    {
        $tabindex = $this->firstAttributeOnRenderPath($path, 'tabindex');
        if ($tabindex !== null) {
            $value = $tabindex->decodedValueText();
            if ($tabindex->isDynamic()
                || ($value !== null && HtmlInteger::parse($value) !== null)) {
                return true;
            }
        }

        $contenteditable = $this->firstAttributeOnRenderPath($path, 'contenteditable');
        if ($contenteditable !== null
            && ($contenteditable->isDynamic()
                || in_array(strtolower($contenteditable->decodedValueText() ?? ''), ['', 'true', 'plaintext-only'], true))) {
            return true;
        }

        foreach (AriaData::globalProperties() as $name) {
            if ($this->firstAttributeOnRenderPath($path, $name) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<array{node: ElementNode, level: int, line: int}>
     */
    protected function collectHeadings(Document $document): array
    {
        $headings = [];

        $byTag = $document->elementsGroupedByName(self::HEADING_TAGS);

        foreach (self::HEADING_TAGS as $tag) {
            foreach ($byTag[$tag] as $heading) {
                $headings[] = [
                    'node' => $heading,
                    'level' => (int) substr($tag, 1),
                    'line' => $heading->startLine(),
                ];
            }
        }

        usort($headings, fn (array $a, array $b): int => $a['node']->startOffset() <=> $b['node']->startOffset());

        return $headings;
    }

    /**
     * @return array<ElementNode>
     */
    protected function collectHeadingsOfLevel(Document $document, int $level): array
    {
        if ($level < 1 || $level > 6) {
            return [];
        }

        $headings = [];
        $tag = 'h'.$level;

        $document->queryElements($tag)->each(function (ElementNode $heading) use (&$headings): void {
            $headings[] = $heading;
        });

        return $headings;
    }

    protected function countHeadingsOfLevel(Document $document, int $level): int
    {
        if ($level < 1 || $level > 6) {
            return 0;
        }

        return $document->queryElements('h'.$level)->count();
    }

    /**
     * @return list<string>
     */
    protected function getHeadingTags(): array
    {
        return self::HEADING_TAGS;
    }
}
