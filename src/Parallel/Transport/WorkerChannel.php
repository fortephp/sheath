<?php

declare(strict_types=1);

namespace Forte\Sheath\Parallel\Transport;

use Evenement\EventEmitter;
use React\ChildProcess\Process;
use React\Stream\ReadableStreamInterface;
use React\Stream\WritableStreamInterface;

/** @internal */
class WorkerChannel extends EventEmitter
{
    public function __construct(
        private readonly Process $process,
        private readonly WritableStreamInterface $input,
        private readonly ReadableStreamInterface $output,
        private readonly ?ReadableStreamInterface $stderrStream = null,
        private readonly ?string $stderrFile = null,
    ) {}

    public function getProcess(): Process
    {
        return $this->process;
    }

    public function getInput(): WritableStreamInterface
    {
        return $this->input;
    }

    public function getOutput(): ReadableStreamInterface
    {
        return $this->output;
    }

    public function getStderrStream(): ?ReadableStreamInterface
    {
        return $this->stderrStream;
    }

    public function readStderr(): string
    {
        if ($this->stderrFile === null) {
            return '';
        }

        if (! is_file($this->stderrFile)) {
            return '';
        }

        $contents = @file_get_contents($this->stderrFile);

        return $contents === false ? '' : trim($contents);
    }
}
