<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Reactive;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\ReactiveAttributeSemantics;
use Forte\Sheath\Support\ReactiveElementCollection;
use Forte\Sheath\Support\StaticClientExpression;

/** @internal */
final class LivewireLoopKeyIntegrityRule extends AbstractReactiveRule
{
    private const LOOP_DIRECTIVES = ['foreach', 'forelse', 'for', 'while'];

    public function getId(): string
    {
        return 'blade-livewire-loop-key-integrity';
    }

    public function getDescription(): string
    {
        return 'Livewire keys and sortable item IDs must vary inside Blade loops.';
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

        foreach (ReactiveElementCollection::livewireIdentityElements($document) as $element) {
            if (! $this->isInsideBladeLoop($element)) {
                continue;
            }

            $paths = $this->explicitAttributeRenderPaths($element);
            if ($paths === null) {
                continue;
            }

            $reported = [];
            foreach ($paths as $path) {
                foreach ($path as $attribute) {
                    if ($attribute->isBladeConstruct()
                        || ! ReactiveAttributeSemantics::clientDirectiveRuns($attribute, $element)
                        || ! $this->isStaticLoopIdentity($attribute)
                        || isset($reported[$attribute->startOffset()])) {
                        continue;
                    }
                    $reported[$attribute->startOffset()] = true;

                    $name = ltrim(strtolower($attribute->name()->rawName()), ':');
                    $message = $name === 'wire:sort:item'
                        ? 'wire:sort:item is static inside a Blade loop.'
                        : 'Livewire key is static inside a Blade loop.';

                    $context->reportAt(
                        Position::fromOffset($document, $attribute->startOffset()),
                        Position::fromOffset($document, $attribute->endOffset()),
                        $message,
                    );
                }
            }
        }
    }

    private function isInsideBladeLoop(ElementNode $element): bool
    {
        foreach ($element->ancestors() as $ancestor) {
            if ($ancestor instanceof DirectiveBlockNode
                && in_array(strtolower($ancestor->nameText()), self::LOOP_DIRECTIVES, true)) {
                return true;
            }
        }

        return false;
    }

    private function isStaticLoopIdentity(Attribute $attribute): bool
    {
        $rawName = strtolower($attribute->name()->rawName());
        $name = ltrim($rawName, ':');
        if (! in_array($name, ['wire:key', 'wire:sort:item'], true)) {
            return false;
        }

        if ($attribute->hasComplexValue()) {
            return false;
        }

        if (! str_starts_with($rawName, ':')) {
            return true;
        }

        return StaticClientExpression::isConstant($attribute->decodedValueText() ?? '');
    }
}
