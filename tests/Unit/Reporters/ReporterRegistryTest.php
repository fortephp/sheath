<?php

declare(strict_types=1);

use Forte\Sheath\Exceptions\ReporterNotFoundException;
use Forte\Sheath\Reporters\JsonReporter;
use Forte\Sheath\Reporters\ReporterRegistry;
use Forte\Sheath\Reporters\StylishReporter;

describe('ReporterRegistry', function (): void {
    it('exposes the built-in reporters by name', function (): void {
        $registry = new ReporterRegistry;

        expect($registry->get('json'))->toBeInstanceOf(JsonReporter::class)
            ->and($registry->getAvailableReporters())->toEqualCanonicalizing([
                'agent',
                'stylish',
                'json',
                'compact',
                'unix',
                'checkstyle',
                'github',
            ])->and($registry->has('json'))->toBeTrue()
            ->and($registry->has('invalid'))->toBeFalse();
    });

    it('throws exception for unknown reporter', function (): void {
        $registry = new ReporterRegistry;

        $registry->get('invalid');
    })->throws(ReporterNotFoundException::class);

    it('registers reporter classes and instances', function (): void {
        $registry = new ReporterRegistry;
        $instance = new StylishReporter;

        $registry->register('class', StylishReporter::class);
        $registry->register('instance', $instance);

        expect($registry->get('class'))->toBeInstanceOf(StylishReporter::class)
            ->and($registry->get('instance'))->toBe($instance)
            ->and($registry->getAvailableReporters())->toContain('class', 'instance');
    });

    it('rejects a class that does not implement the Reporter contract', function (): void {
        $registry = new ReporterRegistry;

        expect(fn () => $registry->register('bad', stdClass::class))
            ->toThrow(InvalidArgumentException::class);
    });

    it('resolves reporters through a configured resolver', function (): void {
        $registry = new ReporterRegistry;
        $resolved = [];

        $registry->setResolver(function (string $class) use (&$resolved) {
            $resolved[] = $class;

            return new $class;
        });

        $reporter = $registry->get('json');

        expect($reporter)->toBeInstanceOf(JsonReporter::class)
            ->and($resolved)->toBe([JsonReporter::class]);
    });

    it('lets a class registration replace an instance registration', function (): void {
        $registry = new ReporterRegistry;
        $instance = new StylishReporter;

        $registry->register('custom', $instance);
        $registry->register('custom', JsonReporter::class);

        expect($registry->get('custom'))->toBeInstanceOf(JsonReporter::class)
            ->and($registry->get('custom'))->not->toBe($instance);
    });
});
