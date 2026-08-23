<?php

declare(strict_types=1);

namespace Forte\Sheath\Parallel\Contracts;

use Throwable;

/** @internal */
interface WorkerProcessor
{
    /**
     * @throws Throwable
     */
    public function process(string $filePath, WorkerConfig $config): WorkerResult;
}
