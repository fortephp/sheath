<?php

declare(strict_types=1);

namespace Forte\Sheath\Results;

use Forte\Ast\Document\Document;
use Forte\Ast\Node;

readonly class Position
{
    public function __construct(
        public int $offset,
        public int $line,
        public int $character
    ) {}

    public static function fromNode(Node $node, bool $end = false): self
    {
        return self::fromOffset(
            $node->getDocument(),
            $end ? $node->endOffset() : $node->startOffset(),
        );
    }

    public static function fromOffset(Document $document, int $offset): self
    {
        $lineAndColumn = $document->getLineAndColumnForOffset($offset);

        $line = $lineAndColumn['line'];
        $byteColumn = $lineAndColumn['column'];
        $prefixLength = max(0, $byteColumn - 1);
        $prefix = substr($document->getLine($line), 0, $prefixLength);
        $character = $byteColumn;

        if (strlen($prefix) === $prefixLength
            && function_exists('mb_strlen')
            && preg_match('//u', $prefix) === 1) {
            $character = mb_strlen($prefix, 'UTF-8') + 1;
        }

        return new self($offset, $line, $character);
    }
}
