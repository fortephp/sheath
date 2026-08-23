<?php

declare(strict_types=1);

namespace Forte\Sheath\Files;

/** @internal */
final class PathResolver
{
    public static function toAbsolutePath(string $path): string
    {
        if (self::isAbsolutePath($path)) {
            return $path;
        }

        return base_path($path);
    }

    public static function toRelativePath(string $path): string
    {
        $normalized = self::normalizeAbsolutePath(self::normalizeSeparators($path));
        $root = self::normalizeAbsolutePath(self::normalizeSeparators(self::projectRoot()));

        if ($root === '' || ! self::isAbsolutePath($normalized)) {
            return $normalized;
        }

        $prefix = rtrim($root, '/').'/';

        $haystack = PHP_OS_FAMILY === 'Windows' ? strtolower($normalized) : $normalized;
        $needle = PHP_OS_FAMILY === 'Windows' ? strtolower($prefix) : $prefix;

        if (! str_starts_with($haystack, $needle)) {
            return $normalized;
        }

        return substr($normalized, strlen($prefix));
    }

    public static function projectRoot(): string
    {
        if (! function_exists('base_path') || ! function_exists('app') || ! app()->bound('path.base')) {
            return (string) getcwd();
        }

        return base_path();
    }

    public static function normalizeSeparators(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return preg_replace('#^\./#', '', $normalized) ?? $normalized;
    }

    public static function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return true;
        }

        return preg_match('/^[A-Za-z]:[\/\\\\]/', $path) === 1;
    }

    private static function normalizeAbsolutePath(string $path): string
    {
        if (! self::isAbsolutePath($path)) {
            return $path;
        }

        $prefix = '/';
        $minimumDepth = 0;
        $remainder = ltrim($path, '/');

        if (preg_match('/^[A-Za-z]:\//', $path) === 1) {
            $prefix = substr($path, 0, 3);
            $remainder = substr($path, 3);
        } elseif (str_starts_with($path, '//')) {
            $prefix = '//';
            $minimumDepth = 2;
        }

        $segments = [];
        foreach (explode('/', $remainder) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if (count($segments) > $minimumDepth) {
                    array_pop($segments);
                }

                continue;
            }

            $segments[] = $segment;
        }

        return $prefix.implode('/', $segments);
    }
}
