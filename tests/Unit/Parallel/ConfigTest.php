<?php

declare(strict_types=1);

use Forte\Sheath\Parallel\Config;

describe('Config', function (): void {
    describe('create', function (): void {
        it('enforces minimum values', function (): void {
            $config = Config::create(0, 0);

            expect($config->getProcessCount())->toBe(1)
                ->and($config->getChunkSize(100))->toBe(1);
        });
    });

    describe('detect', function (): void {
        it('detects CPU cores when no count specified', function (): void {
            $config = Config::detect();

            expect($config->getProcessCount())->toBeGreaterThanOrEqual(1)
                ->and($config->getProcessCount())->toBeLessThanOrEqual(8);
        });

        it('uses specified count when provided', function (): void {
            $config = Config::detect(8);

            expect($config->getProcessCount())->toBe(8);
        });

        it('enforces the minimum for an explicitly detected process count', function (): void {
            $config = Config::detect(0);

            expect($config->getProcessCount())->toBe(1)
                ->and($config->getChunkSize(10))->toBeGreaterThanOrEqual(1);
        });
    });

    describe('getChunkSize', function (): void {
        it('returns explicit chunk size when set', function (): void {
            $config = Config::create(4, 15);

            expect($config->getChunkSize(10))->toBe(15)
                ->and($config->getChunkSize(1000))->toBe(15);
        });

        it('targets ~6 batches per worker when auto-calculating', function (): void {
            $config = Config::create(4);
            $chunkSize = $config->getChunkSize(240);

            expect($chunkSize)->toBe(10);
        });

        it('caps auto-calculated chunk size at 20', function (): void {
            $config = Config::create(1);
            $chunkSize = $config->getChunkSize(1000);

            expect($chunkSize)->toBe(20);
        });
    });

    describe('getWorkerCount', function (): void {
        it('caps workers to the number of available batches', function (): void {
            expect(Config::create(1000)->getWorkerCount(10))->toBe(10)
                ->and(Config::create(1000, 20)->getWorkerCount(10))->toBe(1)
                ->and(Config::create(4, 20)->getWorkerCount(100))->toBe(4)
                ->and(Config::create(4)->getWorkerCount(0))->toBe(0);
        });
    });

    describe('shouldRunInParallel', function (): void {
        it('honors the file threshold and available worker count', function (): void {
            $config = Config::create(4);

            expect($config->shouldRunInParallel(5))->toBeFalse()
                ->and($config->shouldRunInParallel(9))->toBeFalse()
                ->and($config->shouldRunInParallel(10))->toBeTrue()
                ->and(Config::create(1)->shouldRunInParallel(100))->toBeFalse();
        });
    });
});
