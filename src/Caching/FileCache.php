<?php

declare(strict_types=1);

namespace Forte\Sheath\Caching;

use Forte\Sheath\Files\FileSystem;
use JsonException;

/** @internal */
class FileCache
{
    private const FORMAT_VERSION = 1;

    /** @var array<string, array{source_hash: string, data: mixed}> */
    protected array $cache = [];

    protected bool $enabled = false;

    public function __construct(protected string $cacheFile) {}

    public function enable(): void
    {
        $this->enabled = true;
        $this->load();
    }

    public function has(string $filePath, string $sourceContent): bool
    {
        return $this->hasHash($filePath, hash('xxh128', $sourceContent));
    }

    public function hasHash(string $filePath, string $sourceHash): bool
    {
        if (! $this->enabled) {
            return false;
        }

        if (! isset($this->cache[$filePath])) {
            return false;
        }

        $cached = $this->cache[$filePath];

        return hash_equals($cached['source_hash'], $sourceHash);
    }

    public function get(string $filePath): mixed
    {
        if (! $this->enabled || ! isset($this->cache[$filePath])) {
            return null;
        }

        return $this->cache[$filePath]['data'];
    }

    public function put(string $filePath, string $sourceContent, mixed $data): void
    {
        $this->putHash($filePath, hash('xxh128', $sourceContent), $data);
    }

    public function putHash(string $filePath, string $sourceHash, mixed $data): void
    {
        if (! $this->enabled) {
            return;
        }

        $this->cache[$filePath] = [
            'source_hash' => $sourceHash,
            'data' => $data,
        ];
    }

    /**
     * @throws JsonException
     */
    public function save(): bool
    {
        if (! $this->enabled) {
            return true;
        }

        $this->pruneMissingFiles();

        $json = json_encode([
            'version' => self::FORMAT_VERSION,
            'entries' => $this->cache,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return FileSystem::writeFileAtomically($this->cacheFile, $json);
    }

    protected function load(): void
    {
        if (! file_exists($this->cacheFile)) {
            return;
        }

        $contents = file_get_contents($this->cacheFile);

        if ($contents === false) {
            return;
        }

        try {
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        $entries = $this->cacheEntriesFromPayload($payload);
        if ($entries === null) {
            return;
        }

        $cache = [];

        foreach ($entries as $filePath => $entry) {
            $normalized = $this->normalizeCacheEntry($filePath, $entry);
            if ($normalized === null) {
                continue;
            }

            $cache[$normalized['filePath']] = [
                'source_hash' => $normalized['source_hash'],
                'data' => $normalized['data'],
            ];
        }

        $this->cache = $cache;
        $this->pruneMissingFiles();
    }

    /** @return array<mixed>|null */
    private function cacheEntriesFromPayload(mixed $payload): ?array
    {
        if (! is_array($payload) || ($payload['version'] ?? null) !== self::FORMAT_VERSION) {
            return null;
        }

        $entries = $payload['entries'] ?? null;

        return is_array($entries) ? $entries : null;
    }

    /** @return array{filePath: string, source_hash: string, data: mixed}|null */
    private function normalizeCacheEntry(mixed $filePath, mixed $entry): ?array
    {
        if (! is_string($filePath) || ! is_array($entry)) {
            return null;
        }

        $sourceHash = $entry['source_hash'] ?? null;
        if (! is_string($sourceHash) || ! array_key_exists('data', $entry)) {
            return null;
        }

        return [
            'filePath' => $filePath,
            'source_hash' => $sourceHash,
            'data' => $entry['data'],
        ];
    }

    private function pruneMissingFiles(): void
    {
        foreach (array_keys($this->cache) as $filePath) {
            if (! is_file($filePath)) {
                unset($this->cache[$filePath]);
            }
        }
    }

    public function clear(): void
    {
        $this->cache = [];

        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
    }
}
