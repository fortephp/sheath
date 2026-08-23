<?php

declare(strict_types=1);

namespace Forte\Sheath\Configuration;

use Forte\Sheath\Exceptions\ConfigurationException;
use Forte\Sheath\Rules\RuleRegistry;
use Throwable;

/** @internal */
final class DefaultConfigFactory
{
    /**
     * @var array<string>
     */
    private const DEFAULT_IGNORE = [
        'vendor/**',
        'node_modules/**',
        'storage/**',
        'resources/views/emails/**',
    ];

    /**
     * @var array<string>
     */
    private const DEFAULT_PATHS = ['resources/views'];

    /**
     * @param  array<string>  $presetOverride
     *
     * @throws ConfigurationException when the data carries unknown keys, invalid
     * @throws Throwable
     *                   values, or names an unknown preset
     */
    public static function resolve(
        mixed $configData,
        RuleRegistry $ruleRegistry,
        array $presetOverride = [],
        string $source = 'config/sheath.php',
        ?PackagePresets $packagePresets = null,
    ): Config {
        if ($configData === null || $configData === []) {
            return self::fallback($ruleRegistry, $presetOverride, $packagePresets);
        }

        if (! is_array($configData)) {
            throw ConfigurationException::invalidFileContents($source);
        }

        Config::assertValidShape($configData, $source);

        /** @var array<string, mixed> $configData */
        $config = Config::fromArray($configData);
        $ruleRegistry->setPackageRequirementMode($config->getPackageRequirementMode());

        if ($presetOverride !== []) {
            $config->setPreset($presetOverride);
        }

        return self::applyPresets($config, $ruleRegistry, $packagePresets);
    }

    /**
     * @param  array<string>  $presetOverride
     *
     * @throws ConfigurationException
     * @throws Throwable
     */
    public static function fallback(
        RuleRegistry $ruleRegistry,
        array $presetOverride = [],
        ?PackagePresets $packagePresets = null,
    ): Config {
        $config = Config::fromArray([
            'preset' => $presetOverride === [] ? RulePreset::RECOMMENDED->value : $presetOverride,
            'paths' => self::DEFAULT_PATHS,
            'ignore' => self::DEFAULT_IGNORE,
        ]);
        $ruleRegistry->setPackageRequirementMode($config->getPackageRequirementMode());

        return self::applyPresets($config, $ruleRegistry, $packagePresets);
    }

    /**
     * @throws ConfigurationException
     */
    public static function applyPresets(
        Config $config,
        RuleRegistry $ruleRegistry,
        ?PackagePresets $packagePresets = null,
    ): Config {
        $packagePresets ??= PackagePresets::shared();

        if (! $config->has('preset')) {
            $config->setPreset([RulePreset::RECOMMENDED->value]);
        }

        $presetRules = [];
        $contributions = [];

        foreach ($config->getPreset() as $name) {
            $preset = RulePreset::tryFromName($name);

            if ($preset !== null) {
                $presetRules = array_merge($presetRules, $preset->rules($ruleRegistry));

                continue;
            }

            $packageRules = $packagePresets->rulesFor($name, $ruleRegistry);

            if ($packageRules === null) {
                throw ConfigurationException::unknownPreset(
                    $name,
                    array_merge(RulePreset::names(), $packagePresets->allNames())
                );
            }

            $presetRules = array_merge($presetRules, $packageRules);
            $contributions[$name] = self::contributionNote($name, $packageRules, $ruleRegistry, $packagePresets);
        }

        if ($contributions !== []) {
            $config->setPresetContributions($contributions);
        }

        if (! $config->has('paths')) {
            $config->setPaths(self::DEFAULT_PATHS);
        }

        if (! $config->has('ignore')) {
            $config->setIgnore(self::DEFAULT_IGNORE);
        }

        $config->setRules(array_merge($presetRules, $config->getRules()));
        self::validateRuleOptions($config, $ruleRegistry);

        return $config;
    }

    private static function validateRuleOptions(Config $config, RuleRegistry $ruleRegistry): void
    {
        foreach (array_keys($config->getRules()) as $ruleId) {
            $rule = $ruleRegistry->find($ruleId);
            if ($rule === null) {
                continue;
            }

            $rule = clone $rule;
            $rule->setOptions($config->getRuleOptions($ruleId));
        }
    }

    /**
     * @param  array<string, string|array{0: string, 1: array<string, mixed>}>  $contributed
     * @return array{declared: int, contributed: int, skippedDueToPackages: array<string>, unknown: array<string>}
     */
    private static function contributionNote(
        string $name,
        array $contributed,
        RuleRegistry $ruleRegistry,
        PackagePresets $packagePresets,
    ): array {
        $declared = $packagePresets->declared($name) ?? [];
        $droppedIds = array_keys(array_diff_key($declared, $contributed));

        $gated = array_values(array_filter(
            $droppedIds,
            $ruleRegistry->isSkippedDueToPackages(...)
        ));

        return [
            'declared' => count($declared),
            'contributed' => count($contributed),
            'skippedDueToPackages' => $gated,
            'unknown' => array_values(array_diff($droppedIds, $gated)),
        ];
    }
}
