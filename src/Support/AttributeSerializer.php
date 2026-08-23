<?php

declare(strict_types=1);

namespace Forte\Sheath\Support;

use Forte\Support\AttributeQuoting;
use InvalidArgumentException;

/** @internal */
final class AttributeSerializer
{
    public static function render(string $name, string $value, string $quote): string
    {
        if ($quote !== '"' && $quote !== "'") {
            throw new InvalidArgumentException('Attribute quote must be a single or double quote.');
        }

        $value = str_replace('&', '&amp;', $value);
        $value = $quote === '"'
            ? str_replace('"', '&quot;', $value)
            : str_replace("'", '&#39;', $value);

        return AttributeQuoting::render($name, $value, $quote);
    }
}
