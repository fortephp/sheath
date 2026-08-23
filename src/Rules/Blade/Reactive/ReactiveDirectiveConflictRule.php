<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Reactive;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\ReactiveAttributeSemantics;
use Forte\Support\HtmlWhitespace;

/** @internal */
final class ReactiveDirectiveConflictRule extends AbstractReactiveRule
{
    /** @var list<array{string, string, string}> */
    private const EXCLUSIVE_DIRECTIVES = [
        ['x-model', 'wire:model', 'model value'],
        ['x-navigate', 'wire:navigate', 'navigation'],
        ['x-sort', 'wire:sort', 'sorting'],
    ];

    private const LIVEWIRE_NON_ACTIONS = [
        'bind', 'cloak', 'confirm', 'current', 'data', 'dirty', 'effects', 'id', 'ignore', 'init', 'intersect',
        'island', 'key', 'loading', 'model', 'navigate', 'offline', 'poll', 'ref', 'replace', 'show', 'snapshot', 'sort',
        'stream', 'target', 'text', 'transition',
    ];

    public function getId(): string
    {
        return 'blade-reactive-directive-conflicts';
    }

    public function getDescription(): string
    {
        return 'Alpine, Livewire, and HTML directives must not conflict.';
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
        $reported = [];
        $livewireInstalled = ReactiveAttributeSemantics::livewireCompilerIsInstalled();

        foreach ($this->clientElementsWithDirectives($context) as $element) {
            $paths = $this->explicitAttributeRenderPaths($element);
            if ($paths === null) {
                continue;
            }

            foreach ($paths as $path) {
                $path = $this->activeClientPath($path, $element, $livewireInstalled);
                $this->reportExclusiveOwners($path, $document, $context, $reported);
                $this->reportMultipleDirectiveOwners($path, $document, $context, $reported);
                $this->reportBindingOwners($path, $document, $context, $reported);
                $this->reportVisibilityBlocker($path, $document, $context, $reported);
                $this->reportEventOptionConflicts($path, $document, $context, $reported);
            }
        }
    }

    /**
     * @param  list<Attribute>  $path
     * @param  array<string, true>  $reported
     */
    private function reportMultipleDirectiveOwners(
        array $path,
        Document $document,
        RuleContext $context,
        array &$reported,
    ): void {
        $displayOwners = [];
        $contentOwners = [];

        foreach ($path as $attribute) {
            if ($this->isDisplayVisibilityDirective($attribute)) {
                $displayOwners[] = $attribute;
            }

            if (ReactiveAttributeSemantics::isClientTextDirective($attribute)) {
                $contentOwners[] = $attribute;
            }
        }

        if (count($displayOwners) > 1) {
            $this->reportOnce(
                $document,
                $displayOwners[1],
                $context,
                $reported,
                'display-owner',
                'Multiple reactive directives control this element\'s display.',
            );
        }

        if (count($contentOwners) > 1) {
            $this->reportOnce(
                $document,
                $contentOwners[1],
                $context,
                $reported,
                'content-owner',
                'Multiple reactive directives replace this element\'s content.',
            );
        }
    }

    /**
     * @param  list<Attribute>  $path
     * @param  array<string, true>  $reported
     */
    private function reportExclusiveOwners(
        array $path,
        Document $document,
        RuleContext $context,
        array &$reported,
    ): void {
        foreach (self::EXCLUSIVE_DIRECTIVES as [$alpine, $livewire, $behavior]) {
            $alpineAttribute = $this->firstDirective($path, $alpine);
            $livewireAttribute = $this->firstDirective($path, $livewire);

            if ($alpineAttribute === null || $livewireAttribute === null) {
                continue;
            }

            $this->reportOnce(
                $document,
                $livewireAttribute,
                $context,
                $reported,
                $behavior,
                "{$alpine} and {$livewire} both control this element's {$behavior}.",
            );
        }
    }

    /**
     * @param  list<Attribute>  $path
     * @param  array<string, true>  $reported
     */
    private function reportBindingOwners(
        array $path,
        Document $document,
        RuleContext $context,
        array &$reported,
    ): void {
        $alpine = [];
        $livewire = [];

        foreach ($path as $attribute) {
            $target = ReactiveAttributeSemantics::boundAttributeName($attribute);
            if ($target === null) {
                continue;
            }

            $name = strtolower($attribute->name()->rawName());
            if (str_starts_with($name, 'wire:bind:')) {
                $livewire[$target] ??= $attribute;
            } elseif ($name[0] === ':' || str_starts_with($name, 'x-bind:')) {
                if (isset($alpine[$target])) {
                    $this->reportOnce(
                        $document,
                        $attribute,
                        $context,
                        $reported,
                        'bind:alpine:'.$target,
                        "Multiple Alpine bindings update the {$target} attribute on this render path.",
                    );
                } else {
                    $alpine[$target] = $attribute;
                }
            }
        }

        foreach (array_intersect(array_keys($alpine), array_keys($livewire)) as $target) {
            $this->reportOnce(
                $document,
                $livewire[$target],
                $context,
                $reported,
                'bind:'.$target,
                "Alpine and Livewire both bind the {$target} attribute on this render path.",
            );
        }
    }

    /**
     * @param  list<Attribute>  $path
     * @param  array<string, true>  $reported
     */
    private function reportVisibilityBlocker(
        array $path,
        Document $document,
        RuleContext $context,
        array &$reported,
    ): void {
        $visibility = null;
        $hasDynamicHidden = false;
        $hasDynamicClass = false;
        $hasHiddenAttribute = false;
        $hasHiddenClass = false;

        foreach ($path as $attribute) {
            $target = ReactiveAttributeSemantics::boundAttributeName($attribute);
            $hasDynamicHidden = $hasDynamicHidden || $target === 'hidden';
            $hasDynamicClass = $hasDynamicClass || $target === 'class';

            if ($this->isDisplayVisibilityDirective($attribute)) {
                $visibility ??= $attribute;
            }

            if (! $attribute->isBladeConstruct() && $attribute->isNamed('hidden')) {
                $hasHiddenAttribute = true;
            }

            if (! $attribute->isBladeConstruct()
                && $attribute->isNamed('class')
                && ! $attribute->hasComplexValue()) {
                $classes = HtmlWhitespace::split($attribute->decodedValueText() ?? '');
                $hasHiddenClass = $hasHiddenClass || in_array('hidden', $classes, true);
            }
        }

        if ($visibility === null) {
            return;
        }

        if ($hasHiddenAttribute && ! $hasDynamicHidden) {
            $this->reportOnce(
                $document,
                $visibility,
                $context,
                $reported,
                'hidden-attribute',
                'The static hidden attribute conflicts with this visibility directive.',
            );
        }

        if ($hasHiddenClass && ! $hasDynamicClass && $this->isShowVisibilityDirective($visibility)) {
            $this->reportOnce(
                $document,
                $visibility,
                $context,
                $reported,
                'hidden-class',
                'The static hidden class conflicts with this visibility directive.',
            );
        }
    }

    /**
     * @param  list<Attribute>  $path
     * @param  array<string, true>  $reported
     */
    private function reportEventOptionConflicts(
        array $path,
        Document $document,
        RuleContext $context,
        array &$reported,
    ): void {
        foreach ($path as $attribute) {
            $name = strtolower($attribute->name()->rawName());
            $segments = explode('.', $name);
            $modifiers = array_slice($segments, 1);
            $isAlpineEvent = str_starts_with($name, '@') || str_starts_with($name, 'x-on:');
            $wireValue = str_starts_with($segments[0], 'wire:')
                ? explode(':', substr($segments[0], 5), 2)[0]
                : null;
            $isLivewireAction = $wireValue !== null && ! in_array($wireValue, self::LIVEWIRE_NON_ACTIONS, true);
            if (! $isAlpineEvent && ! $isLivewireAction) {
                continue;
            }

            if (in_array('window', $modifiers, true) && in_array('document', $modifiers, true)) {
                $this->reportOnce(
                    $document,
                    $attribute,
                    $context,
                    $reported,
                    'event-target',
                    '.window and .document cannot be combined.',
                );

                continue;
            }

            if (in_array('self', $modifiers, true)
                && array_intersect($modifiers, ['away', 'outside']) !== []) {
                $this->reportOnce(
                    $document,
                    $attribute,
                    $context,
                    $reported,
                    'event-self-outside',
                    '.self and .outside cannot be combined.',
                );

                continue;
            }

            if (! in_array('passive', $modifiers, true)) {
                continue;
            }

            if ($this->passiveIsExplicitlyDisabled($modifiers, $isAlpineEvent, $isLivewireAction)) {
                continue;
            }

            $prevents = in_array('prevent', $modifiers, true) || ($wireValue === 'submit' && $isLivewireAction);

            if ($prevents) {
                $this->reportOnce(
                    $document,
                    $attribute,
                    $context,
                    $reported,
                    'passive-prevent',
                    '.passive and .prevent cannot be combined.',
                );
            }
        }
    }

    /** @param list<string> $modifiers */
    private function passiveIsExplicitlyDisabled(
        array $modifiers,
        bool $isAlpineEvent,
        bool $isLivewireAction,
    ): bool {
        $supportsPassiveFalse = $isAlpineEvent
            || ($isLivewireAction && ReactiveAttributeSemantics::livewireBundleSupportsPassiveFalse());
        if (! $supportsPassiveFalse) {
            return false;
        }

        $passiveIndex = array_search('passive', $modifiers, true);

        return is_int($passiveIndex)
            && ($modifiers[$passiveIndex + 1] ?? null) === 'false';
    }

    private function isDisplayVisibilityDirective(Attribute $attribute): bool
    {
        foreach (['x-show', 'wire:show'] as $base) {
            if ($this->isDirective($attribute, $base)) {
                return true;
            }
        }

        foreach (['wire:loading', 'wire:dirty', 'wire:offline'] as $base) {
            if (! $this->isDirective($attribute, $base)) {
                continue;
            }

            $modifiers = array_slice(explode('.', strtolower($attribute->name()->rawName())), 1);

            return ! in_array('class', $modifiers, true) && ! in_array('attr', $modifiers, true);
        }

        return false;
    }

    private function isShowVisibilityDirective(Attribute $attribute): bool
    {
        return $this->isDirective($attribute, 'x-show') || $this->isDirective($attribute, 'wire:show');
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
