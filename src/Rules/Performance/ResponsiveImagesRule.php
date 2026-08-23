<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Performance;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Parsing\ExpressionScanner;
use Forte\Sheath\Parsing\SrcsetParser;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Accessibility\Aria\AriaData;
use Forte\Sheath\Rules\Accessibility\Aria\NoInvalidRoleRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\Concerns\TraversesRenderedTree;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Support\HtmlInteger;
use Forte\Support\HtmlWhitespace;
use Forte\Support\ImageSource;

/** @internal */
class ResponsiveImagesRule extends AbstractRule
{
    use DetectsOpaqueAttributes;
    use TraversesRenderedTree;

    protected array $options = [
        'minWidth' => 300,
        'excludePatterns' => [],
        'excludeClasses' => ['icon', 'favicon', 'logo-small', 'badge', 'avatar-sm'],
    ];

    protected array $optionRules = [
        'minWidth' => 'non-negative-integer',
    ];

    public function getId(): string
    {
        return 'perf-responsive-images';
    }

    public function getDescription(): string
    {
        return 'Images should use srcset for responsive loading.';
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
        /** @var int $minWidth */
        $minWidth = $this->getOption('minWidth', 300);

        /** @var array<string> $excludePatterns */
        $excludePatterns = $this->getOption('excludePatterns', []);

        $excludeClassesOption = $this->getOption('excludeClasses', []);
        $excludeClasses = array_values(array_map(
            strtolower(...),
            is_array($excludeClassesOption) ? array_filter($excludeClassesOption, is_string(...)) : [],
        ));

        $document->queryElements('img')->each(function (ElementNode $img) use ($context, $excludeClasses, $minWidth, $excludePatterns): void {
            if ($this->isInsideResponsivePicture($img)) {
                return;
            }

            if ($this->everyImageRenderPathIsSatisfied(
                $img,
                $excludeClasses,
                $minWidth,
                $excludePatterns,
            )) {
                return;
            }

            $context->report(
                $img,
                'Responsive image candidate is missing a srcset attribute.'
            );
        });
    }

    /**
     * @param  list<string>  $excludeClasses
     * @param  array<mixed>  $excludePatterns
     */
    private function everyImageRenderPathIsSatisfied(
        ElementNode $img,
        array $excludeClasses,
        int $minWidth,
        array $excludePatterns,
    ): bool {
        $ignoreExplicitClass = false;
        $firstClassDirective = $this->firstUnconditionalKnownAttributeDirective($img, 'class');
        if ($firstClassDirective !== null) {
            $classVerdict = $this->classDirectiveContainsExcludedClass(
                $firstClassDirective->arguments() ?? '',
                $excludeClasses,
            );
            if ($classVerdict === null || $classVerdict) {
                return true;
            }

            $ignoreExplicitClass = true;
        }

        $names = [
            'src', 'srcset', 'width', 'class', 'role', 'alt', 'tabindex', 'contenteditable',
            ...AriaData::globalProperties(),
        ];
        if ($this->elementHasUnmodelledAttributes($img)) {
            foreach (['src', 'srcset', 'width', 'class', 'role', 'alt'] as $name) {
                if ($this->firstAttributesOnRenderPaths($img, $name) === null) {
                    return true;
                }
            }
        }

        if ($this->attributeRenderPathsNeedIndependentConditionCorrelation($img, $names)) {
            return $this->uniformAttributeRenderPathVerdict(
                $img,
                $names,
                fn (array $path): bool => $this->imageRenderPathIsSatisfied(
                    $path,
                    $excludeClasses,
                    $minWidth,
                    $excludePatterns,
                    $ignoreExplicitClass,
                ),
            ) ?? true;
        }

        $paths = $this->explicitAttributeRenderPaths($img, $names);
        if ($paths === null) {
            return true;
        }

        foreach ($paths as $path) {
            if (! $this->imageRenderPathIsSatisfied(
                $path,
                $excludeClasses,
                $minWidth,
                $excludePatterns,
                $ignoreExplicitClass,
            )) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<Attribute>  $path
     * @param  list<string>  $excludeClasses
     * @param  array<mixed>  $excludePatterns
     */
    private function imageRenderPathIsSatisfied(
        array $path,
        array $excludeClasses,
        int $minWidth,
        array $excludePatterns,
        bool $ignoreExplicitClass = false,
    ): bool {
        $srcset = $this->firstAttributeOnRenderPath($path, 'srcset');
        if ($srcset !== null
            && ($srcset->isDynamic()
                || SrcsetParser::hasValidCandidate($srcset->decodedValueText() ?? ''))) {
            return true;
        }

        $src = $this->firstAttributeOnRenderPath($path, 'src');
        if ($src !== null && $src->isDynamic()) {
            return true;
        }

        $source = $src?->decodedValueText() ?? '';
        if (ImageSource::isSvg($source) || ImageSource::isDataUrl($source)) {
            return true;
        }

        foreach ($excludePatterns as $pattern) {
            if (is_string($pattern) && str_contains($source, $pattern)) {
                return true;
            }
        }

        $width = $this->firstAttributeOnRenderPath($path, 'width');
        $parsedWidth = $width !== null && ! $width->isDynamic()
            ? HtmlInteger::parse($width->decodedValueText() ?? '')
            : null;
        if ($parsedWidth !== null && $parsedWidth >= 0 && $parsedWidth < $minWidth) {
            return true;
        }

        $class = $ignoreExplicitClass ? null : $this->firstAttributeOnRenderPath($path, 'class');
        $classes = $class !== null && ! $class->isDynamic() ? $class->tokensLower() : [];
        if (array_intersect($classes, $excludeClasses) !== []) {
            return true;
        }

        $role = $this->effectiveRoleOnPath($path);
        if (($role === 'presentation' || $role === 'none')
            && ! $this->presentationRoleConflictsOnPath($path)) {
            return true;
        }

        $alt = $this->firstAttributeOnRenderPath($path, 'alt');

        return $alt !== null
            && ! $alt->isDynamic()
            && HtmlWhitespace::trim($alt->decodedValueText() ?? '') === '';
    }

    /**
     * Null means the directive's runtime class set is not statically knowable.
     *
     * @param  list<string>  $excludeClasses
     */
    private function classDirectiveContainsExcludedClass(string $arguments, array $excludeClasses): ?bool
    {
        $arguments = ExpressionScanner::stripOuterParentheses(trim($arguments));
        if (! str_starts_with($arguments, '[') || ! str_ends_with($arguments, ']')) {
            return null;
        }

        $body = substr($arguments, 1, -1);
        $entries = [];
        $start = 0;
        foreach (ExpressionScanner::topLevel($body) as [$character, $index, $depth]) {
            if ($character === ',' && $depth === 0) {
                $entries[] = substr($body, $start, $index - $start);
                $start = $index + 1;
            }
        }
        $entries[] = substr($body, $start);
        $sawUnknown = false;

        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            $arrow = $this->classDirectiveArrayArrowOffset($entry);
            $classExpression = $arrow === null ? $entry : substr($entry, 0, $arrow);
            if (preg_match('/^([\'\"])(?<class>(?:\\\\.|(?!\1).)*)\1$/s', trim($classExpression), $match) !== 1) {
                $sawUnknown = true;

                continue;
            }

            $condition = $arrow === null
                ? 'true'
                : strtolower(ExpressionScanner::stripOuterParentheses(trim(substr($entry, $arrow + 2))));
            if (in_array($condition, ['false', 'null', '0', '[]', 'array()'], true)) {
                continue;
            }

            $classes = HtmlWhitespace::split(strtolower($match['class']));
            if (array_intersect($classes, $excludeClasses) !== []) {
                if ($condition === '' || in_array($condition, ['true', '1'], true)) {
                    return true;
                }

                $sawUnknown = true;
            }
        }

        return $sawUnknown ? null : false;
    }

    private function classDirectiveArrayArrowOffset(string $expression): ?int
    {
        $equals = null;
        foreach (ExpressionScanner::topLevel($expression) as [$character, $index, $depth]) {
            if ($depth !== 0) {
                continue;
            }

            if ($character === '=') {
                $equals = $index;

                continue;
            }

            if ($character === '>' && $equals === $index - 1) {
                return $equals;
            }

            $equals = null;
        }

        return null;
    }

    /** @param list<Attribute> $path */
    private function effectiveRoleOnPath(array $path): ?string
    {
        $roleAttribute = $this->firstAttributeOnRenderPath($path, 'role');
        if ($roleAttribute === null || $roleAttribute->isDynamic()) {
            return null;
        }

        foreach ($roleAttribute->tokensLower() as $role) {
            if (NoInvalidRoleRule::isValidRole($role)) {
                return $role;
            }
        }

        return null;
    }

    /** @param list<Attribute> $path */
    private function presentationRoleConflictsOnPath(array $path): bool
    {
        if ($this->firstAttributeOnRenderPath($path, 'tabindex') !== null) {
            return true;
        }

        $contenteditable = $this->firstAttributeOnRenderPath($path, 'contenteditable');
        if ($contenteditable !== null
            && ($contenteditable->isDynamic()
                || in_array(strtolower($contenteditable->decodedValueText() ?? ''), ['', 'true', 'plaintext-only'], true))) {
            return true;
        }

        foreach ($path as $attribute) {
            if (in_array(strtolower($attribute->name()->rawName()), AriaData::globalProperties(), true)) {
                return true;
            }
        }

        return false;
    }

    private function hasUsableSrcset(ElementNode $element): bool
    {
        return $this->everyAttributeRenderPathIsSatisfied(
            $element,
            'srcset',
            static fn ($attribute): bool => $attribute->isDynamic()
                || SrcsetParser::hasValidCandidate($attribute->decodedValueText() ?? ''),
        );
    }

    private function isInsideResponsivePicture(ElementNode $img): bool
    {
        $picture = $this->renderedParentElement($img);

        if ($picture === null || ! $picture->isTag('picture')) {
            return false;
        }

        foreach ($this->renderedChildElements($picture) as $child) {
            if ($child->startOffset() >= $img->startOffset()) {
                break;
            }

            if ($child->isTag('source')
                && $this->hasUsableSrcset($child)) {
                return true;
            }
        }

        return false;
    }
}
