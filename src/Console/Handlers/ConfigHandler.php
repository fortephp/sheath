<?php

declare(strict_types=1);

namespace Forte\Sheath\Console\Handlers;

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Configuration\DefaultConfigFactory;
use Forte\Sheath\Configuration\PackagePresets;
use Forte\Sheath\Configuration\RulePreset;
use Forte\Sheath\Console\Concerns\WritesNotices;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Exceptions\ConfigurationException;
use Forte\Sheath\Files\FileSystem;
use Forte\Sheath\Files\PathResolver;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/** @internal */
readonly class ConfigHandler
{
    use WritesNotices;

    public function __construct(
        private Command $command,
        private RuleRegistry $ruleRegistry,
    ) {}

    protected function noticeOutput(): OutputStyle
    {
        return $this->command->getOutput();
    }

    /**
     * @param  array<string>  $presets  Presets named on the command line, which
     *                                  stand in for whatever the config names
     *
     * @throws ConfigurationException|Throwable
     */
    public function load(?string $configPath = null, array $presets = []): Config
    {
        if ($configPath !== null && $configPath !== '') {
            return $this->loadFromFile($configPath, $presets);
        }

        return DefaultConfigFactory::resolve(config('sheath'), $this->ruleRegistry, $presets);
    }

    /**
     * @param  array<string>  $presets
     *
     * @throws ConfigurationException|Throwable
     */
    public function loadFromFile(string $path, array $presets = []): Config
    {
        $fullPath = PathResolver::toAbsolutePath($path);

        if (! file_exists($fullPath)) {
            throw ConfigurationException::fileNotFound($path);
        }

        if (! is_readable($fullPath)) {
            throw ConfigurationException::fileNotReadable($path);
        }

        $extension = pathinfo($fullPath, PATHINFO_EXTENSION);

        if ($extension === 'php') {
            $configData = require $fullPath;
        } elseif ($extension === 'json') {
            $content = FileSystem::readFile($fullPath);
            $configData = $content !== null ? json_decode($content, true) : null;
        } else {
            throw ConfigurationException::unsupportedFileFormat($extension);
        }

        if (! is_array($configData)) {
            throw ConfigurationException::invalidFileContents($path);
        }

        Config::assertValidShape($configData, $path);

        /** @var array<string, mixed> $configData */
        return DefaultConfigFactory::resolve($configData, $this->ruleRegistry, $presets, $path);
    }

    public function print(Config $config): void
    {
        $data = $config->toResolvedArray();
        $data['availablePresets'] = array_merge(RulePreset::names(), PackagePresets::names());
        $disabledRuleIds = array_keys($this->ruleRegistry->getDisabledDueToPackages());
        $data['ruleStatus'] = [
            'available' => array_values(array_diff($this->ruleRegistry->all(), $disabledRuleIds)),

            'skippedDueToPackages' => (object) $this->formatPackageRuleStatus($this->ruleRegistry->getSkippedDueToPackages()),
            'disabledDueToPackages' => (object) $this->formatPackageRuleStatus($this->ruleRegistry->getDisabledDueToPackages()),
        ];

        $this->command->getOutput()->writeln(
            (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            OutputInterface::OUTPUT_RAW,
        );
    }

    /**
     * @param  array<string>|string|null  $ruleOverrides
     * @param  array<string>|string|null  $onlyRules
     *
     * @throws ConfigurationException
     */
    public function applyOverrides(Config $config, array|string|null $ruleOverrides, array|string|null $onlyRules): Config
    {
        $rules = $config->getRules();
        $this->assertKnownRules(array_keys($rules));

        $ruleOverrides = is_array($ruleOverrides) ? $ruleOverrides : ($ruleOverrides ? [$ruleOverrides] : []);
        foreach ($ruleOverrides as $override) {
            [$ruleId, $severityStr] = array_pad(explode(':', (string) $override, 2), 2, '');
            $ruleId = trim($ruleId);
            $severityStr = trim($severityStr);

            if ($ruleId === '' || $severityStr === '') {
                throw new InvalidArgumentException(sprintf(
                    "Invalid --rule value '%s': expected rule-id:severity, e.g. a11y-alt-text:error.",
                    trim((string) $override)
                ));
            }

            $this->assertKnownRules([$ruleId]);
            $rules[$ruleId] = Severity::fromString($severityStr)->value;
        }

        if ($onlyRules) {
            $allowedRules = [];
            $onlyRulesArray = is_array($onlyRules) ? $onlyRules : [$onlyRules];
            foreach ($onlyRulesArray as $ruleList) {
                $allowedRules = array_merge($allowedRules, explode(',', (string) $ruleList));
            }

            $allowedRules = array_values(array_unique(array_filter(
                array_map(trim(...), $allowedRules),
                static fn (string $ruleId): bool => $ruleId !== ''
            )));

            $this->assertKnownRules($allowedRules);

            foreach (array_keys($rules) as $ruleId) {
                if (! in_array($ruleId, $allowedRules, true)) {
                    $rules[$ruleId] = Severity::OFF->value;
                }
            }

            foreach ($allowedRules as $ruleId) {
                if (! isset($rules[$ruleId]) && $this->ruleRegistry->hasKnown($ruleId)) {
                    $rules[$ruleId] = ($this->ruleRegistry->find($ruleId)?->getDefaultSeverity() ?? Severity::WARNING)->value;
                }
            }
        }

        $configData = $config->toArray();
        $configData['rules'] = $rules;

        return Config::fromArray($configData);
    }

    /**
     * @param  array<mixed>  $ruleIds
     */
    private function assertKnownRules(array $ruleIds): void
    {
        $unknown = array_values(array_unique(array_filter(
            array_filter($ruleIds, is_string(...)),
            fn (string $ruleId): bool => ! $this->ruleRegistry->hasKnown($ruleId)
        )));

        if ($unknown === []) {
            return;
        }

        if (count($unknown) === 1) {
            throw new InvalidArgumentException("Unknown rule: {$unknown[0]}");
        }

        throw new InvalidArgumentException('Unknown rules: '.implode(', ', $unknown));
    }

    /**
     * @param  array<string, class-string<Rule>>  $rules
     * @return array<string, string>
     */
    private function formatPackageRuleStatus(array $rules): array
    {
        $status = [];

        foreach (array_keys($rules) as $ruleId) {
            $status[$ruleId] = $this->ruleRegistry
                ->getPackageRequirementResultForRuleId($ruleId)
                ?->getDescription() ?? 'Package requirements unavailable';
        }

        return $status;
    }
}
