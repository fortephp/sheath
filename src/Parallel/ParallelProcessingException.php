<?php

declare(strict_types=1);

namespace Forte\Sheath\Parallel;

use RuntimeException;
use Throwable;

/** @internal */
class ParallelProcessingException extends RuntimeException
{
    /**
     * @param  array<string>  $files
     */
    public function __construct(
        string $message,
        private readonly array $files = [],
        ?Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string>
     */
    public function getFiles(): array
    {
        return $this->files;
    }
}
