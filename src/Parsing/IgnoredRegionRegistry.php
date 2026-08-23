<?php

declare(strict_types=1);

namespace Forte\Sheath\Parsing;

use Closure;
use Forte\Sheath\Contracts\IgnoredRegionProvider;
use InvalidArgumentException;

final class IgnoredRegionRegistry
{
    /** @var array<string, IgnoredRegionProvider> */
    private array $providers = [];

    /** @var (Closure(class-string<IgnoredRegionProvider>): IgnoredRegionProvider)|null */
    private ?Closure $resolver = null;

    /** @param Closure(class-string<IgnoredRegionProvider>): IgnoredRegionProvider $resolver */
    public function setResolver(Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    /** @param class-string<IgnoredRegionProvider>|IgnoredRegionProvider $provider */
    public function register(string|IgnoredRegionProvider $provider): void
    {
        $instance = is_string($provider) ? $this->resolve($provider) : $provider;
        $id = trim($instance->id());

        if ($id === '') {
            throw new InvalidArgumentException('Ignored region provider IDs must not be empty.');
        }

        $registered = $this->providers[$id] ?? null;
        if ($registered !== null) {
            if ($registered::class === $instance::class
                && $registered->cacheContext() === $instance->cacheContext()) {
                return;
            }

            $registeredClass = $registered::class;
            throw new InvalidArgumentException(
                "Ignored region provider ID [{$id}] is already registered by [{$registeredClass}]."
            );
        }

        $this->providers[$id] = $instance;
    }

    /** @return array<string, IgnoredRegionProvider> */
    public function all(): array
    {
        return $this->providers;
    }

    /** @return list<IgnoredRegion> */
    public function regions(string $source, string $filePath): array
    {
        $sourceLength = strlen($source);
        $regions = [];

        foreach ($this->providers as $providerId => $provider) {
            foreach ($provider->regions($source, $filePath) as $region) {
                if (! $region instanceof IgnoredRegion) {
                    throw new InvalidArgumentException(
                        "Ignored region provider [{$providerId}] must return ".IgnoredRegion::class.' instances.'
                    );
                }

                if ($region->endOffset > $sourceLength) {
                    throw new InvalidArgumentException(
                        "Ignored region provider [{$providerId}] returned a range beyond the source length."
                    );
                }

                if ($region->startOffset !== $region->endOffset) {
                    $regions[] = $region;
                }
            }
        }

        usort($regions, static fn (IgnoredRegion $left, IgnoredRegion $right): int => [
            $left->startOffset,
            $left->endOffset,
        ] <=> [
            $right->startOffset,
            $right->endOffset,
        ]);

        return $this->merge($regions);
    }

    /**
     * @param  list<IgnoredRegion>  $regions
     * @return list<IgnoredRegion>
     */
    private function merge(array $regions): array
    {
        $merged = [];

        foreach ($regions as $region) {
            $lastIndex = array_key_last($merged);
            $last = $lastIndex === null ? null : $merged[$lastIndex];

            if ($last === null || $region->startOffset > $last->endOffset) {
                $merged[] = $region;

                continue;
            }

            $merged[$lastIndex] = new IgnoredRegion(
                $last->startOffset,
                max($last->endOffset, $region->endOffset),
            );
        }

        return $merged;
    }

    /** @param class-string<IgnoredRegionProvider> $provider */
    private function resolve(string $provider): IgnoredRegionProvider
    {
        if (! is_subclass_of($provider, IgnoredRegionProvider::class)) {
            throw new InvalidArgumentException('Ignored region provider must implement '.IgnoredRegionProvider::class.'.');
        }

        return $this->resolver !== null ? ($this->resolver)($provider) : new $provider;
    }
}
