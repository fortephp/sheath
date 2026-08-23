<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Reactive;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\DirectiveModifierGrammar;
use Forte\Sheath\Support\ReactiveAttributeSemantics;

/** @internal */
final class LivewireDirectiveIntegrityRule extends AbstractReactiveRule
{
    private const MODEL_MODIFIERS = [
        'alt', 'async', 'away', 'blur', 'boolean', 'capture', 'change', 'cmd', 'ctrl', 'debounce', 'deep', 'defer',
        'document', 'dot', 'enter', 'fill', 'lazy', 'live', 'meta', 'number', 'once', 'outside', 'parent', 'passive',
        'preserve-scroll', 'prevent', 'renderless', 'self', 'shift', 'stop', 'super', 'throttle', 'trim',
        'unintrusive', 'window',
    ];

    private const MODEL_SYSTEM_KEY_MODIFIERS = ['alt', 'cmd', 'ctrl', 'meta', 'shift', 'super'];

    private const ACTION_MODIFIERS = ['async', 'preserve-scroll', 'renderless'];

    private const TOGGLE_MODIFIERS = [
        'attr', 'block', 'class', 'flex', 'grid', 'inline', 'inline-flex', 'list-item', 'remove', 'table',
    ];

    private const LOADING_DELAY_MODIFIERS = [
        'default', 'long', 'longer', 'longest', 'none', 'short', 'shorter', 'shortest',
    ];

    private const NON_ACTIONS = [
        'bind', 'cloak', 'confirm', 'current', 'data', 'dirty', 'effects', 'id', 'ignore', 'init', 'intersect',
        'island', 'key', 'loading', 'model', 'navigate', 'offline', 'poll', 'ref', 'replace', 'show', 'snapshot',
        'sort', 'stream', 'target', 'text', 'transition',
    ];

    public function getId(): string
    {
        return 'blade-livewire-directive-integrity';
    }

    public function getDescription(): string
    {
        return 'Livewire directives must use valid targets, values, and modifiers.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void
    {
        if (! ReactiveAttributeSemantics::livewireCompilerIsInstalled()) {
            return;
        }

        $reported = [];

        foreach ($this->clientElementsWithDirectives($context) as $element) {
            $this->reportIntrinsicProblems($element, $document, $context, $reported);
            $this->reportPathProblems($element, $document, $context, $reported);
        }
    }

    /** @param array<string, true> $reported */
    private function reportIntrinsicProblems(
        ElementNode $element,
        Document $document,
        RuleContext $context,
        array &$reported,
    ): void {
        foreach ($this->attributesInRenderStructure($element) as $attribute) {
            if ($attribute->isBladeConstruct()
                || ! ReactiveAttributeSemantics::clientDirectiveRuns($attribute, $element)
                || $attribute->hasComplexValue()) {
                continue;
            }

            $value = trim($attribute->decodedValueText() ?? '');
            $directiveName = ReactiveAttributeSemantics::directiveName($attribute);

            if (str_starts_with($directiveName, 'wire:intersect:')
                && ! in_array($directiveName, ['wire:intersect:enter', 'wire:intersect:leave'], true)) {
                $this->reportOnce(
                    $document,
                    $attribute,
                    $context,
                    $reported,
                    'intersect-event',
                    'wire:intersect supports only :enter and :leave.',
                );
            }

            if ($directiveName === 'wire:bind') {
                $this->reportOnce(
                    $document,
                    $attribute,
                    $context,
                    $reported,
                    'bind-target',
                    'wire:bind requires an attribute target, such as wire:bind:title.',
                );
            }

            if ($this->isDirectiveFamily($attribute, 'wire:intersect') && $value === '') {
                $this->reportOnce(
                    $document,
                    $attribute,
                    $context,
                    $reported,
                    'intersect-action',
                    'wire:intersect requires an action.',
                );
            }

            if (str_starts_with($directiveName, 'wire:sort:')
                && ! in_array($directiveName, [
                    'wire:sort:config', 'wire:sort:group', 'wire:sort:group-id', 'wire:sort:handle', 'wire:sort:ignore',
                    'wire:sort:item',
                ], true)) {
                $this->reportOnce(
                    $document,
                    $attribute,
                    $context,
                    $reported,
                    'sort-variant',
                    'This wire:sort variant is not supported.',
                );
            }
            if ($this->isDirective($attribute, 'wire:model') && $value === '') {
                $this->reportOnce(
                    $document,
                    $attribute,
                    $context,
                    $reported,
                    'model-value',
                    'wire:model requires a property expression.',
                );
            }

            if ($this->isDirective($attribute, 'wire:model')) {
                $problem = $this->modelModifierProblem($attribute);
                if ($problem !== null) {
                    $this->reportOnce(
                        $document,
                        $attribute,
                        $context,
                        $reported,
                        'model-modifier',
                        $problem,
                    );
                }
            }

            $modifierContract = $this->modifierContract($attribute);
            if ($modifierContract !== null) {
                [$allowed, $directive] = $modifierContract;
                $unsupported = DirectiveModifierGrammar::firstUnsupported($attribute, $allowed);
                if ($unsupported !== null) {
                    $this->reportOnce(
                        $document,
                        $attribute,
                        $context,
                        $reported,
                        'modifier',
                        "Unsupported .{$unsupported} modifier on {$directive}.",
                    );
                }
            }

            $this->reportModifierCombinationProblems($attribute, $document, $context, $reported);

            if (($this->isDirective($attribute, 'wire:key') || $this->isDirective($attribute, 'wire:sort:item'))
                && $value === '') {
                $this->reportOnce(
                    $document,
                    $attribute,
                    $context,
                    $reported,
                    'identity-value',
                    'Livewire identity cannot be empty.',
                );
            }

            if ($this->isDirective($attribute, 'wire:target') && $value === '') {
                $this->reportOnce(
                    $document,
                    $attribute,
                    $context,
                    $reported,
                    'target-value',
                    'wire:target requires at least one action or property name.',
                );
            }

            if ($this->isDirective($attribute, 'wire:sort')
                && ! $this->isDirective($attribute, 'wire:sort:item')
                && ! $this->isDirective($attribute, 'wire:sort:group')
                && $value === '') {
                $this->reportOnce(
                    $document,
                    $attribute,
                    $context,
                    $reported,
                    'sort-action',
                    'wire:sort requires an action to persist the new item order.',
                );
            }

            if ($this->isDirective($attribute, 'wire:sort:item')
                && ! $this->hasPossibleSortAncestor($element)) {
                $this->reportOnce(
                    $document,
                    $attribute,
                    $context,
                    $reported,
                    'sort-parent',
                    'wire:sort:item requires a sortable ancestor.',
                );
            }
        }
    }

    /** @return array{list<string>, string}|null */
    private function modifierContract(Attribute $attribute): ?array
    {
        return match (true) {
            $this->isDirectiveFamily($attribute, 'wire:bind') => [
                ReactiveAttributeSemantics::boundAttributeName($attribute) === 'key' ? [] : ['camel'],
                ReactiveAttributeSemantics::directiveName($attribute),
            ],
            $this->isDirective($attribute, 'wire:cloak') => [[], 'wire:cloak'],
            $this->isDirective($attribute, 'wire:current') => [['exact', 'ignore', 'strict'], 'wire:current'],
            $this->isDirective($attribute, 'wire:confirm') => [['prompt'], 'wire:confirm'],
            $this->isDirective($attribute, 'wire:ignore') => [['children', 'self'], 'wire:ignore'],
            $this->isDirective($attribute, 'wire:init') => [self::ACTION_MODIFIERS, 'wire:init'],
            $this->isDirective($attribute, 'wire:island') => [['append', 'prepend'], 'wire:island'],
            $this->isDirective($attribute, 'wire:key') => [[], 'wire:key'],
            $this->isDirective($attribute, 'wire:loading') => [[
                ...self::TOGGLE_MODIFIERS,
                'delay',
                ...self::LOADING_DELAY_MODIFIERS,
            ], 'wire:loading'],
            $this->isDirective($attribute, 'wire:dirty') => [self::TOGGLE_MODIFIERS, 'wire:dirty'],
            $this->isDirective($attribute, 'wire:offline') => [self::TOGGLE_MODIFIERS, 'wire:offline'],
            $this->isDirective($attribute, 'wire:ref') => [[], 'wire:ref'],
            $this->isDirective($attribute, 'wire:replace') => [['self'], 'wire:replace'],
            $this->isDirective($attribute, 'wire:show') => [['immediate', 'important'], 'wire:show'],
            $this->isDirective($attribute, 'wire:sort:item') => [[], 'wire:sort:item'],
            $this->isDirective($attribute, 'wire:sort:group'),
            $this->isDirective($attribute, 'wire:sort:group-id'),
            $this->isDirective($attribute, 'wire:sort:config'),
            $this->isDirective($attribute, 'wire:sort:handle'),
            $this->isDirective($attribute, 'wire:sort:ignore') => [[], ReactiveAttributeSemantics::directiveName($attribute)],
            $this->isDirective($attribute, 'wire:target') => [['except'], 'wire:target'],
            $this->isDirective($attribute, 'wire:text') => [[], 'wire:text'],
            $this->isDirective($attribute, 'wire:transition') => [[], 'wire:transition'],
            $this->isDirective($attribute, 'wire:stream') => [['replace'], 'wire:stream'],
            default => null,
        };
    }

    private function modelModifierProblem(Attribute $attribute): ?string
    {
        $unsupported = DirectiveModifierGrammar::firstUnsupported(
            $attribute,
            self::MODEL_MODIFIERS,
            ['debounce', 'throttle'],
        );
        if ($unsupported !== null) {
            return "Unsupported .{$unsupported} modifier on wire:model.";
        }

        $modifiers = DirectiveModifierGrammar::modifiers($attribute);
        if (count(array_keys($modifiers, 'live', true)) > 1) {
            return 'Duplicate .live modifier on wire:model.';
        }

        $liveIndex = array_search('live', $modifiers, true);
        $hasLive = is_int($liveIndex);
        $networkStarts = $hasLive ? $liveIndex + 1 : count($modifiers);

        $ephemeral = $hasLive ? array_slice($modifiers, 0, $liveIndex) : $modifiers;
        $systemKey = array_values(array_intersect($ephemeral, self::MODEL_SYSTEM_KEY_MODIFIERS));
        if ($systemKey !== [] && ! in_array('enter', $ephemeral, true)) {
            return "wire:model.{$systemKey[0]} has no .enter modifier before .live.";
        }

        if (in_array('enter', $ephemeral, true)) {
            $blocking = array_values(array_intersect(
                $ephemeral,
                ['boolean', 'deep', 'dot', 'fill', 'number', 'parent', 'trim', 'unintrusive'],
            ));
            if (in_array('passive', $ephemeral, true)) {
                $passive = array_search('passive', $ephemeral, true);
                if (is_int($passive) && ($ephemeral[$passive + 1] ?? null) === 'false') {
                    $blocking[] = 'false';
                }
            }

            if ($blocking !== []) {
                return "wire:model.enter cannot synchronize with .{$blocking[0]}.";
            }
        }

        foreach ($modifiers as $index => $modifier) {
            if (in_array($modifier, ['debounce', 'throttle'], true) && $index < $networkStarts) {
                return "wire:model.{$modifier} is ignored before .live.";
            }

            if (in_array($modifier, ['debounce', 'throttle'], true)
                && $index >= $networkStarts
                && preg_match('/^0+(?:ms)?$/', $modifiers[$index + 1] ?? '') === 1) {
                return "wire:model.live.{$modifier} has a zero duration; Livewire uses 150ms.";
            }

            if ($hasLive
                && $index >= $networkStarts
                && ! in_array($modifier, [
                    'async', 'blur', 'change', 'debounce', 'deep', 'enter', 'lazy', 'preserve-scroll', 'renderless',
                    'throttle',
                ], true)
                && preg_match('/^\d+(?:ms)?$/', $modifier) !== 1) {
                return "wire:model.live ignores .{$modifier} after .live.";
            }
        }

        $hasNetwork = $hasLive || in_array('lazy', $modifiers, true);
        foreach (['async', 'preserve-scroll', 'renderless'] as $requestModifier) {
            if (in_array($requestModifier, $modifiers, true) && ! $hasNetwork) {
                return "wire:model.{$requestModifier} has no live or lazy request to modify.";
            }
        }

        $outside = array_values(array_intersect($modifiers, ['away', 'outside']));
        if ($outside !== []) {
            return "wire:model.{$outside[0]} cannot synchronize the modeled control.";
        }

        return null;
    }

    /** @param array<string, true> $reported */
    private function reportModifierCombinationProblems(
        Attribute $attribute,
        Document $document,
        RuleContext $context,
        array &$reported,
    ): void {
        $modifiers = DirectiveModifierGrammar::modifiers($attribute);
        $problem = match (true) {
            $this->isDirective($attribute, 'wire:navigate') => $this->navigateModifierProblem($modifiers),
            $this->isDirective($attribute, 'wire:ignore')
                && in_array('self', $modifiers, true)
                && in_array('children', $modifiers, true) => 'wire:ignore.self and wire:ignore.children cannot be combined.',
            $this->isDirective($attribute, 'wire:island')
                && in_array('prepend', $modifiers, true)
                && in_array('append', $modifiers, true) => 'wire:island.prepend and wire:island.append cannot be combined.',
            $this->isDirective($attribute, 'wire:stream')
                && count(array_keys($modifiers, 'replace', true)) > 1 => 'wire:stream accepts .replace only once.',
            $this->isDirective($attribute, 'wire:loading') => $this->toggleModifierProblem($attribute, true),
            $this->isDirective($attribute, 'wire:dirty'),
            $this->isDirective($attribute, 'wire:offline') => $this->toggleModifierProblem($attribute, false),
            $this->isDirective($attribute, 'wire:poll') => $this->pollModifierProblem($modifiers),
            $this->isDirectiveFamily($attribute, 'wire:intersect') => $this->intersectModifierProblem($modifiers),
            $this->isDirective($attribute, 'wire:sort') => $this->sortModifierProblem($modifiers),
            default => null,
        };

        if ($problem !== null) {
            $this->reportOnce($document, $attribute, $context, $reported, 'modifier-combination', $problem);
        }
    }

    /** @param list<string> $modifiers */
    private function navigateModifierProblem(array $modifiers): ?string
    {
        foreach ($modifiers as $modifier) {
            if (! in_array($modifier, ['hover', 'preserve-scroll'], true)) {
                return "Unsupported .{$modifier} modifier on wire:navigate.";
            }
        }

        return in_array($modifiers, [
            [], ['hover'], ['preserve-scroll'], ['hover', 'preserve-scroll'], ['preserve-scroll', 'hover'],
        ], true)
            ? null
            : 'wire:navigate modifiers cannot be repeated.';
    }

    private function toggleModifierProblem(Attribute $attribute, bool $supportsDelay): ?string
    {
        $modifiers = DirectiveModifierGrammar::modifiers($attribute);
        $display = array_values(array_intersect(
            $modifiers,
            ['inline', 'list-item', 'block', 'table', 'flex', 'grid', 'inline-flex'],
        ));
        $modes = array_values(array_intersect($modifiers, ['class', 'attr']));
        if (count($display) > 1 || count($modes) > 1 || ($display !== [] && $modes !== [])) {
            return 'This Livewire state directive has conflicting output modifiers.';
        }

        if ($modes !== [] && ! $attribute->hasComplexValue() && trim($attribute->decodedValueText() ?? '') === '') {
            return "wire:{$this->directiveSuffix($attribute)}.{$modes[0]} requires a non-empty class or attribute name.";
        }

        if (! $supportsDelay) {
            return null;
        }

        $delays = array_values(array_intersect($modifiers, self::LOADING_DELAY_MODIFIERS));
        if ($delays !== [] && ! in_array('delay', $modifiers, true)) {
            return "wire:loading.{$delays[0]} has no effect without the .delay modifier.";
        }

        if (count($delays) > 1) {
            return 'wire:loading accepts only one delay duration.';
        }

        return null;
    }

    /** @param list<string> $modifiers */
    private function pollModifierProblem(array $modifiers): ?string
    {
        $durations = [];
        foreach ($modifiers as $modifier) {
            if (preg_match('/^0*[1-9]\d*(?:ms|s)$/', $modifier) === 1) {
                $durations[] = $modifier;

                continue;
            }

            if (! in_array($modifier, [...self::ACTION_MODIFIERS, 'keep-alive', 'visible'], true)) {
                return "Unsupported .{$modifier} modifier on wire:poll.";
            }
        }

        return count($durations) > 1
            ? 'wire:poll accepts only one interval.'
            : null;
    }

    /** @param list<string> $modifiers */
    private function intersectModifierProblem(array $modifiers): ?string
    {
        $fixed = [...self::ACTION_MODIFIERS, 'full', 'half', 'margin', 'once', 'parent', 'threshold'];
        for ($index = 0; $index < count($modifiers); $index++) {
            $modifier = $modifiers[$index];
            if (in_array($modifier, $fixed, true)) {
                continue;
            }

            $thresholdIndex = array_search('threshold', $modifiers, true);
            if (is_int($thresholdIndex)
                && $index === $thresholdIndex + 1
                && preg_match('/^\d+$/', $modifier) === 1) {
                continue;
            }

            $marginIndex = array_search('margin', $modifiers, true);
            if (is_int($marginIndex)
                && $index > $marginIndex
                && $index <= $marginIndex + 4
                && preg_match('/^-?\d+(?:px|%)?$/', $modifier) === 1) {
                continue;
            }

            return "Unsupported .{$modifier} modifier on wire:intersect.";
        }

        $thresholdIndex = array_search('threshold', $modifiers, true);
        if (is_int($thresholdIndex)
            && (! isset($modifiers[$thresholdIndex + 1])
                || preg_match('/^\d+$/', $modifiers[$thresholdIndex + 1]) !== 1)) {
            return 'wire:intersect.threshold requires a following digit sequence.';
        }

        $marginIndex = array_search('margin', $modifiers, true);
        if (is_int($marginIndex)
            && (! isset($modifiers[$marginIndex + 1])
                || preg_match('/^-?\d+(?:px|%)?$/', $modifiers[$marginIndex + 1]) !== 1)) {
            return 'wire:intersect.margin requires at least one following pixel or percentage length.';
        }

        $thresholdModes = array_intersect($modifiers, ['full', 'half', 'threshold']);
        if (count($thresholdModes) > 1) {
            return 'wire:intersect accepts only one threshold mode.';
        }

        return null;
    }

    /** @param list<string> $modifiers */
    private function sortModifierProblem(array $modifiers): ?string
    {
        $groupIndexes = array_keys($modifiers, 'group', true);
        if (count($groupIndexes) > 1) {
            return 'wire:sort accepts only one .group.[name] modifier.';
        }

        $groupValueIndex = $groupIndexes === [] ? null : $groupIndexes[0] + 1;
        if ($groupValueIndex !== null && ! isset($modifiers[$groupValueIndex])) {
            return 'wire:sort.group requires a following group name.';
        }

        foreach ($modifiers as $index => $modifier) {
            if ($groupValueIndex === $index) {
                continue;
            }

            if (! in_array($modifier, [...self::ACTION_MODIFIERS, 'ghost', 'group'], true)) {
                return "Unsupported .{$modifier} modifier on wire:sort.";
            }
        }

        return null;
    }

    private function directiveSuffix(Attribute $attribute): string
    {
        return substr(ReactiveAttributeSemantics::directiveName($attribute), 5);
    }

    private function isDirectiveFamily(Attribute $attribute, string $name): bool
    {
        $directive = ReactiveAttributeSemantics::directiveName($attribute);

        return $directive === $name || str_starts_with($directive, $name.':');
    }

    /** @param array<string, true> $reported */
    private function reportPathProblems(
        ElementNode $element,
        Document $document,
        RuleContext $context,
        array &$reported,
    ): void {
        $paths = $this->explicitAttributeRenderPaths($element);
        if ($paths === null) {
            return;
        }

        foreach ($paths as $path) {
            $path = array_values(array_filter(
                $path,
                static fn (Attribute $attribute): bool => ! ReactiveAttributeSemantics::isClientDirective($attribute)
                    || ReactiveAttributeSemantics::clientDirectiveRuns($attribute, $element),
            ));

            $navigate = $this->firstDirective($path, 'wire:navigate');
            if ($navigate !== null && ! $this->hasHref($path)) {
                $this->reportOnce(
                    $document,
                    $navigate,
                    $context,
                    $reported,
                    'navigate-href',
                    'wire:navigate requires href on the same render path.',
                );
            }

            $current = $this->firstDirective($path, 'wire:current');
            if ($current !== null
                && ! $this->hasModifier($current, 'ignore')
                && ! $this->hasHref($path)) {
                $this->reportOnce(
                    $document,
                    $current,
                    $context,
                    $reported,
                    'current-href',
                    'wire:current requires a usable href on the same element.',
                );
            } elseif ($current !== null
                && ! $this->hasModifier($current, 'ignore')
                && str_starts_with($this->staticHrefValue($path) ?? '', '#')) {
                $this->reportOnce(
                    $document,
                    $current,
                    $context,
                    $reported,
                    'current-fragment',
                    'wire:current does not support fragment-only href values.',
                );
            }

            $group = $this->firstDirective($path, 'wire:sort:group');
            if ($group !== null && $this->firstDirective($path, 'wire:sort') === null) {
                $this->reportOnce(
                    $document,
                    $group,
                    $context,
                    $reported,
                    'sort-group-owner',
                    'wire:sort:group requires wire:sort on the same element.',
                );
            }

            $confirm = $this->firstDirective($path, 'wire:confirm');
            if ($confirm === null) {
                continue;
            }

            if (! $this->hasLivewireAction($path)) {
                $this->reportOnce(
                    $document,
                    $confirm,
                    $context,
                    $reported,
                    'confirm-action',
                    'wire:confirm requires a Livewire action on the same element.',
                );

                continue;
            }

            if ($this->hasModifier($confirm, 'prompt')
                && ! $confirm->hasComplexValue()
                && ! $this->promptHasExpectation($confirm->decodedValueText() ?? '')) {
                $this->reportOnce(
                    $document,
                    $confirm,
                    $context,
                    $reported,
                    'confirm-expectation',
                    'wire:confirm.prompt requires question|expected-text.',
                );
            }
        }
    }

    /** @param list<Attribute> $path */
    private function hasHref(array $path): bool
    {
        foreach ($path as $attribute) {
            if (ReactiveAttributeSemantics::boundAttributeName($attribute) === 'href') {
                return true;
            }

            if ($attribute->isBladeConstruct() || ! $attribute->isNamed('href')) {
                continue;
            }

            if ($attribute->hasComplexValue()) {
                return true;
            }

            return true;
        }

        return false;
    }

    /** @param list<Attribute> $path */
    private function staticHrefValue(array $path): ?string
    {
        foreach ($path as $attribute) {
            if (ReactiveAttributeSemantics::boundAttributeName($attribute) === 'href'
                || $attribute->isBladeConstruct()
                || ! $attribute->isNamed('href')
                || $attribute->hasComplexValue()) {
                continue;
            }

            return trim($attribute->decodedValueText() ?? '');
        }

        return null;
    }

    /** @param list<Attribute> $path */
    private function hasLivewireAction(array $path): bool
    {
        foreach ($path as $attribute) {
            $name = strtolower($attribute->name()->rawName());
            if (! str_starts_with($name, 'wire:')) {
                continue;
            }

            $value = explode(':', explode('.', substr($name, 5))[0], 2)[0];
            if (! in_array($value, self::NON_ACTIONS, true)
                && ($attribute->hasComplexValue() || trim($attribute->decodedValueText() ?? '') !== '')) {
                return true;
            }
        }

        return false;
    }

    private function hasPossibleSortAncestor(ElementNode $element): bool
    {
        foreach ($element->ancestors() as $ancestor) {
            if (! $ancestor instanceof ElementNode) {
                continue;
            }

            foreach ($this->attributesInRenderStructure($ancestor) as $attribute) {
                if (! $attribute->isBladeConstruct()
                    && ReactiveAttributeSemantics::clientDirectiveRuns($attribute, $ancestor)
                    && ($this->isDirective($attribute, 'wire:sort') || $this->isDirective($attribute, 'x-sort'))) {
                    return true;
                }
            }
        }

        return false;
    }

    private function promptHasExpectation(string $expression): bool
    {
        $parts = explode('|', $expression);

        return isset($parts[1]) && $parts[1] !== '';
    }

    /** @param array<string, true> $reported */
    private function reportOnce(
        Document $document,
        Attribute $attribute,
        RuleContext $context,
        array &$reported,
        string $kind,
        string $message,
    ): void {
        $this->reportAttributeOnce($document, $attribute, $context, $reported, $kind, $message);
    }
}
