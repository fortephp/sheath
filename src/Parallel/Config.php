<?php

declare(strict_types=1);

namespace Forte\Sheath\Parallel;

use Fidry\CpuCoreCounter\CpuCoreCounter;
use Fidry\CpuCoreCounter\NumberOfCpuCoreNotFound;

/** @internal */
class Config
{
    private const MIN_FILES_FOR_PARALLEL = 10;

    private const DEFAULT_BATCHES_PER_WORKER = 6;

    private const DEFAULT_MAX_PROCESSES = 8;

    public function __construct(
        private readonly int $processCount,
        private readonly ?int $chunkSize = null,
    ) {}

    public static function detect(?int $processCount = null): self
    {
        $count = $processCount ?? self::detectCpuCores();

        return self::create($count);
    }

    public static function create(
        int $processCount,
        ?int $chunkSize = null,
    ): self {
        return new self(
            max(1, $processCount),
            $chunkSize !== null ? max(1, $chunkSize) : null,
        );
    }

    public static function detectCpuCores(): int
    {
        if (! class_exists(CpuCoreCounter::class)) {
            return 2;
        }

        try {
            $counter = new CpuCoreCounter;

            // -1 here to always leave at least one core available.
            return max(1, min(self::DEFAULT_MAX_PROCESSES, $counter->getCount() - 1));
        } catch (NumberOfCpuCoreNotFound) {
            return 2;
        }
    }

    public function getProcessCount(): int
    {
        return $this->processCount;
    }

    public function getWorkerCount(int $totalFiles): int
    {
        if ($totalFiles < 1) {
            return 0;
        }

        $batchCount = (int) ceil($totalFiles / $this->getChunkSize($totalFiles));

        return min($this->processCount, $batchCount);
    }

    public function getChunkSize(int $totalFiles): int
    {
        if ($this->chunkSize !== null) {
            return $this->chunkSize;
        }

        return max(
            1,
            min(20, (int) ceil($totalFiles / ($this->processCount * self::DEFAULT_BATCHES_PER_WORKER)))
        );
    }

    public function shouldRunInParallel(int $fileCount): bool
    {
        return $fileCount >= self::MIN_FILES_FOR_PARALLEL && $this->getWorkerCount($fileCount) > 1;
    }
}
