<?php

declare(strict_types=1);

namespace Forte\Sheath\Results;

use InvalidArgumentException;

enum Severity: string
{
    case ERROR = 'error';
    case WARNING = 'warning';
    case INFO = 'info';
    case OFF = 'off';

    public function shouldReport(): bool
    {
        return $this !== self::OFF;
    }

    public function isError(): bool
    {
        return $this === self::ERROR;
    }

    public function isWarning(): bool
    {
        return $this === self::WARNING;
    }

    public function isInfo(): bool
    {
        return $this === self::INFO;
    }

    public static function fromString(string $value): self
    {
        return match (strtolower($value)) {
            'error', '2' => self::ERROR,
            'warning', 'warn', '1' => self::WARNING,
            'info' => self::INFO,
            'off', '0' => self::OFF,
            default => throw new InvalidArgumentException("Invalid severity: {$value}"),
        };
    }
}
