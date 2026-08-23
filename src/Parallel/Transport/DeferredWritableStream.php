<?php

declare(strict_types=1);

namespace Forte\Sheath\Parallel\Transport;

use Evenement\EventEmitter;
use React\Stream\WritableStreamInterface;

/**
 * @internal
 */
class DeferredWritableStream extends EventEmitter implements WritableStreamInterface
{
    private ?WritableStreamInterface $inner = null;

    /** @var array<string> */
    private array $buffer = [];

    private bool $closed = false;

    private bool $ended = false;

    public function attach(WritableStreamInterface $inner): void
    {
        if ($this->closed) {
            return;
        }

        $this->inner = $inner;

        $inner->on('drain', function (): void {
            $this->emit('drain');
        });

        $inner->on('error', function (mixed $error): void {
            $this->emit('error', [$error]);
        });

        $inner->on('close', function (): void {
            $this->close();
        });

        foreach ($this->buffer as $chunk) {
            $inner->write($chunk);
        }
        $this->buffer = [];

        if ($this->ended) {
            $inner->end();
        }
    }

    public function isWritable(): bool
    {
        return ! $this->closed && ! $this->ended;
    }

    public function write(mixed $data): bool
    {
        if (! $this->isWritable()) {
            return false;
        }

        if ($this->inner !== null) {
            return $this->inner->write($data);
        }

        $this->buffer[] = is_string($data) ? $data : '';

        return true;
    }

    public function end(mixed $data = null): void
    {
        if (! $this->isWritable()) {
            return;
        }

        if ($data !== null) {
            $this->write($data);
        }

        $this->ended = true;

        $this->inner?->end();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->buffer = [];

        if ($this->inner !== null) {
            $this->inner->close();
        }

        $this->emit('close');
        $this->removeAllListeners();
    }
}
