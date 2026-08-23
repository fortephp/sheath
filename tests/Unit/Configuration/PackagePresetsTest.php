<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Configuration\DefaultConfigFactory;
use Forte\Sheath\Configuration\PackagePresets;
use Forte\Sheath\Exceptions\ConfigurationException;
use Forte\Sheath\Packages\Dependencies;
use Forte\Sheath\Packages\PackageRequirementEvaluator;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\BestPractices\Attributes\ButtonTypeRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Rules\Security\NoRawEchoRule;

afterEach(function (): void {
    PackagePresets::reset();
});

#[RequiresPackage('acme/not-installed')]
class PresetGatedProbeRule extends AbstractRule
{
    public function getId(): string
    {
        return 'preset-gated-probe';
    }

    public function getDescription(): string
    {
        return 'Rule used to exercise package gating in preset resolution.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void {}
}

function packageGatedRegistry(): RuleRegistry
{
    $registry = new RuleRegistry;
    $registry->setPackageRequirementEvaluator(new PackageRequirementEvaluator(
        Dependencies::fromData(
            ['require' => ['laravel/framework' => '^11.0']],
            ['packages' => [['name' => 'laravel/framework', 'version' => 'v11.0.0']]]
        )
    ));
    $registry->register(PresetGatedProbeRule::class);
    $registry->register(ButtonTypeRule::class);

    return $registry;
}

function packagePresetRegistry(): RuleRegistry
{
    $registry = new RuleRegistry;
    $registry->registerMany([ButtonTypeRule::class, NoRawEchoRule::class]);

    return $registry;
}

describe('instance storage', function (): void {
    it('keeps presets on the instance, not in process-global state', function (): void {
        $isolated = new PackagePresets;
        $isolated->add('acme', ['best-practices-button-type' => 'error']);

        expect($isolated->declared('acme'))->toBe(['best-practices-button-type' => 'error'])
            ->and(PackagePresets::shared()->declared('acme'))->toBeNull()
            ->and(PackagePresets::names())->not->toContain('acme');
    });

    it('routes the static API through the shared instance', function (): void {
        PackagePresets::register('acme', ['best-practices-button-type' => 'error']);

        expect(PackagePresets::shared()->declared('acme'))->toBe(['best-practices-button-type' => 'error']);
    });

    it('lets a test swap the shared instance and restore it', function (): void {
        $original = PackagePresets::shared();
        $replacement = new PackagePresets;
        $replacement->add('acme', ['best-practices-button-type' => 'error']);

        PackagePresets::swap($replacement);

        try {
            expect(PackagePresets::names())->toBe(['acme']);
        } finally {
            PackagePresets::swap($original);
        }

        expect(PackagePresets::names())->not->toContain('acme');
    });

    it('resolves against an explicitly provided store', function (): void {
        $store = new PackagePresets;
        $store->add('acme', ['best-practices-button-type' => 'error']);

        $config = DefaultConfigFactory::resolve(
            ['preset' => ['empty', 'acme']],
            packagePresetRegistry(),
            packagePresets: $store
        );

        expect($config->getRules())->toHaveKey('best-practices-button-type')
            ->and(PackagePresets::names())->not->toContain('acme');
    });
});

it('resolves a package preset composed with built-ins', function (): void {
    PackagePresets::register('acme', [
        'best-practices-button-type' => 'error',
        'acme-not-registered' => 'error',
    ]);

    $config = DefaultConfigFactory::resolve(
        ['preset' => ['empty', 'acme']],
        packagePresetRegistry()
    );

    expect($config->getRules())->toHaveKey('best-practices-button-type')
        ->and($config->getRules())->not->toHaveKey('acme-not-registered');
});

it('lets project rules override a package preset severity', function (): void {
    PackagePresets::register('acme', ['best-practices-button-type' => 'error']);

    $config = DefaultConfigFactory::resolve(
        ['preset' => ['empty', 'acme'], 'rules' => ['best-practices-button-type' => 'off']],
        packagePresetRegistry()
    );

    expect($config->getRules()['best-practices-button-type'])->toBe('off');
});

it('still rejects unknown preset names', function (): void {
    PackagePresets::register('acme', []);

    expect(fn () => DefaultConfigFactory::resolve(['preset' => 'acmee'], packagePresetRegistry()))
        ->toThrow(ConfigurationException::class);
});

it('refuses to shadow a built-in preset', function (): void {
    expect(fn () => PackagePresets::register('recommended', []))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects duplicate names after case and whitespace normalization', function (): void {
    PackagePresets::register('Acme', ['best-practices-button-type' => 'error']);

    expect(fn () => PackagePresets::register(' acME ', ['security-no-raw-echo' => 'warning']))
        ->toThrow(InvalidArgumentException::class)
        ->and(PackagePresets::declaredRules('acme'))
        ->toBe(['best-practices-button-type' => 'error']);
});

it('allows an identical preset to be registered idempotently', function (): void {
    $rules = ['best-practices-button-type' => 'error'];

    PackagePresets::register('Acme', $rules);
    PackagePresets::register(' acME ', $rules);

    expect(PackagePresets::declaredRules('acme'))->toBe($rules);
});

it('carries preset-declared options through to the resolved config', function (): void {
    PackagePresets::register('acme', [
        'security-no-raw-echo' => ['error', ['allowed' => ['$trusted']]],
    ]);

    $config = DefaultConfigFactory::resolve(['preset' => ['empty', 'acme']], packagePresetRegistry());

    expect($config->getRules()['security-no-raw-echo'])->toBe(['error', ['allowed' => ['$trusted']]]);
});

describe('register-time validation', function (): void {
    it('rejects an invalid severity string', function (): void {
        expect(fn () => PackagePresets::register('acme', ['best-practices-button-type' => 'banana']))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects an invalid severity inside a tuple entry', function (): void {
        expect(fn () => PackagePresets::register('acme', ['best-practices-button-type' => ['banana']]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects a non-string severity inside a tuple entry', function (): void {
        expect(fn () => PackagePresets::register('acme', ['best-practices-button-type' => [2]]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects entry values that are neither string nor array', function (mixed $entry): void {
        expect(fn () => PackagePresets::register('acme', ['best-practices-button-type' => $entry]))
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'integer' => 42,
        'null' => null,
        'boolean' => true,
    ]);

    it('rejects a list of rule IDs', function (): void {
        expect(fn () => PackagePresets::register('acme', ['acme-rule-one', 'acme-rule-two']))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects non-array options', function (): void {
        expect(fn () => PackagePresets::register('acme', ['best-practices-button-type' => ['error', 'nope']]))
            ->toThrow(InvalidArgumentException::class);
    });

    it('rejects misspelled or otherwise unknown entry keys', function (): void {
        expect(fn () => PackagePresets::register('acme', [
            'best-practices-button-type' => ['severty' => 'error'],
        ]))->toThrow(InvalidArgumentException::class);
    });

    it('rejects sparse positional entries', function (): void {
        expect(fn () => PackagePresets::register('acme', [
            'best-practices-button-type' => [1 => ['allowed' => ['x']]],
        ]))->toThrow(InvalidArgumentException::class);
    });

    it('rejects an exclude option that is not a list of strings', function (mixed $exclude): void {
        expect(fn () => PackagePresets::register('acme', [
            'best-practices-button-type' => ['warning', ['exclude' => $exclude]],
        ]))->toThrow(InvalidArgumentException::class);
    })->with([
        'bare string' => ['views/native/'],
        'mixed list' => [['views/native/', 42]],
        'map' => [['native' => 'views/native/']],
    ]);

    it('accepts every documented entry shape', function (): void {
        PackagePresets::register('acme', [
            'acme-shorthand' => 'error',
            'acme-off' => 'off',
            'acme-numeric' => '2',
            'acme-tuple' => ['warning', ['exclude' => ['views/native/']]],
            'acme-long-form' => ['severity' => 'error', 'options' => ['allowed' => ['$x']]],
            'acme-default-severity' => [],
            'acme-empty-exclude' => ['warning', ['exclude' => []]],
        ]);

        expect(PackagePresets::names())->toContain('acme');
    });

    it('rejects the whole preset atomically, registering nothing', function (): void {
        try {
            PackagePresets::register('acme', [
                'acme-fine' => 'error',
                'acme-broken' => 'banana',
            ]);
        } catch (InvalidArgumentException) {
        }

        expect(PackagePresets::names())->not->toContain('acme');
    });

    it('still allows rule IDs the registry has never heard of', function (): void {
        PackagePresets::register('acme', ['acme-from-an-optional-companion' => 'error']);

        $config = DefaultConfigFactory::resolve(['preset' => ['empty', 'acme']], packagePresetRegistry());

        expect($config->getRules())->not->toHaveKey('acme-from-an-optional-companion');
    });
});

describe('contribution notes', function (): void {
    it('exposes the declared map before the registry intersection', function (): void {
        PackagePresets::register('acme', ['acme-unknown' => 'error']);

        expect(PackagePresets::declaredRules('acme'))->toBe(['acme-unknown' => 'error'])
            ->and(PackagePresets::declaredRules('missing'))->toBeNull();
    });

    it('records what each package preset contributed and what was withheld', function (): void {
        PackagePresets::register('acme', [
            'best-practices-button-type' => 'error',
            'preset-gated-probe' => 'warning',
            'acme-unknown' => 'error',
        ]);

        $config = DefaultConfigFactory::resolve(
            ['preset' => ['empty', 'acme']],
            packageGatedRegistry()
        );

        expect($config->getPresetContributions())->toBe([
            'acme' => [
                'declared' => 3,
                'contributed' => 1,
                'skippedDueToPackages' => ['preset-gated-probe'],
                'unknown' => ['acme-unknown'],
            ],
        ]);
    });

    it('records a zero contribution when every declared rule is package-gated', function (): void {
        PackagePresets::register('acme', ['preset-gated-probe' => 'warning']);

        $config = DefaultConfigFactory::resolve(
            ['preset' => ['empty', 'acme']],
            packageGatedRegistry()
        );

        expect($config->getPresetContributions()['acme']['contributed'])->toBe(0)
            ->and($config->getPresetContributions()['acme']['skippedDueToPackages'])->toBe(['preset-gated-probe']);
    });

    it('applies ignore mode before intersecting a package preset', function (): void {
        PackagePresets::register('acme', ['preset-gated-probe' => 'warning']);
        $registry = packageGatedRegistry();

        $config = DefaultConfigFactory::resolve(
            [
                'preset' => ['empty', 'acme'],
                'packageRequirementMode' => 'ignore',
            ],
            $registry
        );

        expect($config->getRules())->toHaveKey('preset-gated-probe')
            ->and($config->getPresetContributions()['acme'])->toMatchArray([
                'declared' => 1,
                'contributed' => 1,
                'skippedDueToPackages' => [],
            ]);
    });

    it('records nothing for built-in presets', function (): void {
        $config = DefaultConfigFactory::resolve(['preset' => 'recommended'], packagePresetRegistry());

        expect($config->getPresetContributions())->toBe([]);
    });

    it('survives the toArray/fromArray round trip the command pipeline performs', function (): void {
        PackagePresets::register('acme', ['preset-gated-probe' => 'warning']);

        $config = DefaultConfigFactory::resolve(
            ['preset' => ['empty', 'acme']],
            packageGatedRegistry()
        );

        $restored = Config::fromArray($config->toArray());

        expect($restored->getPresetContributions())->toBe($config->getPresetContributions());
    });

    it('keeps derived data out of an unresolved config array', function (): void {
        expect(Config::make()->toArray())->not->toHaveKey('presetContributions');
    });
});
