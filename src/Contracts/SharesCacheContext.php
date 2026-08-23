<?php

declare(strict_types=1);

namespace Forte\Sheath\Contracts;

interface SharesCacheContext extends ProvidesCacheContext
{
    /**
     * @param  array<string, mixed>  $options
     * @return non-empty-string
     */
    public function cacheContextGroup(array $options): string;
}
