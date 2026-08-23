<?php

declare(strict_types=1);

namespace Forte\Sheath\Support;

use Forte\Ast\Elements\Attribute;

/** @internal */
final class DirectiveModifierGrammar
{
    /** @return list<string> */
    public static function modifiers(Attribute $attribute): array
    {
        $parts = explode('.', strtolower($attribute->name()->rawName()));
        array_shift($parts);

        return $parts;
    }

    /**
     * @param  list<string>  $allowed
     * @param  list<string>  $durationOwners
     */
    public static function firstUnsupported(
        Attribute $attribute,
        array $allowed,
        array $durationOwners = [],
    ): ?string {
        $parts = self::modifiers($attribute);

        for ($index = 0; $index < count($parts); $index++) {
            $modifier = $parts[$index];
            if (! in_array($modifier, $allowed, true)) {
                return $modifier;
            }

            if (in_array($modifier, $durationOwners, true)
                && isset($parts[$index + 1])
                && preg_match('/^\d+(?:ms)?$/', $parts[$index + 1]) === 1) {
                $index++;

                continue;
            }

            // Alpine 3.15.12 added `.passive.false` as a two-token event
            // option. The `false` token is a value, not another modifier.
            if ($modifier === 'passive'
                && isset($parts[$index + 1])
                && $parts[$index + 1] === 'false') {
                $index++;
            }
        }

        return null;
    }
}
