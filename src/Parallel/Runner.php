<?php

declare(strict_types=1);

namespace Forte\Sheath\Parallel;

use Closure;
use Clue\React\NDJson\Encoder;
use Forte\Sheath\Parallel\Contracts\WorkerConfig;
use Forte\Sheath\Parallel\Contracts\WorkerResult;
use React\ChildProcess\Process;
use React\EventLoop\Loop;
use Throwable;

/** @internal */
class Runner
{
    private readonly Config $parallelConfig;

    private ?Closure $onProgress = null;

    private ?string $commandTemplate = null;

    /** @var array<string> */
    private array $errors = [];

    /**
     * @param  class-string<WorkerResult>  $resultClass
     */
    public function __construct(
        private readonly string $resultClass,
        private readonly string $workerCommand,
        ?Config $parallelConfig = null,
    ) {
        $this->parallelConfig = $parallelConfig ?? Config::detect();
    }

    /**
     * @param  array<string>  $files
     * @return array<WorkerResult>
     *
     * @throws ParallelProcessingException
     */
    public function run(array $files, WorkerConfig $config): array
    {
        if (! self::isAvailable()) {
            throw new ParallelProcessingException(
                'Parallel processing dependencies are not installed.',
                $files
            );
        }

        if (! $this->parallelConfig->shouldRunInParallel(count($files))) {
            throw new ParallelProcessingException(
                'Parallel processing not available, use sequential mode',
                $files
            );
        }

        $loop = Loop::get();
        $factory = new Factory($this->resultClass, $this->workerCommand);

        if ($this->commandTemplate !== null) {
            $factory->withCommandTemplate($this->commandTemplate);
        }

        $pool = new Pool($this->parallelConfig, $loop, $factory);

        if ($this->onProgress !== null) {
            $pool->onProgress($this->onProgress);
        }

        try {
            $pool->start($config, count($files));

            $results = $pool->process($files);

            $this->errors = array_merge($this->errors, $pool->getErrors());

            $pool->shutdown();

            return $results;
        } catch (Throwable $e) {
            $pool->terminate();
            $this->errors[] = 'Parallel processing failed: '.$e->getMessage();

            throw new ParallelProcessingException(
                'Parallel processing failed: '.$e->getMessage(),
                $files,
                $e
            );
        } finally {
            $factory->close();
        }
    }

    public function withCommandTemplate(string $template): self
    {
        $this->commandTemplate = $template;

        return $this;
    }

    /**
     * @param  callable(int, int): void  $callback  Receives (processed, total)
     */
    public function onProgress(callable $callback): void
    {
        $this->onProgress = $callback(...);
    }

    /**
     * @return array<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public static function isAvailable(): bool
    {
        if (! class_exists(Loop::class)) {
            return false;
        }

        if (! class_exists(Process::class)) {
            return false;
        }

        if (! class_exists(Encoder::class)) {
            return false;
        }

        if (! function_exists('proc_open')) {
            return false;
        }

        return true;
    }
}
