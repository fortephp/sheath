<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Aria;

use Forte\Sheath\Support\JsonResource;

/** @internal */
final class AriaData
{
    /** @return list<string> */
    public static function globalProperties(): array
    {
        /** @var list<string>|null $properties */
        static $properties;

        return $properties ??= JsonResource::stringList('aria/global-properties.json');
    }

    /** @return list<string> */
    public static function validRoles(): array
    {
        /** @var list<string>|null $roles */
        static $roles;

        return $roles ??= JsonResource::stringList('aria/valid-roles.json');
    }
}
