<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Parallel\Contracts\WorkerProcessor;
use Forte\Sheath\Parallel\WorkerCommand;

class PartialFailureStream
{
    /** @var resource|null */
    public $context;

    public static int $writes = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$writes = 0;

        return true;
    }

    public function stream_write(string $data): int
    {
        self::$writes++;

        return self::$writes === 1 ? min(7, strlen($data)) : 0;
    }

    public function stream_flush(): bool
    {
        return true;
    }
}

class TestWorkerCommand extends WorkerCommand
{
    protected function getProcessor(): WorkerProcessor
    {
        throw new LogicException('Not used by this test.');
    }

    protected function getConfigClass(): string
    {
        return Config::class;
    }

    /** @param array<string, mixed> $data */
    public function writeRecord(array $data): void
    {
        $this->writeOutput($data);
    }

    /** @param resource $output */
    public function useOutput($output): void
    {
        $property = new ReflectionProperty(WorkerCommand::class, 'workerOutput');
        $property->setValue($this, $output);
    }
}

it('fails instead of silently emitting a partial worker record', function (): void {
    stream_wrapper_register('sheathpartialwrite', PartialFailureStream::class);
    $stream = fopen('sheathpartialwrite://worker', 'w');
    expect($stream)->not->toBeFalse();

    try {
        $command = new TestWorkerCommand;
        $command->useOutput($stream);

        expect(fn () => $command->writeRecord(['result' => str_repeat('x', 100)]))
            ->toThrow(RuntimeException::class);
    } finally {
        fclose($stream);
        stream_wrapper_unregister('sheathpartialwrite');
    }
});
