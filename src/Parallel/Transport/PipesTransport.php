<?php

declare(strict_types=1);

namespace Forte\Sheath\Parallel\Transport;

use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;
use RuntimeException;

/**
 * @internal
 */
class PipesTransport implements Transport
{
    public function spawn(string $command, LoopInterface $loop): WorkerChannel
    {
        $process = new Process($command);
        $process->start($loop);

        $stdin = $process->stdin;
        if (! $stdin instanceof WritableStreamInterface) {
            throw new RuntimeException('Worker process has no stdin');
        }

        $stdout = $process->stdout;
        if (! $stdout instanceof ReadableStreamInterface) {
            throw new RuntimeException('Worker process has no stdout');
        }

        $stderr = $process->stderr;

        return new WorkerChannel(
            $process,
            $stdin,
            $stdout,
            $stderr instanceof ReadableStreamInterface ? $stderr : null,
        );
    }

    public function close(): void {}
}
