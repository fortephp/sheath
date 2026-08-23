<?php

declare(strict_types=1);

use Forte\Sheath\Baselines\Baseline;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Configuration\RulePreset;
use Forte\Sheath\Exceptions\ConfigurationException;
use Forte\Sheath\Packages\PackageRequirementMode;
use Forte\Sheath\Results\Severity;

describe('Sheath Config', function (): void {
    it('gets rule severity from string config', function (): void {
        $config = Config::make(['rules' => ['test-rule' => 'error']]);

        expect($config->getRuleSeverity('test-rule'))
            ->toBe(Severity::ERROR);
    });

    it('gets rule severity from array config with severity key', function (): void {
        $config = Config::make(['rules' => ['test-rule' => ['severity' => 'warning']]]);

        expect($config->getRuleSeverity('test-rule'))->toBe(Severity::WARNING);
    });

    it('gets rule severity from array config with numeric index', function (): void {
        $config = Config::make(['rules' => ['test-rule' => ['error', ['option' => 'value']]]]);

        expect($config->getRuleSeverity('test-rule'))
            ->toBe(Severity::ERROR);
    });

    it('returns null for unconfigured rule', function (): void {
        $config = Config::make();

        expect($config->getRuleSeverity('unknown-rule'))->toBeNull();
    });

    it('gets rule options from array config', function (): void {
        $config = Config::make(['rules' => ['test-rule' => ['error', ['option1' => 'value1']]]]);

        expect($config->getRuleOptions('test-rule'))
            ->toBe(['option1' => 'value1']);
    });

    it('gets rule options from array config with options key', function (): void {
        $config = Config::make([
            'rules' => [
                'test-rule' => [
                    'severity' => 'error',
                    'options' => ['option1' => 'value1'],
                ],
            ],
        ]);

        expect($config->getRuleOptions('test-rule'))
            ->toBe(['option1' => 'value1']);
    });

    it('returns empty array for rule without options', function (): void {
        $config = Config::make(['rules' => ['test-rule' => 'error']]);

        expect($config->getRuleOptions('test-rule'))
            ->toBe([]);
    });

    it('can merge configs', function (): void {
        $config1 = Config::make(['rules' => ['rule1' => 'error'], 'ignore' => ['vendor/**']]);
        $config2 = Config::make(['rules' => ['rule2' => 'warning'], 'ignore' => ['storage/**']]);

        $config1->merge($config2);

        expect($config1->getRules())->toBe(['rule1' => 'error', 'rule2' => 'warning'])
            ->and($config1->getIgnore())->toBe(['vendor/**', 'storage/**']);
    });

    it('loads baseline line tolerance from array', function (): void {
        $config = Config::fromArray([
            'baselineLineTolerance' => 5,
        ]);

        expect($config->getBaselineLineTolerance())
            ->toBe(5);
    });

    it('enforces non-negative baseline line tolerance', function (): void {
        $config = Config::fromArray([
            'baselineLineTolerance' => -1,
        ]);

        expect($config->getBaselineLineTolerance())
            ->toBe(0);
    });

    it('merges baseline line tolerance from the other config', function (): void {
        $config1 = Config::make();
        $config2 = Config::make()->setBaselineLineTolerance(10);

        $config1->merge($config2);

        expect($config1->getBaselineLineTolerance())->toBe(10);
    });

    it('merges a baseline line tolerance that equals the default', function (): void {
        $config1 = Config::make()->setBaselineLineTolerance(9);
        $config2 = Config::fromArray(['baselineLineTolerance' => Baseline::DEFAULT_LINE_TOLERANCE]);

        $config1->merge($config2);

        expect($config1->getBaselineLineTolerance())->toBe(Baseline::DEFAULT_LINE_TOLERANCE);
    });

    it('leaves baseline line tolerance alone when the other config never set it', function (): void {
        $config1 = Config::make()->setBaselineLineTolerance(9);

        $config1->merge(Config::make());

        expect($config1->getBaselineLineTolerance())->toBe(9);
    });

    describe('configured versus default', function (): void {
        it('reports nothing as configured on a fresh config', function (string $key): void {
            expect(Config::make()->has($key))->toBeFalse();
        })->with(['preset', 'paths', 'ignore', 'rules', 'baselineLineTolerance', 'neverFix', 'packageRequirementMode']);

        it('reports a setting as configured once fromArray supplies it', function (string $key, mixed $value): void {
            expect(Config::fromArray([$key => $value])->has($key))->toBeTrue();
        })->with([
            ['preset', 'strict'],
            ['paths', ['app/views']],
            ['ignore', ['vendor/**']],
            ['rules', ['a11y-alt-text' => 'error']],
            ['baselineLineTolerance', 5],
            ['neverFix', ['a11y-alt-text']],
            ['packageRequirementMode', 'ignore'],
        ]);

        it('treats a setting explicitly set to its default as configured', function (): void {
            $config = Config::fromArray(['packageRequirementMode' => 'skip']);

            expect($config->has('packageRequirementMode'))->toBeTrue()
                ->and($config->getPackageRequirementMode())->toBe(PackageRequirementMode::SKIP);
        });

        it('lets a merge push a setting back to its default value', function (): void {
            $base = Config::make()->setPackageRequirementMode(PackageRequirementMode::IGNORE);

            $base->merge(Config::fromArray(['packageRequirementMode' => 'skip']));

            expect($base->getPackageRequirementMode())->toBe(PackageRequirementMode::SKIP);
        });

        it('lets a merge clear a list back to empty', function (): void {
            $base = Config::make()->setPaths(['resources/views']);

            $base->merge(Config::make()->setPaths([]));

            expect($base->getPaths())->toBe([]);
        });

    });

    describe('single rule configuration', function (): void {
        it('adds a rule without disturbing the others', function (): void {
            $config = Config::fromArray(['rules' => ['a11y-alt-text' => 'error']])
                ->setRule('security-csrf-field', 'warning');

            expect($config->getRules())->toBe([
                'a11y-alt-text' => 'error',
                'security-csrf-field' => 'warning',
            ]);
        });

        it('replaces a rule that is already configured', function (): void {
            $config = Config::fromArray(['rules' => ['a11y-alt-text' => 'error']])
                ->setRule('a11y-alt-text', 'off');

            expect($config->getRuleSeverity('a11y-alt-text'))->toBe(Severity::OFF);
        });

        it('accepts the severity-with-options shorthand', function (): void {
            $config = Config::make()->setRule('security-no-raw-echo', ['error', ['allowed' => ['$html']]]);

            expect($config->getRuleSeverity('security-no-raw-echo'))->toBe(Severity::ERROR)
                ->and($config->getRuleOptions('security-no-raw-echo'))->toBe(['allowed' => ['$html']]);
        });

    });

    it('replaces paths when the other config sets them explicitly', function (): void {
        $config1 = Config::fromArray(['paths' => ['resources/views']]);
        $config2 = Config::fromArray(['paths' => ['app/View']]);

        $config1->merge($config2);

        expect($config1->getPaths())->toBe(['app/View']);
    });

    describe('neverFix configuration', function (): void {
        it('loads neverFix and checks membership', function (): void {
            $config = Config::fromArray([
                'neverFix' => ['a11y-alt-text', 'security-no-inline-js'],
            ]);

            expect($config->getNeverFix())->toBe(['a11y-alt-text', 'security-no-inline-js'])
                ->and($config->shouldNeverFix('a11y-alt-text'))->toBeTrue()
                ->and($config->shouldNeverFix('security-no-inline-js'))->toBeTrue()
                ->and($config->shouldNeverFix('unknown-rule'))->toBeFalse();
        });

        it('merges neverFix arrays without duplicates', function (): void {
            $config1 = Config::make(['neverFix' => ['rule1', 'rule2']]);
            $config2 = Config::make(['neverFix' => ['rule2', 'rule3']]);

            $config1->merge($config2);

            expect($config1->getNeverFix())->toBe(['rule1', 'rule2', 'rule3']);
        });

        it('omits an unconfigured neverFix from toArray, but resolves it in toResolvedArray', function (): void {
            $config = Config::make();

            expect($config->toArray())->not->toHaveKey('neverFix')
                ->and($config->toResolvedArray())->toHaveKey('neverFix')
                ->and($config->toResolvedArray()['neverFix'])->toBe([]);
        });

    });

    describe('packageRequirementMode configuration', function (): void {
        it('loads packageRequirementMode from array', function (string $mode, PackageRequirementMode $expected): void {
            expect(Config::fromArray(['packageRequirementMode' => $mode])->getPackageRequirementMode())
                ->toBe($expected);
        })->with([
            'disable' => ['disable', PackageRequirementMode::DISABLE],
            'ignore' => ['ignore', PackageRequirementMode::IGNORE],
        ]);

        it('rejects an invalid mode instead of silently defaulting to skip', function (): void {
            expect(fn (): Config => Config::fromArray([
                'packageRequirementMode' => 'invalid',
            ]))->toThrow(ConfigurationException::class);
        });

        it('merges packageRequirementMode when different from default', function (): void {
            $config1 = Config::make();
            $config2 = Config::make(['packageRequirementMode' => 'disable']);

            $config1->merge($config2);

            expect($config1->getPackageRequirementMode())->toBe(PackageRequirementMode::DISABLE);
        });

        it('does not merge packageRequirementMode when other is default', function (): void {
            $config1 = Config::make(['packageRequirementMode' => 'ignore']);
            $config2 = Config::make();

            $config1->merge($config2);

            expect($config1->getPackageRequirementMode())->toBe(PackageRequirementMode::IGNORE);
        });

    });

    describe('value validation in fromArray', function (): void {
        it('coerces a bare string to a single-element list for list keys', function (): void {
            $config = Config::fromArray([
                'paths' => 'resources/views',
                'ignore' => 'vendor/**',
                'neverFix' => 'a11y-alt-text',
            ]);

            expect($config->getPaths())->toBe(['resources/views'])
                ->and($config->getIgnore())->toBe(['vendor/**'])
                ->and($config->getNeverFix())->toBe(['a11y-alt-text']);
        });

        it('rejects non-coercible types for list keys', function (string $key): void {
            expect(fn (): Config => Config::fromArray([$key => 42]))
                ->toThrow(ConfigurationException::class);
        })->with(['paths', 'ignore', 'neverFix']);

        it('rejects non-string entries inside list settings instead of silently dropping them', function (string $key): void {
            expect(fn (): Config => Config::fromArray([$key => ['valid', 42]]))
                ->toThrow(ConfigurationException::class);
        })->with(['paths', 'ignore', 'neverFix']);

        it('rejects a non-array rules value', function (): void {
            expect(fn (): Config => Config::fromArray(['rules' => 'a11y-alt-text']))
                ->toThrow(ConfigurationException::class);
        });

        it('rejects a non-integer baselineLineTolerance', function (): void {
            expect(fn (): Config => Config::fromArray(['baselineLineTolerance' => '5']))
                ->toThrow(ConfigurationException::class);
        });

        it('rejects a non-boolean inlineSuppressions', function (): void {
            expect(fn (): Config => Config::fromArray(['inlineSuppressions' => 'yes']))
                ->toThrow(ConfigurationException::class);
        });

        it('rejects a non-string packageRequirementMode', function (): void {
            expect(fn (): Config => Config::fromArray(['packageRequirementMode' => true]))
                ->toThrow(ConfigurationException::class);
        });

        it('rejects a preset value that is neither string nor array', function (): void {
            expect(fn (): Config => Config::fromArray(['preset' => 42]))
                ->toThrow(ConfigurationException::class);
        });

        it('rejects null for every nullable-looking setting instead of treating it as absent', function (string $key): void {
            expect(fn (): Config => Config::fromArray([$key => null]))
                ->toThrow(ConfigurationException::class);
        })->with([
            'preset',
            'paths',
            'rules',
            'ignore',
            'baselineLineTolerance',
            'neverFix',
            'packageRequirementMode',
            'inlineSuppressions',
        ]);

        it('rejects non-string entries inside the preset list', function (): void {
            expect(fn (): Config => Config::fromArray(['preset' => ['recommended', 42]]))
                ->toThrow(ConfigurationException::class);
        });

        it('rejects a list where the rules map requires string rule IDs', function (): void {
            expect(fn (): Config => Config::fromArray(['rules' => ['error']]))
                ->toThrow(ConfigurationException::class);
        });
    });

    describe('rule severity validation', function (): void {
        it('rejects rule entries that are neither severity strings nor configuration arrays', function (mixed $entry): void {
            expect(fn (): Config => Config::fromArray(['rules' => ['a11y-alt-text' => $entry]]))
                ->toThrow(ConfigurationException::class);
        })->with([
            'null' => null,
            'boolean' => false,
            'integer' => 2,
        ]);

        it('rejects malformed rule configuration arrays instead of using defaults', function (array $entry): void {
            expect(fn (): Config => Config::fromArray(['rules' => ['a11y-alt-text' => $entry]]))
                ->toThrow(ConfigurationException::class);
        })->with([
            'non-string severity' => [['severity' => false]],
            'non-array named options' => [['options' => 'allowed=*']],
            'non-array tuple options' => [['warning', 'allowed=*']],
            'options in the severity position' => [[['allowed' => ['*']]]],
            'unknown long-form key' => [['severity' => 'warning', 'option' => []]],
        ]);

        it('rejects an invalid severity in every configuration form', function (string|array $entry): void {
            expect(fn (): Config => Config::fromArray(['rules' => ['a11y-alt-text' => $entry]]))
                ->toThrow(ConfigurationException::class);
        })->with([
            'string' => ['eror'],
            'tuple' => [['eror', ['x' => 1]]],
            'named' => [['severity' => 'eror']],
        ]);

        it('accepts every documented severity spelling', function (): void {
            $config = Config::fromArray(['rules' => [
                'rule-a' => 'error',
                'rule-b' => 'warn',
                'rule-c' => '2',
                'rule-d' => 'off',
                'rule-e' => ['warning', ['x' => 1]],
                'rule-f' => ['severity' => 'info'],
            ]]);

            expect($config->getRuleSeverity('rule-b'))->toBe(Severity::WARNING);
        });
    });

    describe('shape validation', function (): void {
        it('rejects unknown keys', function (): void {
            expect(fn () => Config::assertValidShape(['rule' => []], 'config/sheath.php'))
                ->toThrow(ConfigurationException::class);
        });

        it('rejects a list', function (): void {
            expect(fn () => Config::assertValidShape(['a11y-alt-text'], 'config/sheath.php'))
                ->toThrow(ConfigurationException::class);
        });

    });

    describe('the config file the package publishes', function (): void {
        it('covers every key the config understands, and no others', function (): void {
            /** @var array<string, mixed> $shipped */
            $shipped = require __DIR__.'/../../../config/sheath.php';

            expect(array_keys($shipped))->toEqualCanonicalizing(Config::RECOGNIZED_KEYS)
                ->and(array_keys($shipped))->toEqualCanonicalizing(array_keys(Config::make()->toResolvedArray()));
        });

        it('parses as the config it claims to be', function (): void {
            /** @var array<string, mixed> $shipped */
            $shipped = require __DIR__.'/../../../config/sheath.php';

            $config = Config::fromArray($shipped);

            expect($config->getPreset())->toBe([RulePreset::RECOMMENDED->value])
                ->and($config->getPaths())->toBe(['resources/views'])
                ->and($config->respectsInlineSuppressions())->toBeTrue()
                ->and($config->getPackageRequirementMode())->toBe(PackageRequirementMode::SKIP)
                ->and($config->getBaselineLineTolerance())->toBe(Baseline::DEFAULT_LINE_TOLERANCE);
        });
    });
});
