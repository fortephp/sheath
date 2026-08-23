<?php

declare(strict_types=1);

namespace Forte\Sheath\Contracts;

interface ReceivesRunContext
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function receiveRunContext(array $context): void;
}
