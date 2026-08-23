<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Concerns;

use Forte\Ast\Components\ComponentNode;
use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Support\ReactiveAttributeSemantics;

/** @internal */
trait DetectsDirectiveAttributeCollisions
{
    protected function directiveAttributeCollision(
        Attribute $attribute,
        Document $document,
        ?ComponentNode $component = null,
    ): ?DirectiveNode {
        if ($component !== null && $this->isLivewireCompiledListenerOwner($component)) {
            return null;
        }

        return $this->directiveAttributeListener($attribute, $document);
    }

    protected function directiveAttributeListener(
        Attribute $attribute,
        Document $document,
    ): ?DirectiveNode {

        if (! $attribute->isBladeConstruct()) {
            return null;
        }

        $construct = $attribute->getBladeConstruct();
        $directive = match (true) {
            $construct instanceof DirectiveNode => $construct,
            $construct instanceof DirectiveBlockNode => $construct->startDirective(),
            default => null,
        };

        if ($directive === null || $directive->startOffset() < 0 || $directive->hasArguments()) {
            return null;
        }

        $name = $directive->nameText();
        if (! $document->getDirectivesRegistry()->hasExplicitDirective($name)) {
            return null;
        }

        $afterName = $directive->startOffset() + 1 + strlen($name);

        return preg_match(
            '/\G(?:\.[\w.-]+|[-:][\w:.-]*)?[ \t\n\f\r]*=[ \t\n\f\r]*/',
            $document->source(),
            $matches,
            0,
            $afterName,
        ) === 1 ? $directive : null;
    }

    /** @return array<int, true> */
    protected function directiveAttributeCollisionIndexes(Document $document): array
    {
        $elements = [
            ...iterator_to_array($document->queryElements()),
            ...iterator_to_array($document->queryComponents()),
        ];

        return $this->directiveAttributeCollisionIndexesForElements($document, $elements);
    }

    /**
     * @param  iterable<ElementNode>  $elements
     * @return array<int, true>
     */
    protected function directiveAttributeCollisionIndexesForElements(Document $document, iterable $elements): array
    {
        $indexes = [];

        foreach ($elements as $element) {
            foreach ($element->attributes() as $attribute) {
                $component = $element instanceof ComponentNode ? $element : null;
                $directive = $component !== null && $this->isLivewireCompiledListenerOwner($component)
                    ? $this->directiveAttributeListener($attribute, $document)
                    : $this->directiveAttributeCollision($attribute, $document, $component);
                if ($directive !== null) {
                    $indexes[$directive->index()] = true;
                }
            }
        }

        return $indexes;
    }

    private function isLivewireCompiledListenerOwner(ComponentNode $component): bool
    {
        return strtolower($component->getPrefix()) === 'livewire:'
            && ReactiveAttributeSemantics::livewireCompilerIsInstalled();
    }
}
