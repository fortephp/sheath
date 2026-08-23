<?php

declare(strict_types=1);

namespace Forte\Sheath\Analysis;

use Forte\Ast\Elements\ElementNode;

/** @internal */
final readonly class ReactiveElements
{
    /** @param list<ElementNode> $elements */
    public function __construct(private array $elements) {}

    /** @return list<ElementNode> */
    public function all(): array
    {
        return $this->elements;
    }
}
