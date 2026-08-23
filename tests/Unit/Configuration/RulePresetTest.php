<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Configuration\DefaultConfigFactory;
use Forte\Sheath\Configuration\RulePreset;
use Forte\Sheath\Exceptions\ConfigurationException;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleRegistry;

function presetRegistry(): RuleRegistry
{
    $registry = new RuleRegistry;
    $registry->discoverRules(__DIR__.'/../../../src/Rules');

    return $registry;
}

describe('RulePreset', function (): void {
    it('resolves names case-insensitively and ignores surrounding space', function (): void {
        expect(RulePreset::tryFromName('  RECOMMENDED '))->toBe(RulePreset::RECOMMENDED)
            ->and(RulePreset::tryFromName('nope'))->toBeNull();
    });

    it('assigns only valid severities', function (): void {
        foreach (RulePreset::cases() as $preset) {
            foreach ($preset->declaredRules() as $severity) {
                expect(Severity::fromString($severity))->not->toBe(Severity::OFF);
            }
        }
    });

    it('names only rules that exist', function (): void {
        $registered = presetRegistry()->all();

        foreach (RulePreset::cases() as $preset) {
            expect(array_diff(array_keys($preset->declaredRules()), $registered))
                ->toBe([], "Preset '{$preset->value}' names a rule that does not exist");
        }
    });

    it('places every registered rule deliberately', function (): void {
        expect(array_values(array_diff(presetRegistry()->all(), RulePreset::classifiedRuleIds())))
            ->toBe([]);
    });

    it('keeps opt-in-only rules out of every preset', function (): void {
        $registry = presetRegistry();

        foreach (RulePreset::optInOnlyRuleIds() as $ruleId) {
            expect(in_array($ruleId, $registry->all(), true))
                ->toBeTrue("opt-in-only rule '{$ruleId}' does not exist");

            foreach (RulePreset::cases() as $preset) {
                expect($preset->declaredRules())
                    ->not->toHaveKey($ruleId, "'{$ruleId}' is opt-in only but {$preset->value} enables it");
            }
        }
    });

    it('never classifies a rule under two of the orthogonal presets', function (): void {
        $axes = [RulePreset::STRICT, RulePreset::STYLISTIC, RulePreset::MIGRATION];

        foreach ($axes as $index => $axis) {
            foreach (array_slice($axes, $index + 1) as $other) {
                expect(array_intersect_key($axis->declaredRules(), $other->declaredRules()))
                    ->toBe([], "A rule appears in both {$axis->value} and {$other->value}");
            }
        }
    });

    it('makes strict a superset of recommended and reserves noisy security rules for it', function (): void {
        $recommended = RulePreset::RECOMMENDED->declaredRules();
        $strict = RulePreset::STRICT->declaredRules();

        expect(array_diff_key($recommended, $strict))->toBe([])
            ->and(count($strict))->toBeGreaterThan(count($recommended))
            ->and($recommended)->not->toHaveKey('security-no-inline-js')
            ->not->toHaveKey('security-no-raw-echo')
            ->and($strict)->toHaveKey('security-no-inline-js', 'warning')
            ->toHaveKey('security-no-raw-echo', 'error');
    });

    it('treats structurally invalid forelse blocks as errors', function (): void {
        expect(RulePreset::RECOMMENDED->declaredRules())
            ->toHaveKey('blade-forelse-has-empty', 'error');
    });

    it('resolves empty to no rules', function (): void {
        expect(RulePreset::EMPTY->rules(presetRegistry()))->toBe([]);
    });
});

describe('preset resolution', function (): void {
    it('applies recommended when a config names no preset', function (): void {
        $config = DefaultConfigFactory::resolve(['paths' => ['resources/views']], presetRegistry());

        expect($config->getPreset())->toBe(['recommended'])
            ->and($config->getRules())->toHaveKey('security-csrf-field');
    });

    it('accepts a single preset name as a string', function (): void {
        $config = DefaultConfigFactory::resolve(['preset' => 'strict'], presetRegistry());

        expect($config->getPreset())->toBe(['strict'])
            ->and($config->getRules())->toHaveKey('seo-canonical-tag');
    });

    it('composes correctness and style presets', function (): void {
        $config = DefaultConfigFactory::resolve(
            ['preset' => ['recommended', 'stylistic']],
            presetRegistry()
        );

        expect($config->getRules())->toHaveKey('security-csrf-field')
            ->and($config->getRules())->toHaveKey('blade-prefer-unless');
    });

    it('lets a config opt out with the empty preset', function (): void {
        $config = DefaultConfigFactory::resolve([
            'preset' => 'empty',
            'rules' => ['a11y-alt-text' => 'error'],
        ], presetRegistry());

        expect($config->getRules())->toBe(['a11y-alt-text' => 'error']);
    });

    it('lets project rules override the preset severity', function (): void {
        $config = DefaultConfigFactory::resolve([
            'preset' => 'recommended',
            'rules' => ['security-csrf-field' => 'off'],
        ], presetRegistry());

        expect($config->getRules()['security-csrf-field'])->toBe('off');
    });

    it('keeps preset rules a project does not mention', function (): void {
        $config = DefaultConfigFactory::resolve([
            'rules' => ['security-csrf-field' => 'off'],
        ], presetRegistry());

        expect($config->getRules())->toHaveKey('a11y-alt-text');
    });

    it('applies presets left to right', function (): void {
        $config = DefaultConfigFactory::resolve([
            'preset' => ['strict', 'empty'],
        ], presetRegistry());

        expect($config->getRules())->toHaveKey('a11y-alt-text');
    });

    it('rejects an unknown preset', function (): void {
        expect(fn () => DefaultConfigFactory::resolve(['preset' => 'reccomended'], presetRegistry()))
            ->toThrow(ConfigurationException::class);
    });

    it('falls back to recommended when there is no config at all', function (): void {
        $config = DefaultConfigFactory::resolve(null, presetRegistry());

        expect($config->getPreset())->toBe(['recommended'])
            ->and($config->getPaths())->toBe(['resources/views']);
    });

    it('does not enable stylistic rules by default', function (): void {
        $config = DefaultConfigFactory::resolve(null, presetRegistry());

        expect($config->getRules())->not->toHaveKey('blade-prefer-unless')
            ->and($config->getRules())->not->toHaveKey('blade-prefer-forelse')
            ->and($config->getRules())->not->toHaveKey('best-practices-self-closing-void-elements')
            ->and($config->getRules())->not->toHaveKey('best-practices-no-inline-styles');
    });

    it('does not enable whole-page checks by default', function (): void {
        $config = DefaultConfigFactory::resolve(null, presetRegistry());

        expect($config->getRules())->not->toHaveKey('seo-canonical-tag')
            ->and($config->getRules())->not->toHaveKey('seo-meta-description')
            ->and($config->getRules())->not->toHaveKey('seo-require-open-graph');
    });

    it('reaches rules added after a project published its config', function (): void {
        $config = Config::fromArray(['rules' => ['a11y-alt-text' => 'error']]);
        DefaultConfigFactory::applyPresets($config, presetRegistry());

        expect($config->getRules())->toHaveKey('blade-unclosed-directives');
    });

    it('lets the command line stand in for the preset a config names', function (): void {
        $config = DefaultConfigFactory::resolve(
            ['preset' => 'recommended'],
            presetRegistry(),
            ['migration']
        );

        expect($config->getRules())->toHaveKey('blade-prefer-component-tags')
            ->and($config->getRules())->not->toHaveKey('security-csrf-field');
    });

    it('keeps a project rule override when the command line names a preset', function (): void {
        $config = DefaultConfigFactory::resolve(
            ['preset' => 'recommended', 'rules' => ['a11y-alt-text' => 'error']],
            presetRegistry(),
            ['migration']
        );

        expect($config->getRules()['a11y-alt-text'])->toBe('error');
    });

    it('applies a command-line preset when the project has no config at all', function (): void {
        $config = DefaultConfigFactory::resolve(null, presetRegistry(), ['stylistic']);

        expect($config->getRules())->toHaveKey('blade-prefer-unless')
            ->and($config->getRules())->not->toHaveKey('security-csrf-field');
    });

    it('matches the preset the shipped config file names', function (): void {
        /** @var array<string, mixed> $shipped */
        $shipped = require __DIR__.'/../../../config/sheath.php';

        expect($shipped['preset'])->toBe(RulePreset::RECOMMENDED->value);
    });
});
