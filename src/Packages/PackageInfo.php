<?php

declare(strict_types=1);

namespace Forte\Sheath\Packages;

/** @internal */
readonly class PackageInfo
{
    public function __construct(
        public ?string $version,
        public bool $isExactVersion,
        public ?string $aliasVersion = null,
        public ?string $versionRanges = null,
    ) {}
}
