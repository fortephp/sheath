<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Aria;

use Forte\Sheath\Support\JsonResource;
use JsonException;
use UnexpectedValueException;

/**
 * @internal
 */
final class Aria12Data
{
    public const VERSION = '1.2';

    public const SOURCE = 'https://www.w3.org/TR/wai-aria-1.2/';

    /** @var list<string> */
    public const GLOBAL_PROPERTIES = [
        'aria-atomic', 'aria-busy', 'aria-controls', 'aria-current',
        'aria-describedby', 'aria-details', 'aria-disabled', 'aria-dropeffect',
        'aria-errormessage', 'aria-flowto', 'aria-grabbed', 'aria-haspopup',
        'aria-hidden', 'aria-invalid', 'aria-keyshortcuts', 'aria-label',
        'aria-labelledby', 'aria-live', 'aria-owns', 'aria-relevant',
        'aria-roledescription',
    ];

    /**
     * Role requirements whose applicability depends on element state rather
     * than role inheritance alone.
     *
     * @var array<string, array<string, list<string>>>
     */
    public const CONDITIONAL_REQUIRED_PROPERTIES = [
        'separator' => [
            'focusable' => ['aria-valuenow'],
        ],
    ];

    /** @return array<string, array{type: string, values?: list<string>, allowUndefined?: bool, min?: int, allowMinusOne?: bool}> */
    public static function properties(): array
    {
        /** @var array<string, array{type: string, values?: list<string>, allowUndefined?: bool, min?: int, allowMinusOne?: bool}>|null $properties */
        static $properties;

        if ($properties === null) {
            $properties = self::loadProperties();

            foreach (['aria-colcount', 'aria-rowcount', 'aria-setsize'] as $name) {
                $properties[$name] = self::withIntegerDomain($properties, $name, 1, true);
            }
            foreach (['aria-colindex', 'aria-colspan', 'aria-level', 'aria-posinset', 'aria-rowindex'] as $name) {
                $properties[$name] = self::withIntegerDomain($properties, $name, 1);
            }
            $properties['aria-rowspan'] = self::withIntegerDomain($properties, 'aria-rowspan', 0);
        }

        return $properties;
    }

    /** @return array<string, array{supported: list<string>, required: list<string>, prohibited: list<string>}>
     * @throws JsonException
     */
    public static function roles(): array
    {
        /** @var array<string, array{supported: list<string>, required: list<string>, prohibited: list<string>}>|null $roles */
        static $roles;

        if ($roles === null) {
            $roles = self::loadRoles();

            foreach ($roles as &$definition) {
                $definition['supported'] = array_values(array_diff(
                    array_unique([...$definition['supported'], ...self::GLOBAL_PROPERTIES]),
                    $definition['prohibited'],
                ));
                sort($definition['supported']);
            }
            unset($definition);

            // `none` is a normative synonym of `presentation` in ARIA 1.2.
            $roles['none'] = $roles['presentation'];
        }

        return $roles;
    }

    /** @return array{type: string, values?: list<string>, allowUndefined?: bool, min?: int, allowMinusOne?: bool}|null */
    public static function property(string $name): ?array
    {
        return self::properties()[strtolower($name)] ?? null;
    }

    /**
     * @return array{supported: list<string>, required: list<string>, prohibited: list<string>}|null
     *
     * @throws JsonException
     */
    public static function role(string $name): ?array
    {
        return self::roles()[strtolower($name)] ?? null;
    }

    /** @return array<string, array{type: string, values?: list<string>, allowUndefined?: bool, min?: int, allowMinusOne?: bool}> */
    private static function loadProperties(): array
    {
        $properties = [];

        foreach (JsonResource::object('aria/1.2/properties.json') as $name => $definition) {
            $properties[$name] = self::propertyDefinition($definition, $name);
        }

        return $properties;
    }

    /**
     * @param  array<string, array{type: string, values?: list<string>, allowUndefined?: bool, min?: int, allowMinusOne?: bool}>  $properties
     * @return array{type: string, values?: list<string>, allowUndefined?: bool, min?: int, allowMinusOne?: bool}
     */
    private static function withIntegerDomain(array $properties, string $name, int $min, bool $allowMinusOne = false): array
    {
        $property = $properties[$name] ?? throw new UnexpectedValueException("Missing ARIA property definition: {$name}");
        $property['min'] = $min;

        if ($allowMinusOne) {
            $property['allowMinusOne'] = true;
        }

        return $property;
    }

    /** @return array{type: string, values?: list<string>, allowUndefined?: bool, min?: int, allowMinusOne?: bool} */
    private static function propertyDefinition(mixed $definition, string $name): array
    {
        if (! is_array($definition) || ! is_string($definition['type'] ?? null)) {
            throw new UnexpectedValueException("Invalid ARIA property definition: {$name}");
        }

        $property = ['type' => $definition['type']];

        if (array_key_exists('values', $definition)) {
            $property['values'] = self::stringList($definition['values'], "{$name}.values");
        }
        if (array_key_exists('allowUndefined', $definition)) {
            if (! is_bool($definition['allowUndefined'])) {
                throw new UnexpectedValueException("Invalid ARIA property definition: {$name}.allowUndefined");
            }

            $property['allowUndefined'] = $definition['allowUndefined'];
        }
        if (array_key_exists('min', $definition)) {
            if (! is_int($definition['min'])) {
                throw new UnexpectedValueException("Invalid ARIA property definition: {$name}.min");
            }

            $property['min'] = $definition['min'];
        }
        if (array_key_exists('allowMinusOne', $definition)) {
            if (! is_bool($definition['allowMinusOne'])) {
                throw new UnexpectedValueException("Invalid ARIA property definition: {$name}.allowMinusOne");
            }

            $property['allowMinusOne'] = $definition['allowMinusOne'];
        }

        return $property;
    }

    /** @return array<string, array{supported: list<string>, required: list<string>, prohibited: list<string>}> */
    private static function loadRoles(): array
    {
        $roles = [];

        foreach (JsonResource::object('aria/1.2/roles.json') as $name => $definition) {
            if (! is_array($definition)) {
                throw new UnexpectedValueException("Invalid ARIA role definition: {$name}");
            }

            $roles[$name] = [
                'supported' => self::stringList($definition['supported'] ?? null, "{$name}.supported"),
                'required' => self::stringList($definition['required'] ?? null, "{$name}.required"),
                'prohibited' => self::stringList($definition['prohibited'] ?? null, "{$name}.prohibited"),
            ];
        }

        return $roles;
    }

    /** @return list<string> */
    private static function stringList(mixed $values, string $name): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw new UnexpectedValueException("Invalid ARIA string list: {$name}");
        }

        $strings = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new UnexpectedValueException("Invalid ARIA string list: {$name}");
            }

            $strings[] = $value;
        }

        return $strings;
    }
}
