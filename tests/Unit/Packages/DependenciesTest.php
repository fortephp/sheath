<?php

declare(strict_types=1);

use Forte\Sheath\Packages\Dependencies;

describe('Dependencies', function (): void {
    describe('package indexing', function (): void {
        it('uses only packages present in Composer installed data', function (): void {
            $dependencies = Dependencies::fromInstalledData([[
                'versions' => [
                    'fortephp/sheath' => [
                        'pretty_version' => 'dev-main',
                        'version' => 'dev-main',
                        'aliases' => ['1.0.x-dev'],
                        'dev_requirement' => false,
                    ],
                    'laravel/framework' => [
                        'pretty_version' => '12.24.0',
                        'version' => '12.24.0.0',
                        'aliases' => [],
                        'dev_requirement' => false,
                    ],
                ],
            ]]);

            expect($dependencies->has('fortephp/sheath'))->toBeTrue()
                ->and($dependencies->has('laravel/framework'))->toBeTrue()
                ->and($dependencies->has('laravel/pail'))->toBeFalse()
                ->and($dependencies->has('laravel/pint'))->toBeFalse();
        });

        it('loads the current process package set from Composer', function (): void {
            $dependencies = Dependencies::fromInstalledVersions();

            expect($dependencies->has('composer/semver'))->toBeTrue()
                ->and($dependencies->version('composer/semver'))->not->toBeNull()
                ->and($dependencies->satisfies('composer/semver', '^3.0'))->toBeTrue();
        });

        it('binds Composer installed data through the service provider', function (): void {
            $dependencies = $this->app->make(Dependencies::class);

            expect($dependencies->has('fortephp/sheath'))->toBeTrue()
                ->and($dependencies->version('fortephp/sheath'))->not->toBeNull();
        });

        it('indexes root and transitive packages case-insensitively', function (): void {
            $dependencies = Dependencies::fromData(
                [
                    'require' => ['Laravel/Framework' => '^11.0'],
                    'require-dev' => ['PestPHP/Pest' => '^3.0'],
                ],
                [
                    'packages' => [
                        ['name' => 'laravel/framework', 'version' => '11.5.0'],
                        ['name' => 'package/transitive', 'version' => '2.0.0'],
                    ],
                    'packages-dev' => [['name' => 'pestphp/pest', 'version' => '3.7.0']],
                ]
            );

            expect($dependencies->has('LARAVEL/FRAMEWORK'))->toBeTrue()
                ->and($dependencies->has('pestphp/pest'))->toBeTrue()
                ->and($dependencies->version('package/transitive'))->toBe('2.0.0')
                ->and($dependencies->has('unknown/package'))->toBeFalse()
                ->and($dependencies->version('unknown/package'))->toBeNull();
        });

        it('ignores malformed lock package entries', function (): void {
            $dependencies = Dependencies::fromData([], [
                'packages' => [
                    ['name' => 'missing/version'],
                    ['version' => '1.0.0'],
                    ['name' => 42, 'version' => '1.0.0'],
                    ['name' => 'valid/package', 'version' => '1.2.3'],
                ],
            ]);

            expect($dependencies->has('missing/version'))->toBeFalse()
                ->and($dependencies->has('valid/package'))->toBeTrue();
        });
    });

    describe('version constraints', function (): void {
        beforeEach(function (): void {
            $this->dependencies = Dependencies::fromData(
                ['require' => ['package/a' => '^8.0']],
                ['packages' => [['name' => 'package/a', 'version' => '8.2.5']]]
            );
        });

        it('delegates Composer constraint syntax to composer/semver', function (): void {
            expect($this->dependencies->satisfies('package/a', '^8.0'))->toBeTrue()
                ->and($this->dependencies->satisfies('package/a', '>=8.0 <9.0'))->toBeTrue()
                ->and($this->dependencies->satisfies('package/a', '^9.0'))->toBeFalse();
        });

        it('compares installed versions with each supported operator', function (string $operator, string $version, bool $expected): void {
            expect($this->dependencies->compare('package/a', $operator, $version))->toBe($expected);
        })->with([
            'greater than' => ['>', '8.0.0', true],
            'greater than or equal' => ['>=', '8.2.5', true],
            'less than' => ['<', '8.2.5', false],
            'less than or equal' => ['<=', '9.0.0', true],
            'single equals' => ['=', '8.2.5', true],
            'double equals' => ['==', '8.2.4', false],
            'not equals' => ['!=', '8.2.4', true],
        ]);

        it('rejects unsupported comparisons and packages without installed versions', function (): void {
            $unlocked = Dependencies::fromData(['require' => ['package/a' => '^8.0']]);

            expect($this->dependencies->compare('package/a', '<=>', '8.0.0'))->toBeFalse()
                ->and($this->dependencies->compare('unknown/package', '>=', '1.0.0'))->toBeFalse()
                ->and($unlocked->compare('package/a', '>=', '8.0.0'))->toBeFalse();
        });

        it('uses a root branch alias for constraint checks', function (): void {
            $dependencies = Dependencies::fromData(
                ['require' => ['package/a' => 'dev-instrumental-runtime as 4.0.x-dev']],
                ['packages' => [['name' => 'package/a', 'version' => 'dev-instrumental-runtime']]]
            );

            expect($dependencies->version('package/a'))->toBe('dev-instrumental-runtime')
                ->and($dependencies->satisfies('package/a', '^4.0'))->toBeTrue()
                ->and($dependencies->satisfies('package/a', '^5.0'))->toBeFalse();
        });

        it('uses aliases recorded in composer.lock', function (): void {
            $dependencies = Dependencies::fromData(
                ['require' => ['package/a' => 'dev-main']],
                [
                    'packages' => [['name' => 'package/a', 'version' => 'dev-main']],
                    'aliases' => [[
                        'package' => 'package/a',
                        'version' => 'dev-main',
                        'alias' => '3.2.x-dev',
                    ]],
                ]
            );

            expect($dependencies->satisfies('package/a', '^3.2'))->toBeTrue();
        });

        it('uses every alias recorded in Composer installed data', function (): void {
            $dependencies = Dependencies::fromInstalledData([[
                'versions' => [
                    'package/a' => [
                        'pretty_version' => 'dev-main',
                        'version' => 'dev-main',
                        'aliases' => ['1.0.x-dev', '2.4.x-dev'],
                        'dev_requirement' => false,
                    ],
                ],
            ]]);

            expect($dependencies->version('package/a'))->toBe('dev-main')
                ->and($dependencies->satisfies('package/a', '^1.0'))->toBeTrue()
                ->and($dependencies->satisfies('package/a', '^2.4'))->toBeTrue()
                ->and($dependencies->satisfies('package/a', '^3.0'))->toBeFalse();
        });

        it('honours provided and replaced ranges from Composer installed data', function (): void {
            $dependencies = Dependencies::fromInstalledData([[
                'versions' => [
                    'virtual/package' => [
                        'provided' => ['^2.0'],
                        'replaced' => ['3.0.*'],
                        'dev_requirement' => false,
                    ],
                ],
            ]]);

            expect($dependencies->has('virtual/package'))->toBeTrue()
                ->and($dependencies->version('virtual/package'))->toBeNull()
                ->and($dependencies->satisfies('virtual/package', '^2.5'))->toBeTrue()
                ->and($dependencies->satisfies('virtual/package', '^3.0'))->toBeTrue()
                ->and($dependencies->satisfies('virtual/package', '^4.0'))->toBeFalse();
        });
    });
});
