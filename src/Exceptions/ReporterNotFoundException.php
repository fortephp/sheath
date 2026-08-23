<?php

declare(strict_types=1);

namespace Forte\Sheath\Exceptions;

class ReporterNotFoundException extends SheathException
{
    /**
     * @param  array<string>  $available
     */
    public static function forName(string $name, array $available = []): self
    {
        $message = "Reporter '{$name}' not found.";

        if (! empty($available)) {
            $message .= ' Available reporters: '.implode(', ', $available).'.';
        }

        return new self($message);
    }
}
