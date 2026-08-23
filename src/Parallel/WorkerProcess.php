<?php

declare(strict_types=1);

namespace Forte\Sheath\Parallel;

use Closure;
use Clue\React\NDJson\Decoder;
use Clue\React\NDJson\Encoder;
use Forte\Sheath\Parallel\Contracts\WorkerResult;
use Forte\Sheath\Parallel\Transport\WorkerChannel;
use Forte\Sheath\Serialization\InternalDataCodec;
use RuntimeException;
use Throwable;

/** @internal */
class WorkerProcess
{
    private const MAX_RECORD_LENGTH = 16 * 1024 * 1024;

    private readonly Encoder $input;

    private readonly Decoder $output;

    private bool $busy = false;

    private bool $terminated = false;

    private bool $protocolFailureHandled = false;

    private bool $exitNotified = false;

    /** @var array<string> */
    private array $pendingFiles = [];

    private ?Closure $onResult = null;

    private ?Closure $onError = null;

    private ?Closure $onComplete = null;

    private ?Closure $onExit = null;

    /**
     * @param  class-string<WorkerResult>  $resultClass
     */
    public function __construct(
        private readonly WorkerChannel $channel,
        private readonly string $resultClass,
    ) {
        $this->input = new Encoder($channel->getInput());
        $this->output = new Decoder(
            $channel->getOutput(),
            true,
            512,
            0,
            self::MAX_RECORD_LENGTH,
        );

        $this->output->on('data', function (mixed $data): void {
            if (is_array($data)) {
                /** @var array<string, mixed> $data */
                $this->handleData($data);
            }
        });

        $this->output->on('error', function (Throwable $error): void {
            $this->handleProtocolFailure('Worker output decode failed: '.$error->getMessage());
        });

        $this->input->on('error', function (Throwable $error): void {
            $this->handleProtocolFailure('Worker input encode failed: '.$error->getMessage());
        });

        $this->channel->on('error', function (mixed $message): void {
            $this->handleProtocolFailure(is_string($message) ? $message : 'Transport error');
        });

        $this->channel->getProcess()->on('exit', function (mixed $exitCode): void {
            $this->terminated = true;
            $wasBusy = $this->busy;
            $this->busy = false;

            if ($exitCode !== 0 && ! $this->protocolFailureHandled && $this->onError !== null) {
                $code = is_int($exitCode) ? (string) $exitCode : 'unknown';
                $message = "Worker exited with code {$code}";

                $stderr = $this->channel->readStderr();
                if ($stderr !== '') {
                    $message .= ': '.$stderr;
                }

                if (! empty($this->pendingFiles)) {
                    $message .= ' (unprocessed files: '.implode(', ', $this->pendingFiles).')';
                }

                ($this->onError)($message);
            }

            $this->pendingFiles = [];

            if ($wasBusy && $this->onComplete !== null) {
                // Let the pool re-evaluate completion after a mid-batch exit.
                ($this->onComplete)();
            }

            $this->notifyExit();
        });

        $stderr = $this->channel->getStderrStream();
        if ($stderr !== null) {
            $stderr->on('data', function (mixed $data): void {
                if ($this->onError !== null) {
                    $message = is_string($data) ? $data : 'unknown error';
                    ($this->onError)("Worker stderr: {$message}");
                }
            });
        }
    }

    public function isBusy(): bool
    {
        return $this->busy;
    }

    public function isTerminated(): bool
    {
        return $this->terminated;
    }

    public function isAvailable(): bool
    {
        return ! $this->busy && ! $this->terminated;
    }

    /**
     * @param  array<string>  $files
     */
    public function sendFiles(array $files): void
    {
        if ($this->terminated) {
            throw new RuntimeException('Cannot send files to terminated worker');
        }

        $this->busy = true;
        $this->pendingFiles = $files;

        $this->input->write(['files' => $files]);
    }

    public function shutdown(): void
    {
        if (! $this->terminated) {
            $this->input->write(['shutdown' => true]);
        }
    }

    public function terminate(): void
    {
        if (! $this->terminated) {
            $this->channel->getProcess()->terminate();
            $this->terminated = true;
        }
    }

    /**
     * @param  callable(WorkerResult): void  $callback
     */
    public function onResult(callable $callback): self
    {
        $this->onResult = $callback(...);

        return $this;
    }

    /**
     * @param  callable(string, ?string): void  $callback
     */
    public function onError(callable $callback): self
    {
        $this->onError = $callback(...);

        return $this;
    }

    /**
     * @param  callable(): void  $callback
     */
    public function onComplete(callable $callback): self
    {
        $this->onComplete = $callback(...);

        return $this;
    }

    /**
     * @param  callable(): void  $callback
     */
    public function onExit(callable $callback): self
    {
        $this->onExit = $callback(...);

        return $this;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function handleData(array $data): void
    {
        if (isset($data['result']) && is_array($data['result'])) {
            try {
                /** @var array<string, mixed> $resultData */
                $resultData = $data['result'];
                $result = ($this->resultClass)::fromArray(InternalDataCodec::decode($resultData));

                $this->removePendingFile($result->getFilePath());

                if ($this->onResult !== null) {
                    ($this->onResult)($result);
                }
            } catch (Throwable $e) {
                $this->handleProtocolFailure('Failed to decode result: '.$e->getMessage());

                return;
            }
        }

        if (isset($data['error']) && is_string($data['error'])) {
            $file = isset($data['file']) && is_string($data['file']) ? $data['file'] : null;

            if ($file !== null) {
                $this->removePendingFile($file);
            }

            if ($this->onError !== null) {
                ($this->onError)($data['error'], $file);
            }
        }

        if (isset($data['complete']) && $data['complete'] === true) {
            $this->busy = false;
            $this->pendingFiles = [];

            if ($this->onComplete !== null) {
                ($this->onComplete)();
            }
        }

        // Assign the next batch as soon as every file in this one has a result.
        if ($this->busy && empty($this->pendingFiles)) {
            $this->busy = false;

            if ($this->onComplete !== null) {
                ($this->onComplete)();
            }
        }
    }

    private function removePendingFile(string $file): void
    {
        $this->pendingFiles = array_filter(
            $this->pendingFiles,
            fn (string $f) => $f !== $file
        );
    }

    private function handleProtocolFailure(string $message): void
    {
        if ($this->protocolFailureHandled) {
            return;
        }

        $this->protocolFailureHandled = true;
        $wasBusy = $this->busy;
        $this->busy = false;
        $this->pendingFiles = [];

        if ($this->onError !== null) {
            ($this->onError)($message);
        }

        $this->terminate();

        if ($wasBusy && $this->onComplete !== null) {
            ($this->onComplete)();
        }

        $this->notifyExit();
    }

    private function notifyExit(): void
    {
        if ($this->exitNotified) {
            return;
        }

        $this->exitNotified = true;

        if ($this->onExit !== null) {
            ($this->onExit)();
        }
    }
}
