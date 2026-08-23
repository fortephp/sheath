<?php

declare(strict_types=1);

namespace Forte\Sheath\Parallel\Transport;

use React\EventLoop\LoopInterface;

/** @internal */
interface Transport
{
    public function spawn(string $command, LoopInterface $loop): WorkerChannel;

    /**
     * Safe to call multiple times.
     */
    public function close(): void;
}
