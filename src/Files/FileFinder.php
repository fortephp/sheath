<?php

declare(strict_types=1);

namespace Forte\Sheath\Files;

use Symfony\Component\Finder\Finder as SymfonyFinder;

/** @internal */
readonly class FileFinder
{
    private const FILE_EXTENSIONS = ['blade.php'];

    /**
     * @param  array<string>  $paths
     * @param  array<string>  $ignorePatterns
     * @return array<string>
     */
    public function find(array $paths, array $ignorePatterns = []): array
    {
        if ($paths === []) {
            return [];
        }

        $files = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $realPath = realpath($path);

                if ($realPath !== false) {
                    $files[] = $realPath;
                }

                continue;
            }

            if (is_dir($path)) {
                foreach ($this->findInDirectory($path, $ignorePatterns) as $file) {
                    $files[] = $file;
                }

                continue;
            }

            if ($this->isGlobPattern($path)) {
                foreach ($this->findByGlob($path, $ignorePatterns) as $file) {
                    $files[] = $file;
                }
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @param  array<string>  $ignorePatterns
     * @return array<string>
     */
    private function findInDirectory(
        string $directory,
        array $ignorePatterns = [],
        bool $bladeFilesOnly = true,
    ): array {
        $finder = SymfonyFinder::create()
            ->files()
            ->in($directory)
            ->ignoreUnreadableDirs();

        if ($bladeFilesOnly) {
            foreach (self::FILE_EXTENSIONS as $extension) {
                $finder->name('*.'.$extension);
            }
        }

        foreach ($ignorePatterns as $pattern) {
            $normalizedPattern = $this->normalizeIgnorePattern($pattern, $directory);
            $finder->notPath($this->globToRegex($normalizedPattern));
        }

        $files = [];
        foreach ($finder as $file) {
            $files[] = $file->getRealPath();
        }

        return $files;
    }

    /**
     * @param  array<string>  $ignorePatterns
     * @return array<string>
     */
    private function findByGlob(string $pattern, array $ignorePatterns = []): array
    {
        $pattern = str_replace('\\', '/', $pattern);
        if (! PathResolver::isAbsolutePath($pattern)) {
            $workingDirectory = getcwd();
            if ($workingDirectory === false) {
                return [];
            }

            $pattern = rtrim(str_replace('\\', '/', $workingDirectory), '/').'/'.ltrim($pattern, '/');
        }

        $searchRoot = $this->globSearchRoot($pattern);
        if ($searchRoot === null) {
            return [];
        }

        $files = [];
        $root = $searchRoot['path'];
        $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
        $relativePattern = ltrim(substr($pattern, strlen($searchRoot['patternPrefix'])), '/');
        $regex = GlobPattern::toRegex('/'.$relativePattern, leadingSlashAnchors: true);

        foreach ($this->findInDirectory($root, $ignorePatterns, bladeFilesOnly: false) as $file) {
            $normalizedFile = str_replace('\\', '/', $file);
            $relativeFile = ltrim(substr($normalizedFile, strlen($normalizedRoot)), '/');

            if (preg_match($regex, $relativeFile) === 1) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /** @return array{path: string, patternPrefix: string}|null */
    private function globSearchRoot(string $pattern): ?array
    {
        $firstGlob = strcspn($pattern, '*?{');
        $staticPrefix = substr($pattern, 0, $firstGlob);

        // The glob may continue the final literal segment (for example,
        // `views*.blade.php`), so that segment can never be the search root.
        $root = dirname(str_replace('/', DIRECTORY_SEPARATOR, $staticPrefix.'__sheath_glob__'));

        $realRoot = realpath($root);

        if ($realRoot === false) {
            return null;
        }

        return [
            'path' => $realRoot,
            'patternPrefix' => rtrim(str_replace('\\', '/', $root), '/'),
        ];
    }

    private function isGlobPattern(string $path): bool
    {
        return str_contains($path, '*') || str_contains($path, '?') || str_contains($path, '{');
    }

    private function normalizeIgnorePattern(string $pattern, string $directory): string
    {
        $pattern = str_replace('\\', '/', $pattern);
        $directory = str_replace('\\', '/', rtrim($directory, '/\\'));

        $isRootRelative = str_starts_with($pattern, '/');
        if ($isRootRelative) {
            $pattern = ltrim($pattern, '/');
        }

        $segments = array_filter(explode('/', $pattern), static fn (string $segment): bool => $segment !== '');
        $dirSegments = array_filter(explode('/', $directory), static fn (string $segment): bool => $segment !== '');

        $segments = array_values($segments);
        $dirSegments = array_values($dirSegments);

        for ($prefixLen = min(count($segments) - 1, count($dirSegments)); $prefixLen > 0; $prefixLen--) {
            $patternPrefix = implode('/', array_slice($segments, 0, $prefixLen));
            $dirSuffix = implode('/', array_slice($dirSegments, -$prefixLen));

            if (PathMatcher::matches($patternPrefix, $dirSuffix)) {
                $remainder = implode('/', array_slice($segments, $prefixLen));

                return $isRootRelative ? '/'.$remainder : $remainder;
            }
        }

        return $isRootRelative ? '/'.$pattern : $pattern;
    }

    /**
     * Convert a gitignore-style glob to a segment-boundary regex.
     *
     * `*` stays within one segment, `**` crosses segments, and `**\/` also
     * matches zero directories. A leading slash anchors the first segment.
     */
    private function globToRegex(string $glob): string
    {
        return GlobPattern::toRegex($glob, leadingSlashAnchors: true);
    }
}
