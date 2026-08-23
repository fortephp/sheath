<?php

declare(strict_types=1);

namespace Forte\Sheath\Packages;

use InvalidArgumentException;

enum PackageRequirementMode: string
{
    case SKIP = 'skip';
    case DISABLE = 'disable';
    case IGNORE = 'ignore';

    /**
     * @throws InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        return match (strtolower($value)) {
            'skip' => self::SKIP,
            'disable' => self::DISABLE,
            'ignore' => self::IGNORE,
            default => throw new InvalidArgumentException(
                "Invalid package requirement mode: {$value}. Expected: skip, disable, or ignore."
            ),
        };
    }
}
