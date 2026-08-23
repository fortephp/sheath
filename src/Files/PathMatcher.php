<?php

declare(strict_types=1);

namespace Forte\Sheath\Files;

/** @internal */
final class PathMatcher
{
    /**
     * @var array<string, string>
     */
    private static array $compiled = [];

    /**
     * @param  array<string>  $patterns
     */
    public static function matchesAny(array $patterns, string $path): bool
    {
        foreach ($patterns as $pattern) {
            if (self::matches($pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $pattern, string $path): bool
    {
        $regex = self::$compiled[$pattern] ??= self::globToRegex($pattern);

        if ($regex === '') {
            return false;
        }

        return preg_match($regex, str_replace('\\', '/', $path)) === 1;
    }

    private static function globToRegex(string $glob): string
    {
        $glob = trim(str_replace('\\', '/', $glob), '/ ');

        if ($glob === '') {
            return '';
        }

        return GlobPattern::toRegex($glob);
    }
}
