<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Reactive;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Analysis\ReactiveElements;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\ReactiveAttributeSemantics;

/** @internal */
abstract class AbstractReactiveRule extends AbstractRule
{
    use DetectsOpaqueAttributes;

    protected function hasClientDirectiveInRenderStructure(ElementNode $element): bool
    {
        $hasBladeConstruct = false;
        foreach ($element->attributes() as $attribute) {
            if ($attribute->isBladeConstruct()) {
                $hasBladeConstruct = true;

                continue;
            }

            if (ReactiveAttributeSemantics::isClientDirective($attribute)) {
                return true;
            }
        }

        if (! $hasBladeConstruct) {
            return false;
        }

        foreach ($this->attributesInRenderStructure($element) as $attribute) {
            if (ReactiveAttributeSemantics::isClientDirective($attribute)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<ElementNode> */
    protected function clientElementsWithDirectives(RuleContext $context): array
    {
        /** @var ReactiveElements $analysis */
        $analysis = $context->analysis(
            ReactiveElements::class,
            fn (): ReactiveElements => new ReactiveElements(array_values(
                $context->elements()
                    ->filter(fn (ElementNode $element): bool => $this->hasClientDirectiveInRenderStructure($element))
                    ->all(),
            )),
        );

        return $analysis->all();
    }

    /**
     * @param  list<Attribute>  $path
     * @return list<Attribute>
     */
    protected function activeClientPath(
        array $path,
        ElementNode $element,
        bool $livewireInstalled,
    ): array {
        return array_values(array_filter(
            $path,
            static function (Attribute $attribute) use ($element, $livewireInstalled): bool {
                if (! $livewireInstalled
                    && str_starts_with(strtolower($attribute->name()->rawName()), 'wire:')) {
                    return false;
                }

                return ! ReactiveAttributeSemantics::isClientDirective($attribute)
                    || ReactiveAttributeSemantics::clientDirectiveRuns($attribute, $element);
            },
        ));
    }

    /** @param list<Attribute> $path */
    protected function firstDirective(array $path, string $name): ?Attribute
    {
        foreach ($path as $attribute) {
            if ($this->isDirective($attribute, $name)) {
                return $attribute;
            }
        }

        return null;
    }

    protected function isDirective(Attribute $attribute, string $name): bool
    {
        return ReactiveAttributeSemantics::directiveName($attribute) === $name;
    }

    protected function hasModifier(Attribute $attribute, string $modifier): bool
    {
        return in_array(
            $modifier,
            array_slice(explode('.', strtolower($attribute->name()->rawName())), 1),
            true,
        );
    }

    protected function reportAttribute(
        Document $document,
        Attribute $attribute,
        RuleContext $context,
        string $message,
    ): void {
        $context->reportAt(
            Position::fromOffset($document, $attribute->startOffset()),
            Position::fromOffset($document, $attribute->endOffset()),
            $message,
        );
    }

    /** @param array<string, true> $reported */
    protected function reportAttributeOnce(
        Document $document,
        Attribute $attribute,
        RuleContext $context,
        array &$reported,
        string $kind,
        string $message,
    ): void {
        $key = $attribute->startOffset().'|'.$kind;
        if (isset($reported[$key])) {
            return;
        }
        $reported[$key] = true;

        $this->reportAttribute($document, $attribute, $context, $message);
    }
}
