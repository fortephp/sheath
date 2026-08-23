<?php

declare(strict_types=1);

namespace Forte\Sheath\Parsing;

use Forte\Ast\Document\Document;
use Forte\Ast\PhpTagNode;
use Forte\Sheath\Files\PathResolver;
use Illuminate\Container\Container;
use Throwable;

/** @internal */
final class VoltComponentSource
{
    private const MOUNTED_DIRECTORIES = 'Livewire\\Volt\\MountedDirectories';

    public static function isTemplate(Document $document, string $filePath): bool
    {
        $mountedPaths = self::mountedPaths();
        if ($mountedPaths !== null) {
            return self::pathIsMounted($filePath, $mountedPaths);
        }

        foreach ($document->allOfType(PhpTagNode::class, true) as $tag) {
            if (! $tag->isPhpTag()) {
                continue;
            }

            if (LivewireComponentSource::isVoltClassDefinition($tag)
                || self::hasVoltFunctionalApiReference($tag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>|null Null means no booted Volt registry is available.
     */
    public static function mountedPaths(): ?array
    {
        $container = Container::getInstance();
        if (! $container->bound(self::MOUNTED_DIRECTORIES)) {
            return null;
        }

        try {
            $registry = $container->make(self::MOUNTED_DIRECTORIES);
            if (! is_object($registry) || ! method_exists($registry, 'paths')) {
                return null;
            }

            $directories = $registry->paths();
        } catch (Throwable) {
            return null;
        }

        if (! is_iterable($directories)) {
            return null;
        }

        $paths = [];
        foreach ($directories as $directory) {
            if (! is_object($directory)) {
                continue;
            }

            $path = get_object_vars($directory)['path'] ?? null;
            if (is_string($path) && $path !== '') {
                $paths[] = self::normalizePath($path);
            }
        }

        sort($paths, SORT_STRING);

        return array_values(array_unique($paths));
    }

    /** @param list<string> $mountedPaths */
    private static function pathIsMounted(string $filePath, array $mountedPaths): bool
    {
        $filePath = self::normalizePath($filePath);

        foreach ($mountedPaths as $mountedPath) {
            // Mirror Volt's MountedDirectories::isWithinMountedDirectory()
            // prefix test exactly, including its lack of a segment boundary.
            if (str_starts_with($filePath, $mountedPath)) {
                return true;
            }
        }

        return false;
    }

    private static function normalizePath(string $path): string
    {
        $absolute = PathResolver::isAbsolutePath($path)
            ? $path
            : rtrim(PathResolver::projectRoot(), '/\\').DIRECTORY_SEPARATOR.$path;
        $resolved = realpath($absolute);

        return rtrim(PathResolver::normalizeSeparators($resolved === false ? $absolute : $resolved), '/');
    }

    private static function hasVoltFunctionalApiReference(PhpTagNode $tag): bool
    {
        $tokens = PhpSource::tokenize($tag->content());
        if ($tokens === null) {
            return false;
        }

        $depth = 0;
        $insideUse = false;
        $allUseMembersAreFunctions = false;
        $insideFunctionImport = false;
        $groupPrefix = null;

        foreach ($tokens as $index => $token) {
            if (self::startsUseImport($tokens, $index, $token, $depth)) {
                $insideUse = true;
                $allUseMembersAreFunctions = false;
                $insideFunctionImport = false;
                $groupPrefix = null;
            } elseif ($insideUse && self::tokenIs($token, T_FUNCTION)) {
                $allUseMembersAreFunctions = $allUseMembersAreFunctions || $depth === 0;
                $insideFunctionImport = true;

                if ($groupPrefix !== null && self::isVoltNamespace($groupPrefix)) {
                    return true;
                }
            } elseif ($insideUse && $token === ',') {
                $insideFunctionImport = $allUseMembersAreFunctions;
            } elseif ($depth === 0 && $token === ';') {
                $insideUse = false;
                $allUseMembersAreFunctions = false;
                $insideFunctionImport = false;
                $groupPrefix = null;
            }

            $name = self::qualifiedName($token);

            if ($insideUse && $name !== null) {
                if (self::startsGroupImport($tokens, $index + 1)) {
                    $groupPrefix = $name;

                    if ($insideFunctionImport && self::isVoltNamespace($groupPrefix)) {
                        return true;
                    }
                } elseif ($insideFunctionImport) {
                    $name = $groupPrefix === null ? $name : $groupPrefix.'\\'.$name;

                    if (self::isVoltFunction($name)) {
                        return true;
                    }
                }
            }

            $depth += PhpSource::nestingDelta($token);

            if (! $insideUse && self::isVoltFunctionCall($tokens, $index, $name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     * @param  array{int, string, int}|string  $token
     */
    private static function startsUseImport(array $tokens, int $index, array|string $token, int $depth): bool
    {
        return $depth === 0
            && self::tokenIs($token, T_USE)
            && self::nextSignificantToken($tokens, $index + 1) !== '(';
    }

    /** @param array{int, string, int}|string $token */
    private static function tokenIs(array|string $token, int $id): bool
    {
        return is_array($token) && $token[0] === $id;
    }

    /** @param array{int, string, int}|string $token */
    private static function qualifiedName(array|string $token): ?string
    {
        if (! is_array($token)
            || ! in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        return $token[1];
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private static function startsGroupImport(array $tokens, int $start): bool
    {
        $foundSeparator = false;

        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (! $foundSeparator) {
                if ($token !== '\\' && (! is_array($token) || $token[0] !== T_NS_SEPARATOR)) {
                    return false;
                }

                $foundSeparator = true;

                continue;
            }

            return $token === '{';
        }

        return false;
    }

    private static function isVoltFunction(string $name): bool
    {
        return str_starts_with(strtolower(ltrim($name, '\\')), 'livewire\\volt\\');
    }

    private static function isVoltNamespace(string $name): bool
    {
        $name = strtolower(ltrim($name, '\\'));

        return $name === 'livewire\\volt' || self::isVoltFunction($name);
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private static function isVoltFunctionCall(array $tokens, int $index, ?string $name): bool
    {
        return $name !== null
            && self::isVoltFunction($name)
            && self::nextSignificantToken($tokens, $index + 1) === '(';
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     * @return array{int, string, int}|string|null
     */
    private static function nextSignificantToken(array $tokens, int $start): array|string|null
    {
        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $token;
            }
        }

        return null;
    }
}
