<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Concerns;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\EchoNode;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Ast\PhpBlockNode;
use Forte\Ast\PhpTagNode;
use Forte\Sheath\Parsing\ExpressionScanner;
use Forte\Sheath\Support\ReactiveAttributeSemantics;

/** @internal */
trait DetectsOpaqueAttributes
{
    use ChecksRenderPathGuarantees;

    private const MAX_EXPLICIT_ATTRIBUTE_PATHS = 128;

    private const SELF_CONTAINED_ATTRIBUTE_DIRECTIVES = [
        'class',
        'style',
        'checked',
        'selected',
        'disabled',
        'readonly',
        'required',
    ];

    private const CONDITIONAL_BOOLEAN_ATTRIBUTE_DIRECTIVES = [
        'checked',
        'selected',
        'disabled',
        'readonly',
        'required',
    ];

    protected function elementHasOpaqueAttributes(ElementNode $element): bool
    {
        foreach ($this->attributeRenderItems($element) as $item) {
            if ($this->containsOpaqueAttributeProvider($item, $element)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an opening tag contains runtime attribute production that the
     * explicit render-path enumerator intentionally cannot model.
     */
    protected function elementHasUnmodelledAttributes(ElementNode $element): bool
    {
        foreach ($this->attributeRenderItems($element) as $item) {
            if ($this->containsOpaqueAttributeProvider($item, $element)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether every possible rendering either contains an acceptable
     * instance of an attribute or passes through a runtime construct that may
     * provide it.
     *
     * @param  string|list<string>  $names
     * @param  (callable(Attribute): bool)|null  $accepts
     */
    protected function everyAttributeRenderPathIsSatisfied(
        ElementNode $element,
        string|array $names,
        ?callable $accepts = null,
    ): bool {
        $names = is_array($names) ? $names : [$names];

        if ($this->elementHasUnmodelledAttributes($element)) {
            return $this->everyRenderPathContainsBefore(
                $this->attributeRenderItems($element),
                function (mixed $item) use ($element, $names, $accepts): bool {
                    if ($item instanceof Attribute) {
                        return (ReactiveAttributeSemantics::clientDirectiveRuns($item, $element)
                            && ReactiveAttributeSemantics::isOpaqueAttributeSet($item))
                            || (! $item->isBladeConstruct()
                            && $this->attributeMatchesName($item, $names, $element)
                            && (ReactiveAttributeSemantics::boundAttributeName($item) !== null
                                || $accepts === null
                                || $accepts($item)));
                    }

                    return $this->isOpaqueAttributeProvider($item, $element);
                },
                static fn (mixed $item): bool => $item instanceof Attribute
                    && ! $item->isBladeConstruct()
                    && $item->isNamed($names),
            );
        }

        $paths = $this->explicitAttributeRenderPaths($element, $names);
        if ($paths === null) {
            return $this->everyRenderPathContainsBefore(
                $this->attributeRenderItems($element),
                fn (mixed $item): bool => $item instanceof Attribute
                    && ((ReactiveAttributeSemantics::clientDirectiveRuns($item, $element)
                        && ReactiveAttributeSemantics::isOpaqueAttributeSet($item))
                        || (! $item->isBladeConstruct()
                            && $this->attributeMatchesName($item, $names, $element)
                            && (ReactiveAttributeSemantics::boundAttributeName($item) !== null
                                || $accepts === null
                                || $accepts($item)))),
                fn (mixed $item): bool => $item instanceof Attribute
                    && ! $item->isBladeConstruct()
                    && $this->attributeMatchesName($item, $names, $element),
            );
        }

        foreach ($paths as $path) {
            $satisfied = false;

            foreach ($names as $name) {
                foreach ($path as $attribute) {
                    if (! $this->attributeMatchesName($attribute, $name, $element)) {
                        continue;
                    }

                    $satisfied = $accepts === null || $accepts($attribute);
                    break;
                }

                if ($satisfied) {
                    break;
                }
            }

            if (! $satisfied) {
                return false;
            }
        }

        return true;
    }

    /**
     * Return every explicit attribute nested in the opening tag's Blade
     * control-flow structure. Mutually exclusive branches are intentionally
     * included because each attribute may be rendered.
     *
     * @param  string|list<string>|null  $names
     * @return list<Attribute>
     */
    protected function attributesInRenderStructure(ElementNode $element, string|array|null $names = null): array
    {
        $wanted = $names === null ? null : (is_array($names) ? $names : [$names]);
        $attributes = [];

        foreach ($this->attributeRenderItems($element) as $item) {
            $this->collectRenderedAttributes($item, $wanted, $attributes, $element);
        }

        return $attributes;
    }

    /**
     * Return Blade directives that are known to emit one specific HTML
     * attribute. Keeping this taxonomy beside the path engine prevents rules
     * from independently guessing whether directives are opaque providers.
     *
     * @param  string|list<string>|null  $names
     * @return list<array{name: string, directive: DirectiveNode}>
     */
    protected function knownAttributeDirectiveEffects(
        ElementNode $element,
        string|array|null $names = null,
    ): array {
        $wanted = $names === null ? null : (is_array($names) ? $names : [$names]);
        $effects = [];

        foreach ($this->attributeRenderItems($element) as $item) {
            array_push($effects, ...$this->knownAttributeDirectiveEffectsForItem($item, $wanted));
        }

        return $effects;
    }

    /**
     * Return a direct Blade directive that unconditionally emits the first
     * attribute with this name. Nested control flow is deliberately excluded:
     * consumers must analyze those directives per render path.
     */
    protected function firstUnconditionalKnownAttributeDirective(
        ElementNode $element,
        string $name,
    ): ?DirectiveNode {
        foreach ($this->attributeRenderItems($element) as $item) {
            if ($item instanceof Attribute) {
                if (! $item->isBladeConstruct() && $this->attributeMatchesName($item, $name, $element)) {
                    return null;
                }

                continue;
            }

            if ($item instanceof DirectiveNode) {
                if ($this->isOpaqueAttributeProvider($item, $element)) {
                    return null;
                }

                $directiveName = strtolower($item->nameText());
                if ($directiveName === $name
                    && in_array($directiveName, self::SELF_CONTAINED_ATTRIBUTE_DIRECTIVES, true)) {
                    return $item;
                }

                continue;
            }

            if ($this->containsOpaqueAttributeProvider($item, $element)) {
                return null;
            }

            if ($this->renderedAttributesForItem($item, [$name], $element) !== []
                || ($item instanceof Node
                    && $this->knownAttributeDirectiveEffectsForItem($item, [$name]) !== [])) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $wanted
     * @return list<Attribute>
     */
    private function renderedAttributesForItem(mixed $item, array $wanted, ElementNode $element): array
    {
        $attributes = [];
        $this->collectRenderedAttributes($item, $wanted, $attributes, $element);

        return $attributes;
    }

    /**
     * @param  list<string>|null  $wanted
     * @return list<array{name: string, directive: DirectiveNode}>
     */
    private function knownAttributeDirectiveEffectsForItem(mixed $item, ?array $wanted): array
    {
        $effects = [];

        if ($item instanceof DirectiveNode) {
            $name = strtolower($item->nameText());
            $attributeName = in_array($name, self::SELF_CONTAINED_ATTRIBUTE_DIRECTIVES, true) ? $name : null;
            $arguments = ExpressionScanner::stripOuterParentheses(trim($item->arguments() ?? ''));
            $isConstantFalseBoolean = in_array($name, self::CONDITIONAL_BOOLEAN_ATTRIBUTE_DIRECTIVES, true)
                && in_array(strtolower($arguments), ['false', 'null', '0', '[]', 'array()'], true);
            if ($attributeName !== null
                && ! $isConstantFalseBoolean
                && ($wanted === null || in_array($attributeName, $wanted, true))) {
                $effects[] = ['name' => $attributeName, 'directive' => $item];
            }
        }

        if (! $item instanceof Node) {
            return $effects;
        }

        foreach ($item->children() as $child) {
            array_push($effects, ...$this->knownAttributeDirectiveEffectsForItem($child, $wanted));
        }

        return $effects;
    }

    protected function attributeMayHaveNonEmptyValue(Attribute $attribute): bool
    {
        if (! $attribute->isUnconditionallyPresent()) {
            return true;
        }

        $value = $attribute->hasComplexValue()
            ? $attribute->valueText()
            : $attribute->decodedValueText();

        return trim($value ?? '') !== '';
    }

    /**
     * Enumerate explicit attributes on each known opening-tag render path.
     * Opaque runtime providers are omitted because they cannot prove a
     * duplicate. Specific Alpine and Livewire bindings remain in the path as
     * explicit attributes whose values are dynamic.
     *
     * @param  string|list<string>|null  $names
     * @return list<list<Attribute>>|null Null when path expansion is deliberately bounded.
     */
    protected function explicitAttributeRenderPaths(
        ElementNode $element,
        string|array|null $names = null,
        bool $retainSpecificBindings = false,
    ): ?array {
        $wanted = $names === null ? null : (is_array($names) ? $names : [$names]);
        $expandedWanted = $wanted;

        if ($expandedWanted !== null) {
            foreach ($this->attributesInRenderStructure($element) as $attribute) {
                $boundName = ReactiveAttributeSemantics::boundAttributeName($attribute);
                if ($boundName !== null && in_array($boundName, $wanted, true)) {
                    if (! $retainSpecificBindings) {
                        return null;
                    }

                    $expandedWanted[] = strtolower($attribute->name()->rawName());
                }
            }

            $expandedWanted = array_values(array_unique($expandedWanted));
        }

        $paths = $this->expandAttributeSequence($this->attributeRenderItems($element), [[]], $expandedWanted);
        if ($paths === null) {
            return null;
        }

        foreach ($paths as &$path) {
            $path = array_values(array_filter(
                $path,
                static fn (Attribute $attribute): bool => ! ReactiveAttributeSemantics::isClientDirective($attribute)
                    || ReactiveAttributeSemantics::clientDirectiveRuns($attribute, $element),
            ));
        }
        unset($path);

        return $paths;
    }

    /**
     * Whether exact analysis of the requested attributes would require
     * correlating separate top-level Blade conditionals. The AST preserves
     * control flow within each block, but does not establish that predicates
     * in independent blocks are equivalent or complementary.
     *
     * @param  string|list<string>  $names
     */
    protected function attributeRenderPathsNeedIndependentConditionCorrelation(
        ElementNode $element,
        string|array $names,
    ): bool {
        $wanted = is_array($names) ? $names : [$names];
        $relevant = 0;
        $hasUnknownPredicate = false;

        foreach ($this->attributeRenderItems($element) as $item) {
            if (! $item instanceof DirectiveBlockNode) {
                continue;
            }
            $attributes = [];
            $this->collectRenderedAttributes($item, $wanted, $attributes, $element);
            if ($attributes === []) {
                continue;
            }
            $relevant++;
            $start = $item->startDirective();
            if ($start === null || $this->atomicAttributePredicate($start, true) === null) {
                $hasUnknownPredicate = true;
            }
        }

        return $relevant > 1 && $hasUnknownPredicate;
    }

    /**
     * @param  string|list<string>  $names
     * @return list<int>
     */
    protected function relevantConditionalAttributeGroupIds(
        ElementNode $element,
        string|array $names,
    ): array {
        $wanted = is_array($names) ? $names : [$names];
        $groups = [];

        foreach ($this->attributeRenderItems($element) as $item) {
            if (! $item instanceof DirectiveBlockNode) {
                continue;
            }

            if (! $this->attributeBlockAffectsNamesOrFlow($item, $wanted, $element)) {
                continue;
            }

            $attributes = [];
            $this->collectRenderedAttributes($item, $wanted, $attributes, $element);
            if ($attributes === []) {
                continue;
            }

            $groups[] = spl_object_id($item);
        }

        return $groups;
    }

    protected function conditionalBranchProvidingAttribute(
        ElementNode $element,
        Attribute $target,
    ): ?DirectiveNode {
        foreach ($this->attributeRenderItems($element) as $item) {
            if (! $item instanceof DirectiveBlockNode) {
                continue;
            }

            $branches = array_filter([
                $item->startDirective(),
                ...iterator_to_array($item->intermediateDirectives()),
            ]);

            foreach ($branches as $branch) {
                $attributes = [];
                $this->collectRenderedAttributes($branch, null, $attributes, $element);

                foreach ($attributes as $attribute) {
                    if ($attribute->startOffset() === $target->startOffset()) {
                        return $branch;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Return a verdict only when every enumerated path agrees. A null result
     * means predicate correlation can change the verdict.
     *
     * @param  string|list<string>  $names
     * @param  callable(list<Attribute>): bool  $verdict
     */
    protected function uniformAttributeRenderPathVerdict(
        ElementNode $element,
        string|array $names,
        callable $verdict,
    ): ?bool {
        $paths = $this->explicitAttributeRenderPaths($element, $names);
        if ($paths === null || $paths === []) {
            return null;
        }

        $uniform = $verdict($paths[0]);

        foreach (array_slice($paths, 1) as $path) {
            if ($verdict($path) !== $uniform) {
                return null;
            }
        }

        return $uniform;
    }

    /**
     * Return the first matching attribute the browser keeps on each explicit
     * render path. HTML ignores later attributes with the same name.
     *
     * @return list<Attribute|null>|null Null when runtime providers or the path bound prevent exact analysis.
     */
    protected function firstAttributesOnRenderPaths(ElementNode $element, string $name): ?array
    {
        $paths = $this->explicitAttributeRenderPaths($element, $name);
        if ($paths === null) {
            return null;
        }

        $firstAttributes = [];

        $opaqueOffsets = $this->opaqueAttributeProviderOffsets($element);

        foreach ($paths as $path) {
            $first = null;

            foreach ($path as $attribute) {
                if ($this->attributeMatchesName($attribute, $name, $element)) {
                    $first = $attribute;
                    break;
                }
            }

            if ($opaqueOffsets !== []
                && ($first === null || min($opaqueOffsets) < $first->startOffset())) {
                return null;
            }

            $firstAttributes[] = $first;
        }

        return $firstAttributes;
    }

    /**
     * Return the first browser-effective attribute for several names while
     * expanding the opening tag's render paths only once.
     *
     * A null value for one name means an opaque provider prevents an exact
     * answer for that name. A null return means the combined path expansion
     * exceeded its bound, so callers can fall back to independent lookups.
     *
     * @param  list<string>  $names
     * @return array<string, list<Attribute|null>|null>|null
     */
    protected function firstAttributesByNameOnRenderPaths(ElementNode $element, array $names): ?array
    {
        $paths = $this->explicitAttributeRenderPaths($element, $names);
        if ($paths === null) {
            return null;
        }

        $results = array_fill_keys($names, []);
        $unknown = [];
        $opaqueOffsets = $this->opaqueAttributeProviderOffsets($element);
        $firstOpaqueOffset = $opaqueOffsets === [] ? null : min($opaqueOffsets);

        foreach ($paths as $path) {
            foreach ($names as $name) {
                if (isset($unknown[$name])) {
                    continue;
                }

                $first = null;
                foreach ($path as $attribute) {
                    if ($this->attributeMatchesName($attribute, $name, $element)) {
                        $first = $attribute;
                        break;
                    }
                }

                if ($firstOpaqueOffset !== null
                    && ($first === null || $firstOpaqueOffset < $first->startOffset())) {
                    $results[$name] = null;
                    $unknown[$name] = true;

                    continue;
                }

                $results[$name][] = $first;
            }
        }

        return $results;
    }

    /**
     * Return the first matching attribute on one explicit render path. HTML
     * ignores later attributes with the same name.
     *
     * @param  list<Attribute>  $path
     */
    protected function firstAttributeOnRenderPath(array $path, string $name): ?Attribute
    {
        foreach ($path as $attribute) {
            if ($this->attributeMatchesName($attribute, $name)) {
                return $attribute;
            }
        }

        return null;
    }

    /** @return iterable<mixed> */
    private function attributeRenderItems(ElementNode $element): iterable
    {
        foreach ($element->attributes() as $attribute) {
            if (! $attribute->isBladeConstruct()) {
                if (ReactiveAttributeSemantics::isClientDirective($attribute)
                    && ! ReactiveAttributeSemantics::clientDirectiveRuns($attribute, $element)) {
                    continue;
                }

                yield $attribute;

                continue;
            }

            $construct = $attribute->getBladeConstruct();

            if ($construct !== null) {
                yield $construct;
            }
        }
    }

    private function isOpaqueAttributeProvider(mixed $item, ?ElementNode $element = null): bool
    {
        if ($item instanceof Attribute) {
            return ! $item->isBladeConstruct()
                && ($element === null || ReactiveAttributeSemantics::clientDirectiveRuns($item, $element))
                && ReactiveAttributeSemantics::isOpaqueAttributeSet($item);
        }

        if ($item instanceof EchoNode || $item instanceof PhpBlockNode || $item instanceof PhpTagNode) {
            return true;
        }

        if ($item instanceof DirectiveBlockNode) {
            return in_array(strtolower($item->nameText()), ['php', 'verbatim'], true);
        }

        if (! $item instanceof DirectiveNode) {
            return false;
        }

        $name = strtolower($item->nameText());

        return ! in_array($name, [
            ...self::SELF_CONTAINED_ATTRIBUTE_DIRECTIVES,
            'if', 'elseif', 'else', 'endif', 'unless', 'endunless',
            'foreach', 'endforeach', 'forelse', 'empty', 'endforelse',
            'for', 'endfor', 'while', 'endwhile',
            'switch', 'case', 'default', 'break', 'continue', 'endswitch',
            'csrf', 'method',
        ], true);
    }

    /** @return list<int> */
    private function opaqueAttributeProviderOffsets(ElementNode $element): array
    {
        $offsets = [];

        foreach ($this->attributeRenderItems($element) as $item) {
            $this->collectOpaqueAttributeProviderOffsets($item, $offsets, $element);
        }

        return $offsets;
    }

    /** @param list<int> $offsets */
    private function collectOpaqueAttributeProviderOffsets(mixed $item, array &$offsets, ElementNode $element): void
    {
        if ($this->isOpaqueAttributeProvider($item, $element)) {
            if ($item instanceof Attribute
                && ReactiveAttributeSemantics::isOpaqueAttributeSet($item)) {
                // Alpine applies object bindings after parsing the element, so
                // they may replace an explicit attribute on either side of the
                // directive. HTML's source-order first-write rule does not
                // apply to this client-side mutation.
                $offsets[] = -1;

                return;
            }

            if ($item instanceof Node && $item->startOffset() >= 0) {
                $offsets[] = $item->startOffset();
            }

            return;
        }

        if (! $item instanceof Node) {
            return;
        }

        foreach ($item->children() as $child) {
            $this->collectOpaqueAttributeProviderOffsets($child, $offsets, $element);
        }
    }

    private function containsOpaqueAttributeProvider(mixed $item, ?ElementNode $element = null): bool
    {
        if ($item instanceof DirectiveBlockNode) {
            if (in_array(strtolower($item->nameText()), ['php', 'verbatim'], true)) {
                return true;
            }

            $start = $item->startDirective();
            if ($start !== null) {
                foreach ($start->children() as $child) {
                    if ($this->containsOpaqueAttributeProvider($child, $element)) {
                        return true;
                    }
                }
            }

            foreach ($item->intermediateDirectives() as $branch) {
                foreach ($branch->children() as $child) {
                    if ($this->containsOpaqueAttributeProvider($child, $element)) {
                        return true;
                    }
                }
            }

            return false;
        }

        if ($this->isOpaqueAttributeProvider($item, $element)) {
            return true;
        }

        if (! $item instanceof Node) {
            return false;
        }

        foreach ($item->children() as $child) {
            if ($this->containsOpaqueAttributeProvider($child, $element)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>|null  $wanted
     * @param  list<Attribute>  $attributes
     */
    private function collectRenderedAttributes(
        mixed $item,
        ?array $wanted,
        array &$attributes,
        ?ElementNode $element = null,
    ): void {
        if ($item instanceof Attribute) {
            if ($this->attributeRendersAndMatches($item, $wanted, $element)) {
                $attributes[] = $item;
            }

            return;
        }

        if ($item instanceof DirectiveBlockNode) {
            $start = $item->startDirective();

            if ($start !== null) {
                foreach ($start->children() as $child) {
                    $this->collectRenderedAttributes($child, $wanted, $attributes, $element);
                }
            }

            foreach ($item->intermediateDirectives() as $branch) {
                foreach ($branch->children() as $child) {
                    $this->collectRenderedAttributes($child, $wanted, $attributes, $element);
                }
            }

            return;
        }

        if ($item instanceof Node) {
            foreach ($item->children() as $child) {
                $this->collectRenderedAttributes($child, $wanted, $attributes, $element);
            }
        }
    }

    /** @param list<string>|null $wanted */
    private function attributeRendersAndMatches(
        Attribute $attribute,
        ?array $wanted,
        ?ElementNode $element,
    ): bool {
        if ($attribute->isBladeConstruct()) {
            return false;
        }

        if ($element !== null
            && ReactiveAttributeSemantics::isClientDirective($attribute)
            && ! ReactiveAttributeSemantics::clientDirectiveRuns($attribute, $element)) {
            return false;
        }

        return $wanted === null || $this->attributeMatchesName($attribute, $wanted, $element);
    }

    /** @param string|list<string> $names */
    protected function attributeMatchesName(
        Attribute $attribute,
        string|array $names,
        ?ElementNode $element = null,
    ): bool {
        if ($element !== null
            && ReactiveAttributeSemantics::isClientDirective($attribute)
            && ! ReactiveAttributeSemantics::clientDirectiveRuns($attribute, $element)) {
            return false;
        }

        $names = is_array($names) ? $names : [$names];
        if ($attribute->isNamed($names)) {
            return true;
        }

        $boundName = ReactiveAttributeSemantics::boundAttributeName($attribute);

        return $boundName !== null && in_array($boundName, $names, true);
    }

    protected function attributeValueIsDynamic(Attribute $attribute): bool
    {
        return ReactiveAttributeSemantics::valueIsDynamic($attribute);
    }

    /**
     * @param  iterable<mixed>  $items
     * @param  list<list<Attribute>>  $paths
     * @param  list<string>|null  $wanted
     * @return list<list<Attribute>>|null
     */
    private function expandAttributeSequence(iterable $items, array $paths, ?array $wanted = null): ?array
    {
        $states = [];
        foreach ($paths as $path) {
            $states[] = [
                'attributes' => $path,
                'predicates' => [],
                'flow' => null,
                'level' => 0,
            ];
        }
        $states = $this->expandAttributeStateSequence($items, $states, $wanted);

        if ($states === null) {
            return null;
        }

        return $this->uniqueAttributePaths(array_map(
            static fn (array $state): array => $state['attributes'],
            $states,
        ));
    }

    /**
     * @param  iterable<mixed>  $items
     * @param  list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>  $states
     * @param  list<string>|null  $wanted
     * @return list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>|null
     */
    private function expandAttributeStateSequence(iterable $items, array $states, ?array $wanted): ?array
    {
        foreach ($items as $item) {
            if ($item instanceof Attribute) {
                if (! $item->isBladeConstruct() && ($wanted === null || $item->isNamed($wanted))) {
                    foreach ($states as &$state) {
                        if ($state['flow'] === null) {
                            $state['attributes'][] = $item;
                        }
                    }
                    unset($state);
                }

                continue;
            }

            if ($item instanceof DirectiveNode) {
                $states = $this->applyAttributeExit($item, $states);

                continue;
            }

            if (! $item instanceof DirectiveBlockNode) {
                continue;
            }

            if ($wanted !== null && ! $this->attributeBlockAffectsNamesOrFlow($item, $wanted)) {
                continue;
            }

            $start = $item->startDirective();
            if ($start === null) {
                continue;
            }

            $name = strtolower($item->nameText());
            if (in_array($name, ['section', 'push', 'pushif', 'pushonce', 'prepend', 'prependonce'], true)
                && ! ($name === 'section' && $this->directiveBlockHasIntermediate($item, 'show'))) {
                continue;
            }

            if (in_array($name, ['foreach', 'for', 'while'], true)) {
                $states = $this->expandAttributeLoop($start, $states, $wanted);
            } elseif ($name === 'forelse') {
                $states = $this->expandAttributeForelse($item, $start, $states, $wanted);
            } elseif ($name === 'switch') {
                $states = $this->expandAttributeSwitchStates($start, $states, $wanted);
            } elseif ($name === 'section' && $this->directiveBlockHasIntermediate($item, 'show')) {
                $states = $this->expandAttributeStateSequence($start->children(), $states, $wanted);
            } else {
                $states = $this->expandAttributeBranches($item, $start, $states, $wanted);
            }

            if ($states === null) {
                return null;
            }

            $states = $this->uniqueAttributeStates($states);
            if (count($states) > self::MAX_EXPLICIT_ATTRIBUTE_PATHS) {
                return null;
            }
        }

        return $states;
    }

    /** @param list<string> $wanted */
    private function attributeBlockAffectsNamesOrFlow(
        DirectiveBlockNode $block,
        array $wanted,
        ?ElementNode $element = null,
    ): bool {
        $attributes = [];
        $this->collectRenderedAttributes($block, $wanted, $attributes, $element);
        if ($attributes !== []) {
            return true;
        }

        $stack = [$block];
        while ($stack !== []) {
            $node = array_pop($stack);
            if ($node instanceof DirectiveNode
                && in_array(strtolower($node->nameText()), ['break', 'continue'], true)) {
                return true;
            }
            if ($node instanceof Node) {
                foreach ($node->children() as $child) {
                    if ($child instanceof Node) {
                        $stack[] = $child;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param  list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>  $states
     * @return list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>
     */
    private function applyAttributeExit(DirectiveNode $directive, array $states): array
    {
        $name = strtolower($directive->nameText());
        if (! in_array($name, ['break', 'continue'], true)) {
            return $states;
        }

        $arguments = trim($directive->arguments() ?? '');
        $level = ctype_digit($arguments) && (int) $arguments > 0 ? (int) $arguments : 1;
        $conditional = $arguments !== '' && ! ctype_digit($arguments);
        $result = $conditional ? $states : [];

        foreach ($states as $state) {
            if ($state['flow'] !== null) {
                if (! $conditional) {
                    $result[] = $state;
                }

                continue;
            }

            $state['flow'] = $name;
            $state['level'] = $level;
            $result[] = $state;
        }

        return $result;
    }

    /**
     * @param  list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>  $states
     * @param  list<string>|null  $wanted
     * @return list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>|null
     */
    private function expandAttributeLoop(DirectiveNode $start, array $states, ?array $wanted): ?array
    {
        $one = $this->expandAttributeStateSequence($start->children(), $states, $wanted);
        if ($one === null) {
            return null;
        }

        $eligible = array_values(array_filter($one, static fn (array $state): bool => $state['flow'] === null || ($state['flow'] === 'continue' && $state['level'] === 1)));
        foreach ($eligible as &$state) {
            $state['flow'] = null;
            $state['level'] = 0;
        }
        unset($state);
        $two = $this->expandAttributeStateSequence($start->children(), $eligible, $wanted);
        if ($two === null) {
            return null;
        }

        return [...$states, ...$this->consumeAttributeLoopExits($one), ...$this->consumeAttributeLoopExits($two)];
    }

    /**
     * @param  list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>  $states
     * @param  list<string>|null  $wanted
     * @return list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>|null
     */
    private function expandAttributeForelse(DirectiveBlockNode $block, DirectiveNode $start, array $states, ?array $wanted): ?array
    {
        $nonEmpty = $this->expandAttributeLoop($start, $states, $wanted);
        if ($nonEmpty === null) {
            return null;
        }

        $expanded = array_slice($nonEmpty, count($states));
        $hasEmpty = false;
        foreach ($block->intermediateDirectives() as $branch) {
            if (strtolower($branch->nameText()) !== 'empty') {
                continue;
            }
            $hasEmpty = true;
            $empty = $this->expandAttributeStateSequence($branch->children(), $states, $wanted);
            if ($empty === null) {
                return null;
            }
            array_push($expanded, ...$empty);
        }

        return $hasEmpty ? $expanded : [...$expanded, ...$states];
    }

    /**
     * @param  list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>  $states
     * @return list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>
     */
    private function consumeAttributeLoopExits(array $states): array
    {
        foreach ($states as &$state) {
            if ($state['flow'] === null) {
                continue;
            }
            if ($state['level'] <= 1) {
                $state['flow'] = null;
                $state['level'] = 0;
            } else {
                $state['level']--;
            }
        }
        unset($state);

        return $states;
    }

    /**
     * @param  list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>  $states
     * @param  list<string>|null  $wanted
     * @return list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>|null
     */
    private function expandAttributeBranches(DirectiveBlockNode $block, DirectiveNode $start, array $states, ?array $wanted): ?array
    {
        $branches = [$start, ...iterator_to_array($block->intermediateDirectives())];
        $expanded = [];
        $hasFallback = false;

        foreach ($branches as $branch) {
            $branchName = strtolower($branch->nameText());
            $hasFallback = $hasFallback || in_array($branchName, ['else', 'empty', 'default'], true);
            $branchStates = $this->constrainAttributeStates($states, $this->attributeBranchPredicate($block, $branch));
            $branchStates = $this->expandAttributeStateSequence($branch->children(), $branchStates, $wanted);
            if ($branchStates === null) {
                return null;
            }
            array_push($expanded, ...$branchStates);
        }

        if (! $hasFallback) {
            array_push($expanded, ...$this->constrainAttributeStates($states, $this->attributeMissingBranchPredicate($block)));
        }

        return $expanded;
    }

    /** @return array{expression: string, when: bool}|null */
    private function attributeBranchPredicate(DirectiveBlockNode $block, DirectiveNode $branch): ?array
    {
        $intermediates = iterator_to_array($block->intermediateDirectives());
        if ($branch->isOpening()) {
            return $this->atomicAttributePredicate($branch, true);
        }
        if (strtolower($branch->nameText()) === 'else' && count($intermediates) === 1) {
            $start = $block->startDirective();

            return $start === null ? null : $this->atomicAttributePredicate($start, false);
        }

        return null;
    }

    /** @return array{expression: string, when: bool}|null */
    private function attributeMissingBranchPredicate(DirectiveBlockNode $block): ?array
    {
        $start = $block->startDirective();

        return $start === null ? null : $this->atomicAttributePredicate($start, false);
    }

    /** @return array{expression: string, when: bool}|null */
    private function atomicAttributePredicate(DirectiveNode $directive, bool $opening): ?array
    {
        $name = strtolower($directive->nameText());
        if (! in_array($name, ['if', 'unless'], true) || $directive->arguments() === null) {
            return null;
        }
        $expression = ExpressionScanner::stripOuterParentheses(trim($directive->arguments()));
        $when = ($name === 'if') === $opening;
        if (preg_match('/^!\s*(\$[A-Za-z_][A-Za-z0-9_]*)$/', $expression, $matches) === 1) {
            return ['expression' => $matches[1], 'when' => ! $when];
        }
        if (preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $expression) !== 1) {
            return null;
        }

        return ['expression' => $expression, 'when' => $when];
    }

    /**
     * @param  list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>  $states
     * @param  array{expression: string, when: bool}|null  $predicate
     * @return list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>
     */
    private function constrainAttributeStates(array $states, ?array $predicate): array
    {
        if ($predicate === null) {
            return $states;
        }
        $result = [];
        foreach ($states as $state) {
            $existing = $state['predicates'][$predicate['expression']] ?? null;
            if ($existing !== null && $existing !== $predicate['when']) {
                continue;
            }
            $state['predicates'][$predicate['expression']] = $predicate['when'];
            $result[] = $state;
        }

        return $result;
    }

    private function directiveBlockHasIntermediate(DirectiveBlockNode $block, string $name): bool
    {
        if ($block->endDirective()?->isDirectiveNamed($name) ?? false) {
            return true;
        }

        foreach ($block->intermediateDirectives() as $directive) {
            if (strtolower($directive->nameText()) === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>  $states
     * @param  list<string>|null  $wanted
     * @return list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>|null
     */
    private function expandAttributeSwitchStates(DirectiveNode $switch, array $states, ?array $wanted = null): ?array
    {
        $branches = [];
        $hasDefault = false;

        foreach ($switch->children() as $child) {
            if (! $child instanceof DirectiveNode
                || ! in_array(strtolower($child->nameText()), ['case', 'default'], true)) {
                continue;
            }

            $branches[] = $child;
            $hasDefault = $hasDefault || strtolower($child->nameText()) === 'default';
        }

        $expanded = $hasDefault ? [] : $states;

        foreach (array_keys($branches) as $entry) {
            $branchStates = $states;
            $completedStates = [];

            for ($index = $entry; $index < count($branches); $index++) {
                $branchStates = $this->expandAttributeStateSequence($branches[$index]->children(), $branchStates, $wanted);
                if ($branchStates === null) {
                    return null;
                }

                $continuing = [];
                foreach ($branchStates as $state) {
                    if ($state['flow'] === 'break') {
                        if ($state['level'] <= 1) {
                            $state['flow'] = null;
                            $state['level'] = 0;
                        } else {
                            $state['level']--;
                        }
                        $completedStates[] = $state;
                    } elseif ($state['flow'] === null) {
                        $continuing[] = $state;
                    } else {
                        $completedStates[] = $state;
                    }
                }
                $branchStates = $continuing;
                if ($branchStates === []) {
                    break;
                }
            }

            array_push($expanded, ...$completedStates, ...$branchStates);
        }

        return $expanded;
    }

    /**
     * @param  list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>  $states
     * @return list<array{attributes: list<Attribute>, predicates: array<string, bool>, flow: string|null, level: int}>
     */
    private function uniqueAttributeStates(array $states): array
    {
        $unique = [];

        foreach ($states as $state) {
            ksort($state['predicates']);
            $attributeKey = implode(',', array_map(static fn (Attribute $attribute): int => $attribute->startOffset(), $state['attributes']));
            $predicateKey = implode(',', array_map(
                static fn (string $name, bool $when): string => $name.'='.($when ? '1' : '0'),
                array_keys($state['predicates']),
                array_values($state['predicates']),
            ));
            $key = $attributeKey.'|'.$predicateKey.'|'.($state['flow'] ?? '').'|'.$state['level'];
            $unique[$key] = $state;
        }

        return array_values($unique);
    }

    /**
     * @param  list<list<Attribute>>  $paths
     * @return list<list<Attribute>>
     */
    private function uniqueAttributePaths(array $paths): array
    {
        $unique = [];

        foreach ($paths as $path) {
            $key = implode(',', array_map(static fn (Attribute $attribute): int => $attribute->startOffset(), $path));
            $unique[$key] = $path;
        }

        return array_values($unique);
    }
}
