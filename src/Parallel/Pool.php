<?php

declare(strict_types=1);

namespace Forte\Sheath\Parallel;

use Closure;
use Forte\Sheath\Parallel\Contracts\WorkerConfig;
use Forte\Sheath\Parallel\Contracts\WorkerResult;
use React\EventLoop\LoopInterface;
use RuntimeException;
use Throwable;

/** @internal */
class Pool
{
    /** @var array<WorkerProcess> */
    private array $workers = [];

    /** @var array<array<string>> */
    private array $pendingBatches = [];

    /** @var array<WorkerResult> */
    private array $results = [];

    /** @var array<string> */
    private array $errors = [];

    private ?Closure $onProgress = null;

    private int $processedCount = 0;

    private int $totalFiles = 0;

    private bool $shuttingDown = false;

    public function __construct(
        private readonly Config $config,
        private readonly LoopInterface $loop,
        private readonly Factory $factory,
    ) {}

    public function start(WorkerConfig $workerConfig, int $totalFiles): void
    {
        $this->errors = [];
        $count = $this->config->getWorkerCount($totalFiles);

        for ($i = 0; $i < $count; $i++) {
            try {
                $worker = $this->factory->create($workerConfig, $this->loop);
                $this->setupWorkerCallbacks($worker);
                $this->workers[] = $worker;
            } catch (Throwable $e) {
                $this->errors[] = "Failed to start worker {$i}: ".$e->getMessage();
            }
        }

        if (empty($this->workers)) {
            throw new RuntimeException('Failed to start any worker processes');
        }
    }

    private function setupWorkerCallbacks(WorkerProcess $worker): void
    {
        $worker->onResult(function (WorkerResult $result): void {
            $this->results[] = $result;
            $this->processedCount++;
            $this->notifyProgress();
        });

        $worker->onError(function (string $error, ?string $file = null): void {
            if ($file !== null) {
                $this->errors[] = "Error processing {$file}: {$error}";
            } else {
                $this->errors[] = $error;
            }
        });

        $worker->onComplete(function () use ($worker): void {
            $this->assignWork($worker);
        });

        $worker->onExit(function (): void {
            // A worker that exits (cleanly or not) can never pick up more
            // work; without this check a crash while it was the only busy
            // worker would leave the loop running forever.
            $this->checkCompletion();
        });
    }

    /**
     * @param  array<string>  $files
     * @return array<WorkerResult>
     */
    public function process(array $files): array
    {
        $this->totalFiles = count($files);
        $this->processedCount = 0;
        $this->results = [];

        /** @var int<1, max> $chunkSize */
        $chunkSize = $this->config->getChunkSize($this->totalFiles);
        $this->pendingBatches = array_chunk($files, $chunkSize);

        foreach ($this->workers as $worker) {
            if (! $worker->isAvailable()) {
                continue;
            }

            $this->assignWork($worker);
        }

        $this->loop->run();

        return $this->results;
    }

    private function assignWork(WorkerProcess $worker): void
    {
        if (! $worker->isAvailable()) {
            return;
        }

        if (empty($this->pendingBatches)) {
            $this->checkCompletion();

            return;
        }

        $batch = array_shift($this->pendingBatches);
        if (! empty($batch)) {
            $worker->sendFiles($batch);
        }
    }

    private function checkCompletion(): void
    {
        if ($this->shuttingDown) {
            return;
        }

        if ($this->getActiveWorkerCount() === 0) {
            // Every worker is gone; remaining batches can never be
            // processed. Stop rather than hang because the errors collected
            // from the exits tell the caller what happened.
            $this->loop->stop();

            return;
        }

        if (! empty($this->pendingBatches)) {
            return;
        }

        foreach ($this->workers as $worker) {
            if ($worker->isBusy()) {
                return;
            }
        }

        $this->loop->stop();
    }

    /**
     * Allow five seconds for clean exits before terminating live workers.
     */
    public function shutdown(): void
    {
        $this->shuttingDown = true;

        $hasLiveWorkers = false;

        foreach ($this->workers as $worker) {
            if (! $worker->isTerminated()) {
                $worker->shutdown();
                $hasLiveWorkers = true;
            }
        }

        if ($hasLiveWorkers) {
            $deadline = $this->loop->addTimer(5.0, function (): void {
                $this->loop->stop();
            });

            $poll = $this->loop->addPeriodicTimer(0.05, function (): void {
                if ($this->getActiveWorkerCount() === 0) {
                    $this->loop->stop();
                }
            });

            $this->loop->run();

            $this->loop->cancelTimer($deadline);
            $this->loop->cancelTimer($poll);
        }

        foreach ($this->workers as $worker) {
            $worker->terminate();
        }
    }

    public function terminate(): void
    {
        foreach ($this->workers as $worker) {
            $worker->terminate();
        }
    }

    public function getActiveWorkerCount(): int
    {
        return count(array_filter(
            $this->workers,
            fn (WorkerProcess $worker) => ! $worker->isTerminated()
        ));
    }

    /**
     * @return array<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * @param  callable(int, int): void  $callback  Receives (processed, total)
     */
    public function onProgress(callable $callback): self
    {
        $this->onProgress = $callback(...);

        return $this;
    }

    private function notifyProgress(): void
    {
        if ($this->onProgress !== null) {
            ($this->onProgress)($this->processedCount, $this->totalFiles);
        }
    }
}
