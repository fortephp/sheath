<?php

declare(strict_types=1);

namespace Forte\Sheath\Results;

readonly class FixResult
{
    /**
     * @param  array<array{Fix, Fix}>  $overlappingFixes
     */
    public function __construct(
        public string $content,
        public int $appliedCount,
        public int $skippedCount = 0,
        public bool $hasOverlaps = false,
        public array $overlappingFixes = [],
    ) {}

    public function allApplied(): bool
    {
        return $this->skippedCount === 0 && ! $this->hasOverlaps;
    }
}
