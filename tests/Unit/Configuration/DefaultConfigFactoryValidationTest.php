<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\DefaultConfigFactory;
use Forte\Sheath\Exceptions\ConfigurationException;
use Forte\Sheath\Rules\BestPractices\Attributes\ButtonTypeRule;
use Forte\Sheath\Rules\Performance\ResponsiveImagesRule;
use Forte\Sheath\Rules\RuleRegistry;

function validationRegistry(): RuleRegistry
{
    $registry = new RuleRegistry;
    $registry->register(ButtonTypeRule::class);
    $registry->register(ResponsiveImagesRule::class);

    return $registry;
}

describe('DefaultConfigFactory shape validation', function (): void {
    it('rejects unknown keys on the Laravel config path, like --config files', function (): void {
        expect(fn () => DefaultConfigFactory::resolve(
            ['rule' => ['best-practices-button-type' => 'error']],
            validationRegistry()
        ))->toThrow(ConfigurationException::class);
    });

    it('names the config source in the error', function (): void {
        expect(fn () => DefaultConfigFactory::resolve(
            ['pahts' => ['resources/views']],
            validationRegistry()
        ))->toThrow(ConfigurationException::class);
    });

    it('rejects a list where a settings map belongs', function (): void {
        expect(fn () => DefaultConfigFactory::resolve(
            ['best-practices-button-type'],
            validationRegistry()
        ))->toThrow(ConfigurationException::class);
    });

    it('still resolves valid config data', function (): void {
        $config = DefaultConfigFactory::resolve(
            ['preset' => 'empty', 'rules' => ['best-practices-button-type' => 'error']],
            validationRegistry()
        );

        expect($config->getRules())->toHaveKey('best-practices-button-type');
    });

    it('still falls back to defaults when no Laravel config is registered', function (): void {
        $config = DefaultConfigFactory::resolve(null, validationRegistry());

        expect($config->getPreset())->toBe(['recommended']);
    });

    it('rejects non-array Laravel config values instead of treating them as missing', function (mixed $value): void {
        expect(fn () => DefaultConfigFactory::resolve($value, validationRegistry()))
            ->toThrow(ConfigurationException::class);
    })->with([
        'string' => 'recommended',
        'boolean' => false,
        'integer' => 1,
    ]);

    it('surfaces wrong value types from the config path', function (): void {
        expect(fn () => DefaultConfigFactory::resolve(
            ['baselineLineTolerance' => 'three'],
            validationRegistry()
        ))->toThrow(ConfigurationException::class);
    });

    it('rejects unknown built-in rule options while loading config', function (): void {
        expect(fn () => DefaultConfigFactory::resolve([
            'preset' => 'empty',
            'rules' => [
                'perf-responsive-images' => ['info', ['minimumWidth' => 300]],
            ],
        ], validationRegistry()))->toThrow(ConfigurationException::class);
    });

    it('rejects malformed built-in rule options while loading config', function (): void {
        expect(fn () => DefaultConfigFactory::resolve([
            'preset' => 'empty',
            'rules' => [
                'perf-responsive-images' => ['info', ['excludePatterns' => 'icons/']],
            ],
        ], validationRegistry()))->toThrow(ConfigurationException::class);
    });
});
