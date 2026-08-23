<?php

declare(strict_types=1);

namespace Forte\Sheath\Parallel\Transport;

use InvalidArgumentException;

/**
 * @internal
 */
final class TransportSelector
{
    public const ENV_TRANSPORT = 'SHEATH_PARALLEL_TRANSPORT';

    public static function select(?string $override = null, ?string $osFamily = null): Transport
    {
        $override ??= self::environmentOverride();
        $osFamily ??= PHP_OS_FAMILY;

        if ($override !== null) {
            return match ($override) {
                'socket' => new SocketTransport,
                'pipes' => new PipesTransport,
                default => throw new InvalidArgumentException(
                    sprintf(
                        'Invalid %s value "%s"; expected "socket" or "pipes".',
                        self::ENV_TRANSPORT,
                        $override,
                    ),
                ),
            };
        }

        return $osFamily === 'Windows' ? new SocketTransport : new PipesTransport;
    }

    private static function environmentOverride(): ?string
    {
        $value = getenv(self::ENV_TRANSPORT);

        if ($value === false || $value === '') {
            return null;
        }

        return $value;
    }
}
