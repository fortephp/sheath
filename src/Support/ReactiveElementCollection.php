<?php

declare(strict_types=1);

namespace Forte\Sheath\Support;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;

/** @internal */
final class ReactiveElementCollection
{
    /**
     * @return list<ElementNode>
     */
    public static function clientElements(Document $document): array
    {
        return array_values($document->queryElements()->all());
    }

    /** @return list<ElementNode> */
    public static function livewireIdentityElements(Document $document): array
    {
        $elements = self::clientElements($document);

        foreach ($document->queryComponents() as $component) {
            if (strtolower($component->getPrefix()) === 'livewire:') {
                $elements[] = $component;
            }
        }

        return $elements;
    }
}
