<?php

declare(strict_types=1);

namespace Forte\Sheath\Console\Handlers;

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Files\FileFinder;
use Forte\Sheath\Files\PathMatcher;
use Forte\Sheath\Files\PathResolver;

/** @internal */
readonly class PathHandler
{
    public function __construct(
        private FileFinder $fileFinder,
    ) {}

    /**
     * @param  array<string>  $argumentPaths
     * @param  array<string, bool>  $shortcuts
     * @return array<string>
     */
    public function resolvePaths(array $argumentPaths, array $shortcuts, Config $config): array
    {
        $paths = $this->namedPaths($argumentPaths, $shortcuts);

        if ($paths !== []) {
            return $paths;
        }

        return $config->getPaths() !== [] ? $config->getPaths() : ['resources/views'];
    }

    /**
     * @param  array<string>  $argumentPaths
     * @param  array<string, bool>  $shortcuts
     * @return array<string>
     */
    public function namedPaths(array $argumentPaths, array $shortcuts): array
    {
        $paths = $argumentPaths;

        if ($shortcuts['views'] ?? false) {
            $paths[] = 'resources/views';
        }

        if ($shortcuts['components'] ?? false) {
            $paths[] = 'resources/views/components';
        }

        if ($shortcuts['emails'] ?? false) {
            $paths[] = 'resources/views/emails';
        }

        return $paths;
    }

    /**
     * @param  array<string>  $paths
     * @param  array<string>  $ignorePatterns
     * @return array<string>
     */
    public function findFiles(array $paths, array $ignorePatterns, bool $pathsWereNamed = false): array
    {
        if (! $pathsWereNamed) {
            return $this->fileFinder->find($this->toAbsolutePaths($paths), $ignorePatterns);
        }

        $files = [];

        foreach ($paths as $path) {
            $found = $this->fileFinder->find(
                $this->toAbsolutePaths([$path]),
                $this->patternsThatStillApply($path, $ignorePatterns)
            );

            foreach ($found as $file) {
                $files[] = $file;
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @param  array<string>  $paths
     * @return array<string>
     */
    private function toAbsolutePaths(array $paths): array
    {
        return array_map(
            PathResolver::toAbsolutePath(...),
            $paths
        );
    }

    /**
     * @param  array<string>  $ignorePatterns
     * @return array<string>
     */
    private function patternsThatStillApply(string $path, array $ignorePatterns): array
    {
        $normalizedPath = trim(str_replace('\\', '/', $path), '/');
        $patterns = [];

        foreach ($ignorePatterns as $pattern) {
            if ($this->swallowsNamedPath($pattern, $normalizedPath)) {
                continue;
            }

            $patterns[] = $this->relativePatternForNamedPath($pattern, $normalizedPath);
        }

        return $patterns;
    }

    private function swallowsNamedPath(string $pattern, string $path): bool
    {
        if (PathMatcher::matches($pattern, $path)) {
            return true;
        }

        $directChild = $path.'/__sheath_probe__.blade.php';
        $nestedChild = $path.'/__sheath_probe__/nested.blade.php';

        return PathMatcher::matches($pattern, $directChild)
            && PathMatcher::matches($pattern, $nestedChild);
    }

    private function relativePatternForNamedPath(string $pattern, string $path): string
    {
        $normalizedPattern = str_replace('\\', '/', $pattern);
        $rootRelative = str_starts_with($normalizedPattern, '/');
        $patternSegments = array_values(array_filter(
            explode('/', trim($normalizedPattern, '/')),
            static fn (string $segment): bool => $segment !== ''
        ));
        $pathSegments = array_values(array_filter(
            explode('/', trim($path, '/')),
            static fn (string $segment): bool => $segment !== ''
        ));

        $maxPrefix = min(count($patternSegments) - 1, count($pathSegments));

        for ($prefixLength = $maxPrefix; $prefixLength > 0; $prefixLength--) {
            $prefixSegments = array_slice($patternSegments, 0, $prefixLength);

            if (in_array('**', $prefixSegments, true)) {
                continue;
            }

            $patternPrefix = implode('/', $prefixSegments);
            $pathSuffix = implode('/', array_slice($pathSegments, -$prefixLength));

            if (! PathMatcher::matches($patternPrefix, $pathSuffix)) {
                continue;
            }

            $remainder = implode('/', array_slice($patternSegments, $prefixLength));

            return $rootRelative ? '/'.$remainder : $remainder;
        }

        return $pattern;
    }
}
