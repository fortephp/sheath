<?php

declare(strict_types=1);

namespace Forte\Sheath\Analysis;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Illuminate\Support\Collection;

/** @internal */
final readonly class DocumentElements
{
    /** @param Collection<int, ElementNode> $all */
    private function __construct(private Collection $all) {}

    public static function fromDocument(Document $document): self
    {
        return new self($document->queryElements()->collect());
    }

    /** @return Collection<int, ElementNode> */
    public function all(): Collection
    {
        return $this->all;
    }
}
