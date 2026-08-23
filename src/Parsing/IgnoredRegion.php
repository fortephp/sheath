<?php

declare(strict_types=1);

namespace Forte\Sheath\Parsing;

use InvalidArgumentException;

final readonly class IgnoredRegion
{
    public function __construct(
        public int $startOffset,
        public int $endOffset,
    ) {
        if ($startOffset < 0 || $endOffset < $startOffset) {
            throw new InvalidArgumentException('Ignored region offsets are invalid.');
        }
    }
}
