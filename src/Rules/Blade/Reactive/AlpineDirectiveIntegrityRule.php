<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Reactive;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\DirectiveModifierGrammar;
use Forte\Sheath\Support\ReactiveAttributeSemantics;
use Forte\Sheath\Support\StaticClientExpression;

/** @internal */
final class AlpineDirectiveIntegrityRule extends AbstractReactiveRule
{
    private const MODEL_MODIFIERS = [
        'alt', 'away', 'blur', 'boolean', 'capture', 'change', 'cmd', 'ctrl', 'debounce', 'document', 'dot', 'enter',
        'fill', 'lazy', 'meta', 'number', 'once', 'outside', 'parent', 'passive', 'prevent', 'self', 'shift', 'stop',
        'super', 'throttle', 'trim', 'unintrusive', 'window',
    ];

    private const MODEL_SYSTEM_KEY_MODIFIERS = ['alt', 'cmd', 'ctrl', 'meta', 'shift', 'super'];

    public function getId(): string
    {
        return 'blade-alpine-directive-integrity';
    }

    public function getDescription(): string
    {
        return 'Alpine directives must use valid targets, values, and modifiers.';
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

                $this->reportInvalidIdValue($path, $document, $context, $reported);
                $this->reportOrphanedModelable($path, $document, $context, $reported);
                $this->reportInvalidModelValue($path, $document, $context, $reported);
                $this->reportInvalidModelModifiers($path, $document, $context, $reported);
                $this->reportInvalidCoreModifiers($path, $document, $context, $reported);

                if ($element->isTag('template')) {
                    $this->reportInertTemplateDirectives($path, $document, $context, $reported);
                }
            }
        }
    }

    /**
     * @param  list<Attribute>  $path
     * @param  array<string, true>  $reported
     */
    private function reportInvalidCoreModifiers(
        array $path,
        Document $document,
        RuleContext $context,
        array &$reported,
    ): void {
        foreach ($path as $attribute) {
            $contract = $this->modifierContract($attribute);
            if ($contract === null) {
                continue;
            }

            [$allowed, $directive] = $contract;
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

                continue;
            }

            if ($directive === 'x-teleport'
                && $this->hasModifier($attribute, 'append')
                && $this->hasModifier($attribute, 'prepend')) {
                $this->reportOnce(
                    $document,
                    $attribute,
                    $context,
                    $reported,
                    'teleport-placement',
                    'x-teleport.append and x-teleport.prepend cannot be combined.',
                );
            }
        }
    }

    /** @return array{list<string>, string}|null */
    private function modifierContract(Attribute $attribute): ?array
    {
        $directive = ReactiveAttributeSemantics::directiveName($attribute);
        $rawName = strtolower($attribute->name()->rawName());

        if (str_starts_with($rawName, ':') || str_starts_with($rawName, 'x-bind:')) {
            return ReactiveAttributeSemantics::boundAttributeName($attribute) === 'key'
                ? [[], 'x-bind:key']
                : [['camel'], 'x-bind'];
        }

        return match ($directive) {
            'x-cloak', 'x-data', 'x-effect', 'x-for', 'x-html', 'x-id', 'x-if', 'x-init', 'x-modelable',
            'x-ref', 'x-text', 'x-bind' => [[], $directive],
            'x-ignore' => [['self'], $directive],
            'x-show' => [['immediate', 'important'], $directive],
            'x-teleport' => [['append', 'prepend'], $directive],
            default => null,
        };
    }

    /**
     * @param  list<Attribute>  $path
     * @param  array<string, true>  $reported
     */
    private function reportInvalidModelModifiers(
        array $path,
        Document $document,
        RuleContext $context,
        array &$reported,
    ): void {
        $model = $this->firstDirective($path, 'x-model');
        if ($model === null) {
            return;
        }

        $modifiers = DirectiveModifierGrammar::modifiers($model);
        $outside = array_intersect($modifiers, ['away', 'outside']);
        if ($outside !== []) {
            $modifier = array_values($outside)[0];
            $this->reportOnce(
                $document,
                $model,
                $context,
                $reported,
                'model-outside',
                "x-model.{$modifier} cannot synchronize the modeled element.",
            );

            return;
        }

        if (in_array('enter', $modifiers, true)) {
            $blocking = array_values(array_intersect(
                $modifiers,
                ['boolean', 'dot', 'fill', 'number', 'parent', 'trim', 'unintrusive'],
            ));
            if (in_array('passive', $modifiers, true)) {
                $passive = array_search('passive', $modifiers, true);
                if (is_int($passive) && ($modifiers[$passive + 1] ?? null) === 'false') {
                    $blocking[] = 'false';
                }
            }

            if ($blocking !== []) {
                $this->reportOnce(
                    $document,
                    $model,
                    $context,
                    $reported,
                    'model-enter-filter',
                    "x-model.enter cannot synchronize with .{$blocking[0]}.",
                );

                return;
            }
        }

        $systemKey = array_values(array_intersect($modifiers, self::MODEL_SYSTEM_KEY_MODIFIERS));
        if ($systemKey !== [] && ! in_array('enter', $modifiers, true)) {
            $this->reportOnce(
                $document,
                $model,
                $context,
                $reported,
                'model-system-key',
                "x-model.{$systemKey[0]} has no .enter keyboard listener.",
            );

            return;
        }

        $unsupported = DirectiveModifierGrammar::firstUnsupported(
            $model,
            self::MODEL_MODIFIERS,
            ['debounce', 'throttle'],
        );
        if ($unsupported !== null) {
            $this->reportOnce(
                $document,
                $model,
                $context,
                $reported,
                'model-modifier',
                "Unsupported .{$unsupported} modifier on x-model.",
            );
        }
    }

    /**
     * @param  list<Attribute>  $path
     * @param  array<string, true>  $reported
     */
    private function reportInvalidModelValue(
        array $path,
        Document $document,
        RuleContext $context,
        array &$reported,
    ): void {
        $model = $this->firstDirective($path, 'x-model');
        if ($model === null || $model->hasComplexValue()) {
            return;
        }

        $expression = trim($model->decodedValueText() ?? '');
        if ($expression !== '' && ! StaticClientExpression::isConstant($expression)) {
            return;
        }

        $this->reportOnce(
            $document,
            $model,
            $context,
            $reported,
            'model-value',
            $expression === ''
                ? 'x-model requires an assignable expression.'
                : 'x-model cannot assign to a literal value.',
        );
    }

    /**
     * @param  list<Attribute>  $path
     * @param  array<string, true>  $reported
     */
    private function reportInvalidIdValue(
        array $path,
        Document $document,
        RuleContext $context,
        array &$reported,
    ): void {
        $id = $this->firstDirective($path, 'x-id');
        if ($id === null || $id->hasComplexValue()) {
            return;
        }

        $expression = trim($id->decodedValueText() ?? '');
        $definitelyInvalid = StaticClientExpression::isConstant($expression)
            || StaticClientExpression::isDefinitelyNonIterableObject($expression);

        if ($definitelyInvalid && ! $this->isArrayExpression($expression)) {
            $this->reportOnce(
                $document,
                $id,
                $context,
                $reported,
                'id-value',
                'x-id requires an array-like expression of ID names.',
            );
        }
    }

    /**
     * @param  list<Attribute>  $path
     * @param  array<string, true>  $reported
     */
    private function reportOrphanedModelable(
        array $path,
        Document $document,
        RuleContext $context,
        array &$reported,
    ): void {
        $modelable = $this->firstDirective($path, 'x-modelable');
        if ($modelable === null) {
            return;
        }

        $modelableExpression = trim($modelable->decodedValueText() ?? '');
        if (! $modelable->hasComplexValue()
            && ($modelableExpression === '' || StaticClientExpression::isConstant($modelableExpression))) {
            $this->reportOnce(
                $document,
                $modelable,
                $context,
                $reported,
                'modelable-expression',
                $modelableExpression === ''
                    ? 'x-modelable requires an inner Alpine expression to entangle.'
                    : 'x-modelable cannot assign to a literal value.',
            );

            return;
        }

        if ($this->firstDirective($path, 'x-model') === null
            && $this->firstDirective($path, 'wire:model') === null) {
            $this->reportOnce(
                $document,
                $modelable,
                $context,
                $reported,
                'modelable-owner',
                'x-modelable requires x-model or wire:model on the same render path.',
            );
        }
    }

    /**
     * @param  list<Attribute>  $path
     * @param  array<string, true>  $reported
     */
    private function reportInertTemplateDirectives(
        array $path,
        Document $document,
        RuleContext $context,
        array &$reported,
    ): void {
        $teleports = $this->firstDirective($path, 'x-teleport') !== null;

        foreach ($path as $attribute) {
            $name = strtolower($attribute->name()->rawName());
            if ($name === 'x-transition'
                || str_starts_with($name, 'x-transition.')
                || str_starts_with($name, 'x-transition:')) {
                $this->reportOnce(
                    $document,
                    $attribute,
                    $context,
                    $reported,
                    'template-transition',
                    'x-transition cannot be attached to <template>.',
                );

                continue;
            }

            if (! str_starts_with($name, '@') && ! str_starts_with($name, 'x-on:')) {
                continue;
            }

            $modifiers = array_slice(explode('.', $name), 1);
            $isGlobal = in_array('window', $modifiers, true) || in_array('document', $modifiers, true);
            if (! $teleports && ! $isGlobal) {
                $this->reportOnce(
                    $document,
                    $attribute,
                    $context,
                    $reported,
                    'template-event',
                    'Event listeners cannot be attached to <template>.',
                );
            }
        }
    }

    private function isArrayExpression(string $expression): bool
    {
        $expression = trim($expression);
        while (strlen($expression) >= 2 && $expression[0] === '(' && str_ends_with($expression, ')')) {
            $expression = trim(substr($expression, 1, -1));
        }

        return strlen($expression) >= 2 && $expression[0] === '[' && str_ends_with($expression, ']');
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
