<?php

declare(strict_types=1);

namespace Forte\Sheath\Exceptions;

class ConfigurationException extends SheathException
{
    /** @param array<string> $available */
    public static function unknownRuleOption(string $ruleId, string $option, array $available): self
    {
        $suffix = $available === []
            ? 'This rule has no rule-specific options.'
            : 'Available options: '.implode(', ', $available).'.';

        return new self("Unknown option '{$option}' for rule '{$ruleId}'. {$suffix}");
    }

    public static function invalidRuleOption(string $ruleId, string $option, string $expected): self
    {
        return new self("Invalid option '{$option}' for rule '{$ruleId}'. Expected {$expected}.");
    }

    public static function invalidFormat(string $key, string $expected): self
    {
        return new self("Invalid configuration format for '{$key}'. Expected: {$expected}.");
    }

    public static function invalidRuleSeverity(string $ruleId, string $value): self
    {
        return new self("Invalid severity '{$value}' for rule '{$ruleId}'. Expected: error, warning, info, or off.");
    }

    /**
     * @param  array<string>  $available
     */
    public static function unknownPreset(string $name, array $available): self
    {
        return new self("Unknown preset '{$name}'. Available presets: ".implode(', ', $available).'.');
    }

    public static function fileNotFound(string $path): self
    {
        return new self("Configuration file not found: {$path}");
    }

    public static function fileNotReadable(string $path): self
    {
        return new self("Configuration file is not readable: {$path}");
    }

    public static function unsupportedFileFormat(string $extension): self
    {
        return new self("Unsupported configuration file format: {$extension}. Expected a .php or .json file.");
    }

    public static function invalidFileContents(string $path): self
    {
        return new self("Invalid configuration file: {$path}. Expected it to contain a configuration array.");
    }

    public static function fileContainsList(string $path): self
    {
        return new self("Invalid configuration file: {$path}. Expected an array of settings keyed by name, but the file contains a list.");
    }

    /**
     * @param  array<string>  $unknown
     * @param  array<string>  $recognized
     */
    public static function unknownConfigKeys(string $path, array $unknown, array $recognized): self
    {
        $label = count($unknown) === 1 ? 'key' : 'keys';

        return new self(
            "Invalid configuration file: {$path}. Unknown {$label}: ".implode(', ', $unknown)
            .'. Recognized keys: '.implode(', ', $recognized).'.'
        );
    }
}
