<?php

declare(strict_types=1);

use Forte\Sheath\Analysis\ComponentRequiredPropsCache;

it('shares parsed component props until the source changes', function (): void {
    $cache = new ComponentRequiredPropsCache;
    $resolutions = 0;

    $resolve = function () use (&$resolutions): array {
        $resolutions++;

        return ['title'];
    };

    expect($cache->remember('/components/card.blade.php', '@props([\'title\'])', $resolve))
        ->toBe(['title'])
        ->and($cache->remember('/components/card.blade.php', '@props([\'title\'])', $resolve))
        ->toBe(['title'])
        ->and($resolutions)->toBe(1)
        ->and($cache->remember('/components/card.blade.php', '@props([\'heading\'])', function () use (&$resolutions): array {
            $resolutions++;

            return ['heading'];
        }))->toBe(['heading'])
        ->and($resolutions)->toBe(2);
});

it('caches an unresolvable component definition', function (): void {
    $cache = new ComponentRequiredPropsCache;
    $resolutions = 0;
    $resolve = function () use (&$resolutions): null {
        $resolutions++;

        return null;
    };

    expect($cache->remember('/components/dynamic.blade.php', '@props($dynamic)', $resolve))->toBeNull()
        ->and($cache->remember('/components/dynamic.blade.php', '@props($dynamic)', $resolve))->toBeNull()
        ->and($resolutions)->toBe(1);
});
