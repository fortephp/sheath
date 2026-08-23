<?php

declare(strict_types=1);

use Forte\Sheath\Contracts\IgnoredRegionProvider;
use Forte\Sheath\Parsing\IgnoredRegion;
use Forte\Sheath\Parsing\IgnoredRegionRegistry;

final readonly class FixedIgnoredRegionProvider implements IgnoredRegionProvider
{
    /** @param iterable<IgnoredRegion> $regions */
    public function __construct(
        private iterable $regions,
        private string $providerId = 'fixed',
        private array|string $context = 'v1',
    ) {}

    public function id(): string
    {
        return $this->providerId;
    }

    public function regions(string $source, string $filePath): iterable
    {
        return $this->regions;
    }

    public function cacheContext(): array|string
    {
        return $this->context;
    }
}

it('sorts and merges overlapping adjacent and nested ignored regions', function (): void {
    $registry = new IgnoredRegionRegistry;
    $registry->register(new FixedIgnoredRegionProvider([
        new IgnoredRegion(8, 10),
        new IgnoredRegion(2, 5),
        new IgnoredRegion(4, 8),
        new IgnoredRegion(3, 4),
        new IgnoredRegion(10, 10),
    ]));

    expect($registry->regions('0123456789', 'view.blade.php'))
        ->toEqual([new IgnoredRegion(2, 10)]);
});

it('rejects invalid and out-of-bounds ranges', function (): void {
    expect(fn () => new IgnoredRegion(-1, 1))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new IgnoredRegion(2, 1))
        ->toThrow(InvalidArgumentException::class);

    $registry = new IgnoredRegionRegistry;
    $registry->register(new FixedIgnoredRegionProvider([new IgnoredRegion(0, 4)]));

    expect(fn () => $registry->regions('abc', 'view.blade.php'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects non-region provider results', function (): void {
    $registry = new IgnoredRegionRegistry;
    $registry->register(new FixedIgnoredRegionProvider(['not-a-region']));

    expect(fn () => $registry->regions('abc', 'view.blade.php'))
        ->toThrow(InvalidArgumentException::class);
});

it('makes identical registration idempotent and rejects conflicting IDs', function (): void {
    $registry = new IgnoredRegionRegistry;
    $registry->register(new FixedIgnoredRegionProvider([], context: ['mode' => 'one']));
    $registry->register(new FixedIgnoredRegionProvider([], context: ['mode' => 'one']));

    expect($registry->all())->toHaveCount(1)
        ->and(fn () => $registry->register(
            new FixedIgnoredRegionProvider([], context: ['mode' => 'two'])
        ))->toThrow(InvalidArgumentException::class);
});
