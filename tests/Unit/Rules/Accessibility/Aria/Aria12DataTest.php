<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Aria\Aria12Data;
use Forte\Sheath\Rules\Accessibility\Aria\AriaRoleResolver;
use Forte\Sheath\Rules\Accessibility\Aria\NoInvalidRoleRule;

it('contains a self-consistent exhaustive ARIA 1.2 core data set', function (): void {
    $properties = Aria12Data::properties();
    $roles = Aria12Data::roles();

    expect(Aria12Data::VERSION)->toBe('1.2')
        ->and($properties)->toHaveCount(48)
        ->and($roles)->toHaveCount(82);

    foreach ($properties as $name => $definition) {
        expect($name)->toStartWith('aria-')
            ->and($definition['type'])->toBeIn([
                'boolean', 'tristate', 'token', 'tokenlist',
                'integer', 'number', 'id', 'idlist', 'string',
            ]);
    }

    foreach ($roles as $name => $definition) {
        expect(NoInvalidRoleRule::isValidRole($name))->toBeTrue();

        foreach (['supported', 'required', 'prohibited'] as $kind) {
            foreach ($definition[$kind] as $property) {
                expect($properties)->toHaveKey($property);
            }
        }

        foreach ($definition['required'] as $property) {
            expect($definition['supported'])->toContain($property);
        }

        foreach (Aria12Data::GLOBAL_PROPERTIES as $property) {
            if (! in_array($property, $definition['prohibited'], true)) {
                expect($definition['supported'])->toContain($property);
            }
        }
    }

    foreach (AriaRoleResolver::implicitRoleMap() as $role) {
        expect($roles)->toHaveKey($role);
    }

    foreach (Aria12Data::CONDITIONAL_REQUIRED_PROPERTIES as $role => $conditions) {
        expect($roles)->toHaveKey($role);
        foreach ($conditions as $required) {
            foreach ($required as $property) {
                expect($properties)->toHaveKey($property)
                    ->and($roles[$role]['supported'])->toContain($property);
            }
        }
    }

    expect($roles['none'])->toBe($roles['presentation']);
});

it('encodes property-specific integer domains', function (): void {
    expect(Aria12Data::property('aria-colcount'))->toMatchArray(['min' => 1, 'allowMinusOne' => true])
        ->and(Aria12Data::property('aria-rowcount'))->toMatchArray(['min' => 1, 'allowMinusOne' => true])
        ->and(Aria12Data::property('aria-setsize'))->toMatchArray(['min' => 1, 'allowMinusOne' => true])
        ->and(Aria12Data::property('aria-rowspan'))->toMatchArray(['min' => 0])
        ->and(Aria12Data::property('aria-level'))->toMatchArray(['min' => 1]);
});
