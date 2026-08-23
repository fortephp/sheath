<?php

declare(strict_types=1);

namespace Forte\Sheath\Support;

use RuntimeException;
use UnexpectedValueException;

/** @internal */
final class JsonResource
{
    /** @return array<string, mixed> */
    public static function object(string $relativePath): array
    {
        $data = self::decode($relativePath);
        if (! is_array($data)) {
            throw new UnexpectedValueException("Sheath resource must contain a JSON object: {$relativePath}");
        }

        $object = [];
        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                throw new UnexpectedValueException("Sheath resource must contain a JSON object: {$relativePath}");
            }

            $object[$key] = $value;
        }

        return $object;
    }

    /** @return list<string> */
    public static function stringList(string $relativePath): array
    {
        $data = self::decode($relativePath);
        if (! is_array($data) || ! array_is_list($data)) {
            throw new UnexpectedValueException("Sheath resource must contain a JSON string list: {$relativePath}");
        }

        $strings = [];
        foreach ($data as $value) {
            if (! is_string($value)) {
                throw new UnexpectedValueException("Sheath resource must contain a JSON string list: {$relativePath}");
            }

            $strings[] = $value;
        }

        return $strings;
    }

    private static function decode(string $relativePath): mixed
    {
        /** @var array<string, mixed> $resources */
        static $resources = [];

        if (array_key_exists($relativePath, $resources)) {
            return $resources[$relativePath];
        }

        $path = dirname(__DIR__, 2).'/resources/'.$relativePath;
        if (! is_file($path)) {
            throw new RuntimeException("Sheath resource not found: {$relativePath}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Sheath resource could not be read: {$relativePath}");
        }

        return $resources[$relativePath] = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    }
}
