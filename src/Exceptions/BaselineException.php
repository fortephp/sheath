<?php

declare(strict_types=1);

namespace Forte\Sheath\Exceptions;

final class BaselineException extends SheathException
{
    public static function unreadable(string $path): self
    {
        return new self("Baseline file is not readable: {$path}");
    }

    public static function invalidJson(string $path, string $reason): self
    {
        return new self("Invalid baseline file [{$path}]: {$reason}");
    }

    public static function invalidStructure(string $path, string $reason): self
    {
        return new self("Invalid baseline file [{$path}]: {$reason}");
    }

    public static function unsupportedVersion(string $path, int $version, int $supported): self
    {
        return new self("Baseline file [{$path}] uses schema version {$version}; this Sheath release supports up to version {$supported}.");
    }

    public static function writeFailed(string $path): self
    {
        return new self("Failed to save baseline file: {$path}");
    }
}
