<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Structure;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\Concerns\ReportsWithFix;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Support\AttributeQuoting;
use Forte\Support\ViewportValue;

/** @internal */
class NoNonScalableViewportRule extends AbstractRule
{
    use DetectsOpaqueAttributes;
    use ReportsWithFix;

    public function getId(): string
    {
        return 'a11y-no-non-scalable-viewport';
    }

    public function getDescription(): string
    {
        return 'Viewport must allow user scaling for accessibility.';
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
        $document->queryElements('meta')->each(function (ElementNode $meta) use ($context): void {
            if ($this->elementHasUnmodelledAttributes($meta)) {
                return;
            }

            $paths = $this->explicitAttributeRenderPaths($meta, ['name', 'content']);
            if ($paths === null) {
                return;
            }

            $reported = [];

            if ($this->attributeRenderPathsNeedIndependentConditionCorrelation($meta, ['name', 'content'])) {
                if ($this->uniformAttributeRenderPathVerdict(
                    $meta,
                    ['name', 'content'],
                    fn (array $path): bool => $this->viewportPathHasViolation($path),
                ) !== true) {
                    return;
                }

                foreach ($paths as $path) {
                    $content = $this->staticViewportContentOnPath($path);
                    if ($content === null
                        || ! $this->viewportContentHasViolation($content->decodedValueText() ?? '')) {
                        continue;
                    }

                    $this->checkViewportContent(
                        $meta,
                        $content,
                        $content->decodedValueText() ?? '',
                        $context,
                        $reported,
                    );
                    break;
                }

                return;
            }

            foreach ($paths as $path) {
                $content = $this->staticViewportContentOnPath($path);
                if ($content === null) {
                    continue;
                }

                $before = count($reported);
                $this->checkViewportContent(
                    $meta,
                    $content,
                    $content->decodedValueText() ?? '',
                    $context,
                    $reported,
                );
                if (count($reported) > $before) {
                    break;
                }
            }
        });
    }

    /** @param list<Attribute> $path */
    private function viewportPathHasViolation(array $path): bool
    {
        $content = $this->staticViewportContentOnPath($path);

        return $content !== null
            && $this->viewportContentHasViolation($content->decodedValueText() ?? '');
    }

    /** @param list<Attribute> $path */
    private function staticViewportContentOnPath(array $path): ?Attribute
    {
        $name = $this->firstAttributeOnRenderPath($path, 'name');
        if ($name === null || $name->isDynamic()) {
            return null;
        }

        if (strcasecmp($name->decodedValueText() ?? '', 'viewport') !== 0) {
            return null;
        }

        $content = $this->firstAttributeOnRenderPath($path, 'content');

        return $content !== null && ! $content->isDynamic() ? $content : null;
    }

    private function viewportContentHasViolation(string $content): bool
    {
        foreach ($this->viewportDirectives($content) as [$key, $value]) {
            if ($this->viewportDirectivePreventsZoom($key, $value)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, true> $reported */
    private function checkViewportContent(
        ElementNode $meta,
        Attribute $contentAttribute,
        string $content,
        RuleContext $context,
        array &$reported,
    ): void {
        $reportedIssues = [];

        foreach ($this->viewportDirectives($content) as [$key, $value]) {
            if (! $this->viewportDirectivePreventsZoom($key, $value)) {
                continue;
            }

            $reportedIssues[] = [
                'key' => $key,
                'message' => $key === 'user-scalable'
                    ? 'Viewport disables user scaling.'
                    : 'Viewport maximum-scale prevents 200% zoom.',
            ];
        }

        if ($reportedIssues === []) {
            return;
        }

        $fix = $this->createContentFix(
            $meta,
            $contentAttribute,
            $content,
            array_values(array_unique(array_column($reportedIssues, 'key')))
        );

        foreach ($reportedIssues as $issue) {
            if (isset($reported[$issue['message']])) {
                continue;
            }

            $reported[$issue['message']] = true;
            $context->report($meta, $issue['message'], $fix);
        }
    }

    /**
     * @param  array<string>  $keysToRemove
     */
    private function createContentFix(
        ElementNode $meta,
        Attribute $contentAttr,
        string $content,
        array $keysToRemove,
    ): ?Fix {
        if ($meta->attribute('content') !== $contentAttr
            || ! $contentAttr->isUnconditionallyPresent()) {
            return null;
        }

        $newContent = $content;
        $ranges = [];
        foreach ($this->viewportDirectiveMatches($content) as $directive) {
            if (in_array($directive['key'], $keysToRemove, true)) {
                $ranges[] = [$directive['start'], $directive['end']];
            }
        }

        foreach (array_reverse($ranges) as [$start, $end]) {
            $newContent = substr($newContent, 0, $start).substr($newContent, $end);
        }

        $newContent = preg_replace(
            '/(?:[ \t\n\f\r]*[,;][ \t\n\f\r]*){2,}/',
            ', ',
            $newContent,
        ) ?? $newContent;
        $newContent = preg_replace('/[ \t\n\f\r]{2,}/', ' ', $newContent) ?? $newContent;
        $newContent = trim($newContent, " \t\n\f\r,;");
        $quote = AttributeQuoting::styleOf($contentAttr);

        return $this->createReplaceAttributeFix(
            $contentAttr,
            AttributeQuoting::render('content', $newContent, $quote),
            dangerous: true,
        );
    }

    /** @return list<array{string, string}> */
    private function viewportDirectives(string $content): array
    {
        $lastValues = [];
        foreach ($this->viewportDirectiveMatches($content) as $directive) {
            $lastValues[$directive['key']] = $directive['value'];
        }

        $directives = [];
        foreach ($lastValues as $key => $value) {
            $directives[] = [$key, $value];
        }

        return $directives;
    }

    /** @return list<array{key: string, value: string, start: int, end: int}> */
    private function viewportDirectiveMatches(string $content): array
    {
        $matched = preg_match_all(
            '/(?<![A-Za-z0-9_-])([A-Za-z][A-Za-z0-9-]*)[ \t\n\f\r]*=[ \t\n\f\r]*([^ \t\n\f\r,;]+)/',
            $content,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        if ($matched === false || $matched === 0) {
            return [];
        }

        $directives = [];
        foreach ($matches as $match) {
            $raw = $match[0][0];
            $start = $match[0][1];
            $directives[] = [
                'key' => strtolower($match[1][0]),
                'value' => strtolower($match[2][0]),
                'start' => $start,
                'end' => $start + strlen($raw),
            ];
        }

        return $directives;
    }

    private function userScalableNumber(string $value): float
    {
        $number = ViewportValue::numberPrefix($value);
        if ($number !== null) {
            return $number;
        }

        return match ($value) {
            'yes' => 1.0,
            'device-width', 'device-height' => 10.0,
            default => 0.1,
        };
    }

    private function viewportDirectivePreventsZoom(string $key, string $value): bool
    {
        if ($key === 'user-scalable') {
            $number = $this->userScalableNumber($value);

            return $value === 'no' || ($number > -1.0 && $number < 1.0);
        }

        if ($key !== 'maximum-scale') {
            return false;
        }

        $number = $this->maximumScaleNumber($value);

        return $number !== null && $number >= 0.0 && $number < 2.0;
    }

    private function maximumScaleNumber(string $value): ?float
    {
        $number = ViewportValue::numberPrefix($value);
        if ($number !== null) {
            // Negative maximum-scale values are dropped by viewport parsing.
            return $number < 0.0 ? null : $number;
        }

        // CSS Device Adaptation's viewport translation maps yes to 1,
        // device dimensions to 10, and no/unknown values to 0.1.
        return match ($value) {
            'yes' => 1.0,
            'device-width', 'device-height' => 10.0,
            default => 0.1,
        };
    }
}
