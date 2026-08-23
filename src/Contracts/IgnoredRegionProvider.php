<?php

declare(strict_types=1);

namespace Forte\Sheath\Contracts;

use Forte\Sheath\Parsing\IgnoredRegion;

interface IgnoredRegionProvider
{
    /** A stable, globally unique provider identifier. */
    public function id(): string;

    /**
     * Return zero-based, half-open byte ranges in the original source.
     *
     * @return iterable<IgnoredRegion>
     */
    public function regions(string $source, string $filePath): iterable;

    /**
     * Return deterministic, JSON-serializable state that affects discovered regions.
     *
     * @return array<string, mixed>|string
     */
    public function cacheContext(): array|string;
}
