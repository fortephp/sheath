<?php

declare(strict_types=1);

namespace Forte\Sheath\Support;

use Composer\InstalledVersions;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use OutOfBoundsException;

/**
 * @internal
 */
final class ReactiveAttributeSemantics
{
    /** @var list<string> */
    public const CLIENT_TEXT_DIRECTIVES = ['x-text', 'x-html', 'wire:text'];

    /** @var list<string> */
    private const NON_DURABLE_SUBMIT_MODIFIERS = ['away', 'once', 'outside', 'passive'];

    public static function livewireCompilerIsInstalled(): bool
    {
        return class_exists('Livewire\\Mechanisms\\CompileLivewireTags\\LivewireTagPrecompiler');
    }

    public static function livewireBundleSupportsPassiveFalse(): bool
    {
        if (! self::livewireCompilerIsInstalled()
            || ! class_exists(InstalledVersions::class)
            || ! InstalledVersions::isInstalled('livewire/livewire')) {
            return false;
        }

        try {
            $directory = InstalledVersions::getInstallPath('livewire/livewire');
        } catch (OutOfBoundsException) {
            return false;
        }

        if (! is_string($directory)) {
            return false;
        }

        $bundle = $directory.DIRECTORY_SEPARATOR.'dist'.DIRECTORY_SEPARATOR.'livewire.js';
        if (! is_file($bundle)) {
            return false;
        }

        /** @var array<string, bool> $supportByBundle */
        static $supportByBundle = [];
        $cacheKey = $bundle.'|'.filemtime($bundle).'|'.filesize($bundle);
        if (isset($supportByBundle[$cacheKey])) {
            return $supportByBundle[$cacheKey];
        }

        $source = file_get_contents($bundle);

        return $supportByBundle[$cacheKey] = is_string($source)
            && preg_match(
                '/options\.passive\s*=\s*modifiers\[modifiers\.indexOf\(["\']passive["\']\)\s*\+\s*1\]\s*!==\s*["\']false["\']/',
                $source,
            ) === 1;
    }

    public static function boundAttributeName(Attribute $attribute): ?string
    {
        $name = strtolower($attribute->name()->rawName());

        if ($attribute->isBound() && str_starts_with($name, ':')) {
            $target = explode('.', substr($name, 1), 2)[0];

            return $target !== '' ? $target : null;
        }

        foreach (['x-bind:' => 7, 'wire:bind:' => 10] as $prefix => $length) {
            if (! str_starts_with($name, $prefix)) {
                continue;
            }

            $target = explode('.', substr($name, $length), 2)[0];

            return $target !== '' ? $target : null;
        }

        return null;
    }

    public static function isClientDirective(Attribute $attribute): bool
    {
        if ($attribute->isBladeConstruct()) {
            return false;
        }

        $name = strtolower($attribute->name()->rawName());

        return str_starts_with($name, 'x-')
            || str_starts_with($name, 'wire:')
            || str_starts_with($name, '@')
            || str_starts_with($name, ':');
    }

    public static function valueIsDynamic(Attribute $attribute): bool
    {
        return $attribute->isDynamic() || self::boundAttributeName($attribute) !== null;
    }

    public static function directiveName(Attribute $attribute): string
    {
        return explode('.', strtolower($attribute->name()->rawName()), 2)[0];
    }

    /**
     * Alpine's object form can apply arbitrary HTML attributes and directives.
     */
    public static function isOpaqueAttributeSet(Attribute $attribute): bool
    {
        if (strtolower($attribute->name()->rawName()) !== 'x-bind') {
            return false;
        }

        return $attribute->hasComplexValue()
            || trim($attribute->decodedValueText() ?? '') !== '';
    }

    public static function isClientTextDirective(Attribute $attribute): bool
    {
        $name = self::directiveName($attribute);

        return in_array($name, self::CLIENT_TEXT_DIRECTIVES, true);
    }

    /**
     * Whether Alpine replaces the element's children with a value that may
     * contribute non-whitespace accessible text.
     */
    public static function clientTextMayBeNonEmpty(Attribute $attribute, ?ElementNode $element = null): bool
    {
        if (! self::isClientTextDirective($attribute)
            || ($element !== null && ! self::clientDirectiveRuns($attribute, $element))) {
            return false;
        }

        if ($attribute->hasComplexValue()) {
            return true;
        }

        $expression = trim($attribute->decodedValueText() ?? '');
        if ($expression === '' || in_array(strtolower($expression), ['null', 'undefined', 'void 0', '[]'], true)) {
            return false;
        }

        if (strlen($expression) >= 2
            && in_array($expression[0], ["'", '"', '`'], true)
            && $expression[strlen($expression) - 1] === $expression[0]) {
            $literal = substr($expression, 1, -1);

            // Escaped JavaScript literals need a JavaScript parser to resolve
            // safely. A plain whitespace literal is unambiguously nameless.
            return str_contains($literal, '\\') || trim($literal) !== '';
        }

        return true;
    }

    /**
     * Alpine x-if/x-for clone their template's first element beside the
     * template. Unlike x-teleport, that content retains the source parent.
     */
    public static function isLocalTemplateRenderer(ElementNode $element): bool
    {
        return $element->isTag('template')
            && self::activeAlpineAttribute($element, ['x-if', 'x-for']) !== null;
    }

    /** Alpine moves this template's content elsewhere in the same document. */
    public static function isTeleportTemplateRenderer(ElementNode $element): bool
    {
        return $element->isTag('template')
            && self::activeAlpineAttribute($element, ['x-teleport']) !== null;
    }

    /**
     * Alpine does not initialize descendants of x-ignore. On the ignored
     * element itself Alpine directives are skipped, while Livewire directives
     * are initialized by its interceptor before Alpine processes x-ignore.
     */
    public static function clientDirectiveRuns(Attribute $attribute, ElementNode $element): bool
    {
        $name = strtolower($attribute->name()->rawName());
        $isAlpine = str_starts_with($name, 'x-') || str_starts_with($name, '@') || str_starts_with($name, ':');
        $isLivewire = str_starts_with($name, 'wire:');
        $isIgnoreDirective = self::directiveName($attribute) === 'x-ignore';

        if (! $isAlpine && ! $isLivewire) {
            return true;
        }

        $candidate = $element;
        $isOwner = true;

        while (true) {
            $ignore = self::ignoreMode($candidate);

            if (! $isOwner && $ignore === 'subtree') {
                return false;
            }

            if ($isOwner && $isAlpine && ! $isIgnoreDirective && $ignore !== null) {
                return false;
            }

            $parent = $candidate->getParent();
            while ($parent !== null && ! $parent instanceof ElementNode) {
                $parent = $parent->getParent();
            }

            if (! $parent instanceof ElementNode) {
                return true;
            }

            $candidate = $parent;
            $isOwner = false;
        }
    }

    /** Whether Alpine instantiates a template rather than leaving it inert. */
    public static function rendersTemplateContent(ElementNode $element): bool
    {
        return self::isLocalTemplateRenderer($element)
            || self::isTeleportTemplateRenderer($element);
    }

    /**
     * Whether every client-visible state containing the subject also contains
     * the required element. This intentionally proves only exact shared
     * Alpine/Livewire conditions; non-identical expressions remain unknown.
     */
    public static function elementRendersWhenever(ElementNode $required, ElementNode $subject): bool
    {
        $subjectConstraints = array_fill_keys(self::visibilityConstraints($subject), true);

        foreach (self::visibilityConstraints($required) as $constraint) {
            if (! isset($subjectConstraints[$constraint])) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function visibilityConstraints(ElementNode $element): array
    {
        $constraints = [];
        $candidate = $element;

        while (true) {
            foreach (self::ownVisibilityConstraints($candidate) as $constraint) {
                $constraints[$constraint] = true;
            }

            $parent = $candidate->getParent();
            while ($parent !== null && ! $parent instanceof ElementNode) {
                $parent = $parent->getParent();
            }

            if (! $parent instanceof ElementNode) {
                break;
            }

            if (self::isLocalTemplateRenderer($parent)) {
                $constraints['template:'.$parent->index()] = true;
            }

            $candidate = $parent;
        }

        return array_keys($constraints);
    }

    /** @return list<string> */
    public static function ownVisibilityConstraints(ElementNode $element): array
    {
        $constraints = [];

        foreach ($element->attributes() as $attribute) {
            if ($attribute->isBladeConstruct()) {
                continue;
            }

            $constraint = self::visibilityConstraint($attribute, $element);
            if ($constraint !== null) {
                $constraints[$constraint] = true;
            }
        }

        return array_keys($constraints);
    }

    public static function visibilityConstraint(Attribute $attribute, ElementNode $element): ?string
    {
        if (! self::mayHideElement($attribute, $element)) {
            return null;
        }

        $name = strtolower($attribute->name()->rawName());
        $value = trim($attribute->decodedValueText() ?? '');
        if (str_starts_with($name, 'wire:show') || str_starts_with($name, 'x-show')) {
            return 'show:'.$value;
        }

        $target = $element->attribute('wire:target');
        $targetValue = trim($target?->decodedValueText() ?? '');

        return $name.'='.$value.'|target='.$targetValue;
    }

    /**
     * Whether a directive deterministically introduces a state in which the
     * element is display:none. Class/attribute variants only mutate the named
     * class or attribute and therefore do not imply visibility.
     */
    public static function mayHideElement(Attribute $attribute, ?ElementNode $element = null): bool
    {
        if ($element !== null && ! self::clientDirectiveRuns($attribute, $element)) {
            return false;
        }

        $parts = explode('.', strtolower($attribute->name()->rawName()));
        $directive = array_shift($parts);

        if (in_array($directive, ['x-show', 'wire:show'], true)) {
            return strtolower(trim($attribute->decodedValueText() ?? '')) !== 'true';
        }

        if (! in_array($directive, ['wire:loading', 'wire:dirty', 'wire:offline'], true)) {
            return false;
        }

        if (in_array('class', $parts, true)) {
            return false;
        }

        if (in_array('attr', $parts, true)) {
            return in_array(
                strtolower(trim($attribute->decodedValueText() ?? '')),
                ['hidden', 'inert', 'aria-hidden'],
                true,
            );
        }

        return true;
    }

    /** @param list<string> $names */
    private static function activeAlpineAttribute(ElementNode $element, array $names): ?Attribute
    {
        foreach ($element->attributes() as $attribute) {
            if ($attribute->isBladeConstruct() || ! in_array(self::directiveName($attribute), $names, true)) {
                continue;
            }

            if (self::clientDirectiveRuns($attribute, $element)) {
                return $attribute;
            }
        }

        return null;
    }

    private static function ignoreMode(ElementNode $element): ?string
    {
        foreach ($element->attributes() as $attribute) {
            if ($attribute->isBladeConstruct()) {
                continue;
            }

            $parts = explode('.', strtolower($attribute->name()->rawName()));
            if (array_shift($parts) !== 'x-ignore') {
                continue;
            }

            return in_array('self', $parts, true) ? 'self' : 'subtree';
        }

        return null;
    }

    public static function isFormSubmissionDirective(Attribute $attribute): bool
    {
        $name = self::directiveName($attribute);

        return in_array($name, ['wire:submit', 'x-on:submit', '@submit'], true);
    }

    /**
     * Whether the listener reliably prevents every native submission.
     *
     * Livewire adds Alpine's `.prevent` modifier to `wire:submit` itself. The
     * excluded modifiers either route the listener away from the form, make
     * preventDefault ineffective, or remove the listener after one submit.
     */
    public static function preventsNativeFormSubmission(
        Attribute $attribute,
        ?ElementNode $element = null,
    ): bool {
        if (! self::isFormSubmissionDirective($attribute)
            || ($element !== null && ! self::clientDirectiveRuns($attribute, $element))) {
            return false;
        }

        $parts = explode('.', strtolower($attribute->name()->rawName()));
        $directive = array_shift($parts);

        $nonDurable = array_values(array_intersect($parts, self::NON_DURABLE_SUBMIT_MODIFIERS));
        if (($directive !== 'wire:submit' || self::livewireBundleSupportsPassiveFalse())
            && ($passiveIndex = array_search('passive', $nonDurable, true)) !== false
            && self::passiveIsExplicitlyDisabled($parts)) {
            unset($nonDurable[$passiveIndex]);
        }

        if ($nonDurable !== []) {
            return false;
        }

        return $directive === 'wire:submit' || in_array('prevent', $parts, true);
    }

    /** @param list<string> $modifiers */
    private static function passiveIsExplicitlyDisabled(array $modifiers): bool
    {
        $index = array_search('passive', $modifiers, true);

        return is_int($index) && ($modifiers[$index + 1] ?? null) === 'false';
    }
}
