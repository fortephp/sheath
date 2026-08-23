<?php

declare(strict_types=1);

namespace Forte\Sheath\Configuration;

use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleRegistry;
use InvalidArgumentException;

final class PackagePresets
{
    /** @var array<string, array<string, string|array{0: string, 1: array<string, mixed>}>> */
    private array $presets = [];

    private static ?self $shared = null;

    public static function shared(): self
    {
        return self::$shared ??= new self;
    }

    public static function swap(?self $instance): void
    {
        self::$shared = $instance;
    }

    /**
     * @param  array<string, string|array{0: string, 1: array<string, mixed>}>  $rules  ruleId => severity (or [severity, options])
     */
    public function add(string $name, array $rules): void
    {
        $name = strtolower(trim($name));

        if ($name === '' || RulePreset::tryFromName($name) !== null) {
            throw new InvalidArgumentException(
                "Package preset name [{$name}] is empty or shadows a built-in preset."
            );
        }

        foreach ($rules as $ruleId => $entry) {
            self::assertValidEntry($name, $ruleId, $entry);
        }

        if (isset($this->presets[$name])) {
            if ($this->presets[$name] === $rules) {
                return;
            }

            throw new InvalidArgumentException(
                "Package preset [{$name}] is already registered; preset names are case-insensitive."
            );
        }

        $this->presets[$name] = $rules;
    }

    /**
     * @return array<string, string|array{0: string, 1: array<string, mixed>}>|null
     */
    public function rulesFor(string $name, RuleRegistry $registry): ?array
    {
        $declared = $this->declared($name);

        if ($declared === null) {
            return null;
        }

        return array_intersect_key($declared, array_flip($registry->all()));
    }

    /**
     * @return array<string, string|array{0: string, 1: array<string, mixed>}>|null
     */
    public function declared(string $name): ?array
    {
        return $this->presets[strtolower(trim($name))] ?? null;
    }

    /**
     * @return array<string>
     */
    public function allNames(): array
    {
        return array_keys($this->presets);
    }

    public function flush(): void
    {
        $this->presets = [];
    }

    /**
     * @param  array<string, string|array{0: string, 1: array<string, mixed>}>  $rules
     */
    public static function register(string $name, array $rules): void
    {
        self::shared()->add($name, $rules);
    }

    /**
     * @return array<string, string|array{0: string, 1: array<string, mixed>}>|null
     */
    public static function rules(string $name, RuleRegistry $registry): ?array
    {
        return self::shared()->rulesFor($name, $registry);
    }

    /**
     * @return array<string, string|array{0: string, 1: array<string, mixed>}>|null
     */
    public static function declaredRules(string $name): ?array
    {
        return self::shared()->declared($name);
    }

    /**
     * @return array<string>
     */
    public static function names(): array
    {
        return self::shared()->allNames();
    }

    public static function reset(): void
    {
        self::shared()->flush();
    }

    private static function assertValidEntry(string $name, mixed $ruleId, mixed $entry): void
    {
        if (! is_string($ruleId) || trim($ruleId) === '') {
            throw new InvalidArgumentException(sprintf(
                'Package preset [%s] entries must be keyed by rule ID; found key [%s]. '
                .'Did you pass a list of rule IDs instead of a ruleId => severity map?',
                $name,
                is_scalar($ruleId) ? (string) $ruleId : get_debug_type($ruleId)
            ));
        }

        if (is_string($entry)) {
            self::assertValidSeverity($name, $ruleId, $entry);

            return;
        }

        if (! is_array($entry)) {
            throw new InvalidArgumentException(sprintf(
                'Package preset [%s] rule [%s] must map to a severity string or a [severity, options] array; got %s.',
                $name,
                $ruleId,
                get_debug_type($entry)
            ));
        }

        $allowedKeys = array_is_list($entry)
            ? [0, 1]
            : ['severity', 'options'];

        foreach (array_keys($entry) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                throw new InvalidArgumentException(sprintf(
                    'Package preset [%s] rule [%s] entry has unknown key [%s]; expected a [severity, options] list or severity/options map.',
                    $name,
                    $ruleId,
                    (string) $key
                ));
            }
        }

        $severity = $entry['severity'] ?? $entry[0] ?? null;

        if ($severity !== null) {
            if (! is_string($severity)) {
                throw new InvalidArgumentException(sprintf(
                    'Package preset [%s] rule [%s] severity must be a string; got %s.',
                    $name,
                    $ruleId,
                    get_debug_type($severity)
                ));
            }

            self::assertValidSeverity($name, $ruleId, $severity);
        }

        $options = $entry['options'] ?? $entry[1] ?? null;

        if ($options !== null && ! is_array($options)) {
            throw new InvalidArgumentException(sprintf(
                'Package preset [%s] rule [%s] options must be an array; got %s.',
                $name,
                $ruleId,
                get_debug_type($options)
            ));
        }

        if (is_array($options) && array_key_exists('exclude', $options)) {
            self::assertValidExclude($name, $ruleId, $options['exclude']);
        }
    }

    private static function assertValidSeverity(string $name, string $ruleId, string $severity): void
    {
        try {
            Severity::fromString($severity);
        } catch (InvalidArgumentException) {
            throw new InvalidArgumentException(sprintf(
                'Package preset [%s] rule [%s] has an invalid severity [%s].',
                $name,
                $ruleId,
                $severity
            ));
        }
    }

    private static function assertValidExclude(string $name, string $ruleId, mixed $exclude): void
    {
        $valid = is_array($exclude)
            && $exclude === array_values(array_filter($exclude, is_string(...)));

        if (! $valid) {
            throw new InvalidArgumentException(sprintf(
                "Package preset [%s] rule [%s] 'exclude' must be a list of path pattern strings; got %s.",
                $name,
                $ruleId,
                get_debug_type($exclude)
            ));
        }
    }
}
