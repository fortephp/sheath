<?php

declare(strict_types=1);

namespace Forte\Sheath\Configuration;

use Forte\Sheath\Baselines\Baseline;
use Forte\Sheath\Components\ComponentSemanticRewriter;
use Forte\Sheath\Exceptions\ConfigurationException;
use Forte\Sheath\Packages\PackageRequirementMode;
use Forte\Sheath\Parallel\Contracts\WorkerConfig;
use Forte\Sheath\Results\Severity;
use InvalidArgumentException;

final class Config implements WorkerConfig
{
    private int $revision = 0;

    /**
     * @var array<string>
     */
    public const RECOGNIZED_KEYS = [
        'preset',
        'paths',
        'ignore',
        'rules',
        'baselineLineTolerance',
        'neverFix',
        'packageRequirementMode',
        'inlineSuppressions',
        'componentMappings',
    ];

    /**
     * @var array<string>|null
     */
    private ?array $preset = null;

    /**
     * @var array<string>|null
     */
    private ?array $paths = null;

    /**
     * @var array<string>|null
     */
    private ?array $ignore = null;

    /**
     * @var array<string, string|array<array-key, mixed>>|null
     */
    private ?array $rules = null;

    private ?int $baselineLineTolerance = null;

    /**
     * @var array<string>|null
     */
    private ?array $neverFix = null;

    private ?PackageRequirementMode $packageRequirementMode = null;

    private ?bool $inlineSuppressions = null;

    /** @var array<string, string>|null */
    private ?array $componentMappings = null;

    /**
     * @var array<string, array{declared: int, contributed: int, skippedDueToPackages: array<string>, unknown: array<string>}>|null
     */
    private ?array $presetContributions = null;

    /**
     * @param  array<string, mixed>|null  $data
     *
     * @throws ConfigurationException
     */
    public static function make(?array $data = null): self
    {
        if ($data !== null) {
            return self::fromArray($data);
        }

        return new self;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ConfigurationException when a value cannot mean what its key requires
     */
    public static function fromArray(array $data): static
    {
        $instance = new self;

        $preset = self::readPreset($data);
        if ($preset !== null) {
            $instance->setPreset($preset);
        }

        $paths = self::readStringList($data, 'paths', 'a list of path strings (or a single path string)');
        if ($paths !== null) {
            $instance->setPaths($paths);
        }

        $rules = self::readRules($data);
        if ($rules !== null) {
            $instance->setRules($rules);
        }

        $ignore = self::readStringList($data, 'ignore', 'a list of ignore patterns (or a single pattern string)');
        if ($ignore !== null) {
            $instance->setIgnore($ignore);
        }

        $baselineLineTolerance = self::readBaselineLineTolerance($data);
        if ($baselineLineTolerance !== null) {
            $instance->setBaselineLineTolerance($baselineLineTolerance);
        }

        $neverFix = self::readStringList(
            $data,
            'neverFix',
            'a list of rule IDs (or a single rule ID string)',
        );
        if ($neverFix !== null) {
            $instance->setNeverFix($neverFix);
        }

        $packageRequirementMode = self::readPackageRequirementMode($data);
        if ($packageRequirementMode !== null) {
            $instance->setPackageRequirementMode($packageRequirementMode);
        }

        $inlineSuppressions = self::readInlineSuppressions($data);
        if ($inlineSuppressions !== null) {
            $instance->setInlineSuppressions($inlineSuppressions);
        }

        if (array_key_exists('componentMappings', $data)) {
            $mappings = $data['componentMappings'];
            if (! is_array($mappings)) {
                throw ConfigurationException::invalidFormat(
                    'componentMappings',
                    "a map of Blade component tag names to native HTML tag names, for example ['x-button' => 'button']",
                );
            }

            /** @var array<array-key, mixed> $mappings */
            $instance->setComponentMappings($mappings);
        }

        if (isset($data['presetContributions']) && is_array($data['presetContributions'])) {
            /** @var array<string, array{declared: int, contributed: int, skippedDueToPackages: array<string>, unknown: array<string>}> $contributions */
            $contributions = $data['presetContributions'];
            $instance->setPresetContributions($contributions);
        }

        return $instance;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>|null
     *
     * @throws ConfigurationException
     */
    private static function readPreset(array $data): ?array
    {
        if (! array_key_exists('preset', $data)) {
            return null;
        }

        $value = $data['preset'];
        if (! is_string($value) && ! is_array($value)) {
            throw ConfigurationException::invalidFormat('preset', 'a preset name or a list of preset names');
        }

        $names = is_array($value) ? $value : [$value];
        $strings = array_filter($names, is_string(...));

        if ($names !== $strings) {
            throw ConfigurationException::invalidFormat('preset', 'a preset name or a list of preset names');
        }

        return array_values($strings);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, string|array<array-key, mixed>>|null
     *
     * @throws ConfigurationException
     */
    private static function readRules(array $data): ?array
    {
        if (! array_key_exists('rules', $data)) {
            return null;
        }

        $rules = $data['rules'];
        if (! is_array($rules)) {
            throw ConfigurationException::invalidFormat('rules', 'a map of rule ID to severity, or to [severity, options]');
        }

        /** @var array<string, string|array<array-key, mixed>> $rules */
        return $rules;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ConfigurationException
     */
    private static function readBaselineLineTolerance(array $data): ?int
    {
        if (! array_key_exists('baselineLineTolerance', $data)) {
            return null;
        }

        $tolerance = $data['baselineLineTolerance'];
        if (! is_int($tolerance)) {
            throw ConfigurationException::invalidFormat('baselineLineTolerance', 'an integer');
        }

        return $tolerance;
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ConfigurationException
     */
    private static function readPackageRequirementMode(array $data): ?PackageRequirementMode
    {
        if (! array_key_exists('packageRequirementMode', $data)) {
            return null;
        }

        $value = $data['packageRequirementMode'];
        $expected = "one of 'skip', 'disable', or 'ignore'";

        if (! is_string($value)) {
            throw ConfigurationException::invalidFormat('packageRequirementMode', $expected);
        }

        try {
            return PackageRequirementMode::fromString($value);
        } catch (InvalidArgumentException) {
            throw ConfigurationException::invalidFormat('packageRequirementMode', $expected);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ConfigurationException
     */
    private static function readInlineSuppressions(array $data): ?bool
    {
        if (! array_key_exists('inlineSuppressions', $data)) {
            return null;
        }

        $enabled = $data['inlineSuppressions'];
        if (! is_bool($enabled)) {
            throw ConfigurationException::invalidFormat('inlineSuppressions', 'a boolean (true or false)');
        }

        return $enabled;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string>|null Null when the key is not set
     *
     * @throws ConfigurationException
     */
    private static function readStringList(
        array $data,
        string $key,
        string $expected,
    ): ?array {
        if (! array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];

        if (is_string($value)) {
            return [$value];
        }

        if (! is_array($value)) {
            throw ConfigurationException::invalidFormat($key, $expected);
        }

        $strings = array_filter($value, is_string(...));

        if ($value !== $strings) {
            throw ConfigurationException::invalidFormat($key, $expected);
        }

        /** @var array<string> $strings */
        return array_values($strings);
    }

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws ConfigurationException
     */
    public static function assertValidShape(array $data, string $source): void
    {
        if ($data === []) {
            return;
        }

        if (array_is_list($data)) {
            throw ConfigurationException::fileContainsList($source);
        }

        $unknown = array_values(array_diff(
            array_map(strval(...), array_keys($data)),
            self::RECOGNIZED_KEYS
        ));

        if ($unknown !== []) {
            throw ConfigurationException::unknownConfigKeys($source, $unknown, self::RECOGNIZED_KEYS);
        }
    }

    public function has(string $key): bool
    {
        return match ($key) {
            'preset' => $this->preset !== null,
            'paths' => $this->paths !== null,
            'ignore' => $this->ignore !== null,
            'rules' => $this->rules !== null,
            'baselineLineTolerance' => $this->baselineLineTolerance !== null,
            'neverFix' => $this->neverFix !== null,
            'packageRequirementMode' => $this->packageRequirementMode !== null,
            'inlineSuppressions' => $this->inlineSuppressions !== null,
            'componentMappings' => $this->componentMappings !== null,
            'presetContributions' => $this->presetContributions !== null,
            default => false,
        };
    }

    public function respectsInlineSuppressions(): bool
    {
        return $this->inlineSuppressions ?? true;
    }

    /** @return array<string, string> */
    public function getComponentMappings(): array
    {
        return $this->componentMappings ?? [];
    }

    /**
     * @param  array<array-key, mixed>  $mappings
     *
     * @throws ConfigurationException
     */
    public function setComponentMappings(array $mappings): self
    {
        $normalized = [];

        foreach ($mappings as $component => $semanticTag) {
            if (! is_string($component)
                || ! is_string($semanticTag)
                || ! ComponentSemanticRewriter::isValidComponentTag($component)
                || ! ComponentSemanticRewriter::isValidSemanticTag($semanticTag)) {
                throw ConfigurationException::invalidFormat(
                    'componentMappings',
                    "a map of Blade component tag names to native HTML tag names, for example ['x-button' => 'button']",
                );
            }

            $component = strtolower(trim($component));
            $semanticTag = strtolower(trim($semanticTag));

            if (isset($normalized[$component])) {
                throw ConfigurationException::invalidFormat(
                    'componentMappings',
                    'unique component tag names (case-insensitive)',
                );
            }

            $normalized[$component] = $semanticTag;
        }

        $this->componentMappings = $normalized;
        $this->revision++;

        return $this;
    }

    public function setInlineSuppressions(bool $enabled): self
    {
        $this->inlineSuppressions = $enabled;
        $this->revision++;

        return $this;
    }

    /**
     * @return array<string>
     */
    public function getPreset(): array
    {
        return $this->preset ?? [];
    }

    /**
     * @return array<string>
     */
    public function getPaths(): array
    {
        return $this->paths ?? [];
    }

    /**
     * @return array<string>
     */
    public function getIgnore(): array
    {
        return $this->ignore ?? [];
    }

    /**
     * @return array<string, string|array<array-key, mixed>>
     */
    public function getRules(): array
    {
        return $this->rules ?? [];
    }

    public function getBaselineLineTolerance(): int
    {
        return $this->baselineLineTolerance ?? Baseline::DEFAULT_LINE_TOLERANCE;
    }

    /**
     * @return array<string>
     */
    public function getNeverFix(): array
    {
        return $this->neverFix ?? [];
    }

    public function getPackageRequirementMode(): PackageRequirementMode
    {
        return $this->packageRequirementMode ?? PackageRequirementMode::SKIP;
    }

    /**
     * @param  array<string>|string  $presets
     */
    public function setPreset(array|string $presets): self
    {
        $this->preset = array_values((array) $presets);
        $this->revision++;

        return $this;
    }

    /**
     * @param  array<string>  $paths
     */
    public function setPaths(array $paths): self
    {
        $this->paths = array_values($paths);
        $this->revision++;

        return $this;
    }

    /**
     * @param  array<string>  $ignore
     */
    public function setIgnore(array $ignore): self
    {
        $this->ignore = array_values($ignore);
        $this->revision++;

        return $this;
    }

    /**
     * @param  array<string, string|array<array-key, mixed>>  $rules
     *
     * @throws ConfigurationException when an entry carries an invalid severity
     */
    public function setRules(array $rules): self
    {
        foreach ($rules as $ruleId => $entry) {
            if (! is_string($ruleId) || trim($ruleId) === '') {
                throw ConfigurationException::invalidFormat('rules', 'a map with non-empty string rule IDs');
            }

            self::assertValidRuleConfiguration($ruleId, $entry);
            self::assertValidRuleSeverity($ruleId, $entry);
        }

        $this->rules = $rules;
        $this->revision++;

        return $this;
    }

    /**
     * @throws ConfigurationException
     */
    private static function assertValidRuleConfiguration(string $ruleId, mixed $entry): void
    {
        if (is_string($entry)) {
            return;
        }

        $expected = "rule '{$ruleId}' to be a severity string, an empty array, [severity, options], or ['severity' => ..., 'options' => ...]";

        if (! is_array($entry)) {
            throw ConfigurationException::invalidFormat('rules', $expected);
        }

        if (array_is_list($entry)) {
            if (count($entry) > 2
                || (array_key_exists(0, $entry) && ! is_string($entry[0]))
                || (array_key_exists(1, $entry) && ! is_array($entry[1]))) {
                throw ConfigurationException::invalidFormat('rules', $expected);
            }

            return;
        }

        $unknownKeys = array_diff(array_keys($entry), ['severity', 'options']);
        if ($unknownKeys !== []
            || (array_key_exists('severity', $entry) && ! is_string($entry['severity']))
            || (array_key_exists('options', $entry) && ! is_array($entry['options']))) {
            throw ConfigurationException::invalidFormat('rules', $expected);
        }
    }

    private static function assertValidRuleSeverity(string $ruleId, mixed $entry): void
    {
        $severity = null;

        if (is_string($entry)) {
            $severity = $entry;
        } elseif (is_array($entry)) {
            $candidate = $entry['severity'] ?? $entry[0] ?? null;

            if (is_string($candidate)) {
                $severity = $candidate;
            }
        }

        if ($severity === null) {
            return;
        }

        try {
            Severity::fromString($severity);
        } catch (InvalidArgumentException) {
            throw ConfigurationException::invalidRuleSeverity($ruleId, $severity);
        }
    }

    /**
     * @param  string|array<array-key, mixed>  $config  A severity, or a severity with options
     *
     * @throws ConfigurationException
     */
    public function setRule(string $ruleId, string|array $config): self
    {
        $rules = $this->getRules();
        $rules[$ruleId] = $config;

        return $this->setRules($rules);
    }

    /**
     * @param  array<string>  $neverFix
     */
    public function setNeverFix(array $neverFix): self
    {
        $this->neverFix = array_values($neverFix);
        $this->revision++;

        return $this;
    }

    public function setBaselineLineTolerance(int $tolerance): self
    {
        $this->baselineLineTolerance = max(0, $tolerance);
        $this->revision++;

        return $this;
    }

    public function setPackageRequirementMode(PackageRequirementMode $mode): self
    {
        $this->packageRequirementMode = $mode;
        $this->revision++;

        return $this;
    }

    public function getRuleSeverity(string $ruleId): ?Severity
    {
        $config = $this->getRules()[$ruleId] ?? null;

        if ($config === null) {
            return null;
        }

        if (is_string($config)) {
            return Severity::fromString($config);
        }

        if (isset($config['severity']) && is_string($config['severity'])) {
            return Severity::fromString($config['severity']);
        }

        if (isset($config[0]) && is_string($config[0])) {
            return Severity::fromString($config[0]);
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRuleOptions(string $ruleId): array
    {
        $config = $this->getRules()[$ruleId] ?? null;

        if (! is_array($config)) {
            return [];
        }

        if (isset($config['options']) && is_array($config['options'])) {
            /** @var array<string, mixed> $options */
            $options = $config['options'];

            return $options;
        }

        if (isset($config[1]) && is_array($config[1])) {
            /** @var array<string, mixed> $options */
            $options = $config[1];

            return $options;
        }

        return [];
    }

    /**
     * @return array<string>
     */
    public function getRuleExclusions(string $ruleId): array
    {
        $exclude = $this->getRuleOptions($ruleId)['exclude'] ?? [];

        return array_values(array_filter((array) $exclude, is_string(...)));
    }

    public function hasRule(string $ruleId): bool
    {
        return isset($this->getRules()[$ruleId]);
    }

    public function shouldNeverFix(string $ruleId): bool
    {
        return in_array($ruleId, $this->getNeverFix(), true);
    }

    public function merge(Config $other): self
    {
        if ($other->rules !== null) {
            $this->rules = array_merge($this->getRules(), $other->rules);
        }

        if ($other->ignore !== null) {
            $this->ignore = array_values(array_unique(array_merge($this->getIgnore(), $other->ignore)));
        }

        if ($other->neverFix !== null) {
            $this->neverFix = array_values(array_unique(array_merge($this->getNeverFix(), $other->neverFix)));
        }

        $this->preset = $other->preset ?? $this->preset;
        $this->paths = $other->paths ?? $this->paths;
        $this->baselineLineTolerance = $other->baselineLineTolerance ?? $this->baselineLineTolerance;
        $this->packageRequirementMode = $other->packageRequirementMode ?? $this->packageRequirementMode;
        $this->inlineSuppressions = $other->inlineSuppressions ?? $this->inlineSuppressions;
        $this->componentMappings = $other->componentMappings ?? $this->componentMappings;
        $this->presetContributions = $other->presetContributions ?? $this->presetContributions;
        $this->revision++;

        return $this;
    }

    /**
     * @return array<string, array{declared: int, contributed: int, skippedDueToPackages: array<string>, unknown: array<string>}>
     */
    public function getPresetContributions(): array
    {
        return $this->presetContributions ?? [];
    }

    /**
     * @param  array<string, array{declared: int, contributed: int, skippedDueToPackages: array<string>, unknown: array<string>}>  $contributions
     */
    public function setPresetContributions(array $contributions): self
    {
        $this->presetContributions = $contributions;
        $this->revision++;

        return $this;
    }

    /** @internal Monotonic version used to invalidate prepared lint plans. */
    public function revision(): int
    {
        return $this->revision;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [];

        if ($this->preset !== null) {
            $data['preset'] = $this->preset;
        }

        if ($this->paths !== null) {
            $data['paths'] = $this->paths;
        }

        if ($this->rules !== null) {
            $data['rules'] = $this->rules;
        }

        if ($this->ignore !== null) {
            $data['ignore'] = $this->ignore;
        }

        if ($this->baselineLineTolerance !== null) {
            $data['baselineLineTolerance'] = $this->baselineLineTolerance;
        }

        if ($this->neverFix !== null) {
            $data['neverFix'] = $this->neverFix;
        }

        if ($this->packageRequirementMode !== null) {
            $data['packageRequirementMode'] = $this->packageRequirementMode->value;
        }

        if ($this->inlineSuppressions !== null) {
            $data['inlineSuppressions'] = $this->inlineSuppressions;
        }

        if ($this->componentMappings !== null) {
            $data['componentMappings'] = $this->componentMappings;
        }

        if ($this->presetContributions !== null) {
            $data['presetContributions'] = $this->presetContributions;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toResolvedArray(): array
    {
        $data = [
            'preset' => $this->getPreset(),
            'paths' => $this->getPaths(),
            'rules' => $this->getRules(),
            'ignore' => $this->getIgnore(),
            'baselineLineTolerance' => $this->getBaselineLineTolerance(),
            'neverFix' => $this->getNeverFix(),
            'packageRequirementMode' => $this->getPackageRequirementMode()->value,
            'inlineSuppressions' => $this->respectsInlineSuppressions(),
            'componentMappings' => $this->getComponentMappings(),
        ];

        if ($this->presetContributions !== null) {
            $data['presetContributions'] = $this->presetContributions;
        }

        return $data;
    }
}
