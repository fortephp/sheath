<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\BestPractices\Attributes;

use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Parsing\ExpressionScanner;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\Concerns\ReportsWithFix;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoInlineStylesRule extends AbstractRule
{
    use DetectsOpaqueAttributes;
    use ReportsWithFix;

    protected array $options = [
        'allowedProperties' => [],
    ];

    public function getId(): string
    {
        return 'best-practices-no-inline-styles';
    }

    public function getDescription(): string
    {
        return 'Disallow inline style attributes. Use CSS classes instead.';
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
        $allowedProperties = $this->getAllowedProperties();

        $context->elements()
            ->each(function (ElementNode $el) use ($context, $allowedProperties): void {
                $firstStyleDirective = $this->firstUnconditionalKnownAttributeDirective($el, 'style');
                if ($firstStyleDirective !== null) {
                    if ($this->hasDisallowedStyleDirectiveEffect(
                        [['name' => 'style', 'directive' => $firstStyleDirective]],
                        $allowedProperties,
                    )) {
                        $context->report(
                            $el,
                            'Inline style attribute is disallowed.',
                        );
                    }

                    // @style always emits the browser's first style attribute;
                    // later style providers cannot replace it.
                    return;
                }

                $styleDirectiveEffects = $this->knownAttributeDirectiveEffects($el, 'style');
                if ($this->hasDisallowedStyleDirectiveEffect($styleDirectiveEffects, $allowedProperties)) {
                    $context->report(
                        $el,
                        'Inline style attribute is disallowed.',
                    );

                    return;
                }

                if ($this->attributesInRenderStructure($el, 'style') === []) {
                    return;
                }

                $styleAttributes = $this->firstAttributesOnRenderPaths($el, 'style');
                if ($styleAttributes === null) {
                    return;
                }

                $styleAttr = null;
                $seen = [];

                foreach ($styleAttributes as $candidate) {
                    if ($candidate === null || isset($seen[spl_object_id($candidate)])) {
                        continue;
                    }
                    $seen[spl_object_id($candidate)] = true;

                    if (! $this->declaresOnlyAllowedProperties($candidate, $allowedProperties)) {
                        $styleAttr = $candidate;

                        break;
                    }
                }

                if ($styleAttr === null) {
                    return;
                }

                $context->report(
                    $el,
                    'Inline style attribute is disallowed.',
                    count($this->attributesInRenderStructure($el, 'style')) === 1
                        ? $this->createRemoveStyleFix($styleAttr)
                        : null
                );
            });
    }

    /**
     * @param  list<array{name: string, directive: DirectiveNode}>  $effects
     * @param  list<string>  $allowedProperties
     */
    private function hasDisallowedStyleDirectiveEffect(array $effects, array $allowedProperties): bool
    {
        if ($effects === []) {
            return false;
        }

        if ($allowedProperties === []) {
            return true;
        }

        foreach ($effects as $effect) {
            $arguments = $effect['directive']->arguments() ?? '';

            foreach ($this->styleDirectiveEntries($arguments) as [$literal, $condition]) {
                if ($condition !== null && $this->isConstantFalse($condition)) {
                    continue;
                }

                $property = trim(strstr($literal, ':', true) ?: '');
                $property = str_starts_with($property, '--') ? $property : strtolower($property);

                if ($property !== '' && ! in_array($property, $allowedProperties, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<array{string, string|null}> */
    private function styleDirectiveEntries(string $arguments): array
    {
        $arguments = ExpressionScanner::stripOuterParentheses(trim($arguments));

        if (str_starts_with($arguments, '[') && str_ends_with($arguments, ']')) {
            $arguments = substr($arguments, 1, -1);
        } elseif (preg_match('/^array\s*\((.*)\)$/is', $arguments, $match) === 1) {
            $arguments = $match[1];
        } else {
            return [];
        }

        $entries = [];
        $start = 0;

        foreach (ExpressionScanner::topLevel($arguments) as [$character, $index, $depth]) {
            if ($character !== ',' || $depth !== 0) {
                continue;
            }

            $entries[] = substr($arguments, $start, $index - $start);
            $start = $index + 1;
        }
        $entries[] = substr($arguments, $start);

        $styles = [];
        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            $arrow = $this->topLevelArrayArrowOffset($entry);
            $literal = $this->quotedLiteral($arrow === null ? $entry : substr($entry, 0, $arrow));
            if ($literal === null || ! str_contains($literal, ':')) {
                continue;
            }

            $styles[] = [$literal, $arrow === null ? null : trim(substr($entry, $arrow + 2))];
        }

        return $styles;
    }

    private function topLevelArrayArrowOffset(string $expression): ?int
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

    private function quotedLiteral(string $expression): ?string
    {
        $expression = trim($expression);
        if (preg_match('/^([\'\"])(?<literal>(?:\\\\.|(?!\\1).)*)\\1$/s', $expression, $match) !== 1) {
            return null;
        }

        return $match['literal'];
    }

    private function isConstantFalse(string $expression): bool
    {
        $expression = strtolower(ExpressionScanner::stripOuterParentheses(trim($expression)));

        return in_array($expression, ['false', 'null', '0', '[]', 'array()'], true);
    }

    /** @return list<string> */
    private function getAllowedProperties(): array
    {
        $option = $this->getOption('allowedProperties', []);

        $allowed = [];

        foreach (is_array($option) ? $option : [] as $property) {
            if (is_string($property)) {
                $property = trim($property);
                $allowed[] = str_starts_with($property, '--') ? $property : strtolower($property);
            }
        }

        return $allowed;
    }

    /**
     * @param  array<int, string>  $allowedProperties  Lowercase ordinary names; case-preserved custom names.
     */
    private function declaresOnlyAllowedProperties(Attribute $styleAttr, array $allowedProperties): bool
    {
        if ($allowedProperties === []) {
            return false;
        }

        if ($styleAttr->isBladeConstruct() || $styleAttr->hasComplexValue()) {
            return false;
        }

        $value = $styleAttr->decodedValueText();

        if ($value === null) {
            return false;
        }

        $declaredProperties = [];

        foreach ($this->splitDeclarations($value) as $declaration) {
            $declaration = trim($declaration);

            if ($declaration === '') {
                continue;
            }

            $separator = strstr($declaration, ':', true);
            $property = trim($separator === false ? $declaration : $separator);
            $declaredProperties[] = str_starts_with($property, '--') ? $property : strtolower($property);
        }

        if ($declaredProperties === []) {
            return false;
        }

        foreach ($declaredProperties as $property) {
            if (! in_array($property, $allowedProperties, true)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function splitDeclarations(string $value): array
    {
        $declarations = [];
        $declaration = '';
        $quote = null;
        $escaped = false;
        $inComment = false;
        $depth = 0;
        $length = strlen($value);

        for ($index = 0; $index < $length; $index++) {
            $character = $value[$index];
            $next = $index + 1 < $length ? $value[$index + 1] : null;

            if ($inComment) {
                if ($character === '*' && $next === '/') {
                    $index++;
                    $inComment = false;
                }

                continue;
            }

            if ($escaped) {
                $declaration .= $character;
                $escaped = false;

                continue;
            }

            if ($character === '\\') {
                $declaration .= $character;
                $escaped = true;

                continue;
            }

            if ($quote !== null) {
                $declaration .= $character;

                if ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if (($character === '"' || $character === "'")) {
                $declaration .= $character;
                $quote = $character;

                continue;
            }

            if ($character === '/' && $next === '*') {
                $index++;
                $inComment = true;

                continue;
            }

            if ($character === '(' || $character === '[' || $character === '{') {
                $depth++;
            } elseif (($character === ')' || $character === ']' || $character === '}') && $depth > 0) {
                $depth--;
            }

            if ($character === ';' && $depth === 0) {
                $declarations[] = $declaration;
                $declaration = '';

                continue;
            }

            $declaration .= $character;
        }

        $declarations[] = $declaration;

        return $declarations;
    }

    private function createRemoveStyleFix(Attribute $styleAttr): ?Fix
    {
        if ($styleAttr->isBladeConstruct() || $styleAttr->hasComplexValue()) {
            return null;
        }

        return $this->createRemoveAttributeFix($styleAttr);
    }
}
