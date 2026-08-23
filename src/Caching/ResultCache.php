<?php

declare(strict_types=1);

namespace Forte\Sheath\Caching;

use Forte\Sheath\Files\FileSystem;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Serialization\InternalDataCodec;
use InvalidArgumentException;
use JsonException;
use WeakMap;

/** @internal */
class ResultCache
{
    private const CONTEXT_SCHEMA_VERSION = 2;

    protected FileCache $cache;

    protected string $contextHash = 'default';

    /** @var WeakMap<LintResult, string>|null */
    private ?WeakMap $inferredSourceHashes = null;

    public function __construct(string $cacheFile = '.sheath-cache')
    {
        $this->cache = new FileCache($cacheFile);
    }

    public function setPath(string $cacheFile): static
    {
        $this->cache = new FileCache($cacheFile);

        return $this;
    }

    public function enable(): static
    {
        $this->cache->enable();

        return $this;
    }

    /**
     * @param  array<string, mixed>|string  $context
     *
     * @throws JsonException
     */
    public function setContext(array|string $context): static
    {
        $payload = is_string($context)
            ? $context
            : json_encode(
                $this->normalizeContext($context),
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );

        $this->contextHash = hash('xxh128', self::CONTEXT_SCHEMA_VERSION.'|'.$payload);

        return $this;
    }

    public function has(string $filePath, ?string $sourceContent = null): bool
    {
        $sourceHash = $this->getSourceHash($filePath, $sourceContent);
        if ($sourceHash === null) {
            return false;
        }

        return $this->cache->hasHash($filePath, $this->getCacheHash($sourceHash));
    }

    public function get(string $filePath): ?LintResult
    {
        $result = $this->cache->get($filePath);
        if (! is_array($result)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $result */
            return LintResult::fromArray(InternalDataCodec::decode($result));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function put(string $filePath, LintResult $result, ?string $sourceContent = null): void
    {
        $sourceHash = $result->sourceHash;

        if ($sourceHash === null) {
            $inferred = $this->inferredSourceHashes ??= new WeakMap;

            if ($sourceContent !== null) {
                $sourceHash = hash('xxh128', $sourceContent);
                $inferred[$result] = $sourceHash;
            } elseif (isset($inferred[$result])) {
                $sourceHash = $inferred[$result];
            } else {
                $sourceHash = $this->getSourceHash($filePath);
                if ($sourceHash !== null) {
                    $inferred[$result] = $sourceHash;
                }
            }
        }

        if ($sourceHash === null) {
            return;
        }

        $this->cache->putHash(
            $filePath,
            $this->getCacheHash($sourceHash),
            InternalDataCodec::encode($result->toArray())
        );
    }

    public function save(): bool
    {
        return $this->cache->save();
    }

    public function clear(): void
    {
        $this->cache->clear();
    }

    private function getSourceContent(string $filePath): ?string
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            return null;
        }

        return FileSystem::readFile($filePath);
    }

    private function getSourceHash(string $filePath, ?string $sourceContent = null): ?string
    {
        $source = $sourceContent ?? $this->getSourceContent($filePath);
        if ($source === null) {
            return null;
        }

        return hash('xxh128', $source);
    }

    private function getCacheHash(string $sourceHash): string
    {
        return hash('xxh128', $this->contextHash."\0".$sourceHash);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalizeContext(array $context): array
    {
        ksort($context);

        foreach ($context as $key => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $context[$key] = $this->normalizeContext($value);
            }
        }

        return $context;
    }
}
