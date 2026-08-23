<?php

declare(strict_types=1);

namespace Forte\Sheath\Files;

/** @internal */
final class FileSystem
{
    public static function readFile(string $path): ?string
    {
        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }

    public static function writeFileAtomically(string $path, string $content): bool
    {
        return self::writeAtomically($path, $content);
    }

    public static function writeFileAtomicallyIfUnchanged(
        string $path,
        string $expectedContent,
        string $content,
    ): bool {
        return self::writeAtomically($path, $content, $expectedContent);
    }

    private static function writeAtomically(string $path, string $content, ?string $expectedContent = null): bool
    {
        if (is_link($path)) {
            $target = realpath($path);
            if ($target === false) {
                return false;
            }

            $path = $target;
        }

        if (is_file($path)) {
            $permissions = @fileperms($path);
            if (($permissions !== false && ($permissions & 0222) === 0) || ! is_writable($path)) {
                return false;
            }
        }

        $directory = dirname($path);
        if (! is_dir($directory)) {
            return false;
        }

        $temporary = @tempnam($directory, '.sheath-');
        if ($temporary === false) {
            return false;
        }

        try {
            $written = @file_put_contents($temporary, $content, LOCK_EX);
            if ($written !== strlen($content)) {
                return false;
            }

            $permissions = @fileperms($path);
            if ($permissions !== false) {
                @chmod($temporary, $permissions & 0777);
            }

            if ($expectedContent !== null) {
                $currentContent = self::readFile($path);
                if ($currentContent === null || ! hash_equals($expectedContent, $currentContent)) {
                    return false;
                }
            }

            return @rename($temporary, $path);
        } finally {
            if (file_exists($temporary)) {
                @chmod($temporary, 0666);
                @unlink($temporary);
            }
        }
    }
}
