<?php

declare(strict_types=1);

use Forte\Sheath\Parallel\Config;
use Forte\Sheath\Parallel\Contracts\WorkerConfig;
use Forte\Sheath\Parallel\Contracts\WorkerResult;
use Forte\Sheath\Parallel\Factory;
use Forte\Sheath\Parallel\ParallelProcessingException;
use Forte\Sheath\Parallel\Pool;
use Forte\Sheath\Parallel\Runner;
use Forte\Sheath\Parallel\WorkerProcess;
use React\EventLoop\StreamSelectLoop;

class TestWorkerResult implements WorkerResult
{
    public function __construct(private readonly string $filePath = '') {}

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function toArray(): array
    {
        return ['file' => $this->filePath];
    }

    public static function fromArray(array $data): static
    {
        return new static($data['file'] ?? '');
    }
}

class TestWorkerConfig implements WorkerConfig
{
    public function toArray(): array
    {
        return [];
    }

    public static function fromArray(array $data): static
    {
        return new self;
    }
}

describe('Runner', function (): void {
    describe('run', function (): void {
        it('throws exception when file count is too small', function (): void {
            $config = new TestWorkerConfig;

            $parallelConfig = Config::create(4);
            $runner = new Runner(TestWorkerResult::class, 'test:worker', $parallelConfig);

            $files = ['file1.php', 'file2.php', 'file3.php', 'file4.php', 'file5.php'];

            expect(fn () => $runner->run($files, $config))
                ->toThrow(ParallelProcessingException::class);
        });

        it('throws exception with files when parallel not worthwhile', function (): void {
            $config = new TestWorkerConfig;

            $parallelConfig = Config::create(1);
            $runner = new Runner(TestWorkerResult::class, 'test:worker', $parallelConfig);

            $files = array_map(fn ($i) => "file{$i}.php", range(1, 20));

            try {
                $runner->run($files, $config);
                test()->fail('Expected exception to be thrown');
            } catch (ParallelProcessingException $e) {
                expect($e->getFiles())->toBe($files);
            }
        });
    });
});

describe('Pool', function (): void {
    it('preserves worker startup errors while processing with the workers that started', function (): void {
        $factory = Mockery::mock(Factory::class);
        $worker = Mockery::mock(WorkerProcess::class);

        $worker->shouldReceive('onResult', 'onError', 'onComplete', 'onExit')->andReturnSelf();
        $worker->shouldReceive('isAvailable')->andReturnFalse();

        $factory->shouldReceive('create')
            ->once()
            ->ordered()
            ->andThrow(new RuntimeException('startup probe failed'));
        $factory->shouldReceive('create')
            ->once()
            ->ordered()
            ->andReturn($worker);

        $pool = new Pool(Config::create(2, 1), new StreamSelectLoop, $factory);
        $pool->start(new TestWorkerConfig, 2);
        $pool->process(['file.php']);

        expect($pool->getErrors())->toHaveCount(1);
    });

    it('does not start more workers than there are batches', function (): void {
        $factory = Mockery::mock(Factory::class);
        $worker = Mockery::mock(WorkerProcess::class);

        $worker->shouldReceive('onResult', 'onError', 'onComplete', 'onExit')->andReturnSelf();
        $factory->shouldReceive('create')->once()->andReturn($worker);

        $pool = new Pool(Config::create(1000, 20), new StreamSelectLoop, $factory);
        $pool->start(new TestWorkerConfig, 10);
    });
});
