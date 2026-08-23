<?php

declare(strict_types=1);

namespace Forte\Sheath\Analysis;

/** @internal */
final class ComponentRequiredPropsCache
{
    /** @var array<string, array{hash: string, props: list<string>|null}> */
    private array $entries = [];

    /**
     * @param  callable(): (list<string>|null)  $resolve
     * @return list<string>|null
     */
    public function remember(string $path, string $source, callable $resolve): ?array
    {
        $hash = hash('xxh128', $source);
        $entry = $this->entries[$path] ?? null;
        if ($entry !== null && hash_equals($entry['hash'], $hash)) {
            return $entry['props'];
        }

        $props = $resolve();
        $this->entries[$path] = ['hash' => $hash, 'props' => $props];

        return $props;
    }
}
