<?php

declare(strict_types=1);

namespace Forte\Sheath\Contracts;

interface ProvidesCacheContext
{
    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>|string
     */
    public function cacheContext(array $options): array|string;
}
