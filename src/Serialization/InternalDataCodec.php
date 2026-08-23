<?php

declare(strict_types=1);

namespace Forte\Sheath\Serialization;

use InvalidArgumentException;

/** @internal */
final class InternalDataCodec
{
    private const BINARY_KEY = '__sheath_binary_base64_v1';

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function encode(array $data): array
    {
        /** @var array<string, mixed> */
        return self::encodeValue($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function decode(array $data): array
    {
        $decoded = self::decodeValue($data);
        if (! is_array($decoded)) {
            throw new InvalidArgumentException('Internal payload must decode to an array.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private static function encodeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return preg_match('//u', $value) === 1
                ? $value
                : [self::BINARY_KEY => base64_encode($value)];
        }

        if (! is_array($value)) {
            return $value;
        }

        return array_map(self::encodeValue(...), $value);
    }

    private static function decodeValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (count($value) === 1 && isset($value[self::BINARY_KEY])) {
            $encoded = $value[self::BINARY_KEY];
            if (! is_string($encoded)) {
                throw new InvalidArgumentException('Binary payload must be a base64 string.');
            }

            $decoded = base64_decode($encoded, true);
            if ($decoded === false) {
                throw new InvalidArgumentException('Binary payload is not valid base64.');
            }

            return $decoded;
        }

        return array_map(self::decodeValue(...), $value);
    }
}
