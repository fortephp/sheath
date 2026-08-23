<?php

declare(strict_types=1);

use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Packages\Dependencies;
use Forte\Sheath\Packages\PackageRequirementEvaluator;

#[RequiresPackage('livewire/livewire')]
class TestRuleWithPackage {}

#[RequiresPackage('livewire/livewire', '^3.0')]
class TestRuleWithPackageAndConstraint {}

#[RequiresPackage('livewire/livewire', '^3.0')]
#[RequiresPackage('laravel/framework', '^11.0')]
class TestRuleWithMultiplePackages {}

class TestRuleWithoutPackage {}

describe('PackageRequirementEvaluator', function (): void {
    describe('getRequirements', function (): void {
        it('returns empty array for class without attributes', function (): void {
            $evaluator = new PackageRequirementEvaluator;

            $requirements = $evaluator->getRequirements(TestRuleWithoutPackage::class);

            expect($requirements)->toBe([]);
        });

        it('returns single requirement for class with one attribute', function (): void {
            $evaluator = new PackageRequirementEvaluator;

            $requirements = $evaluator->getRequirements(TestRuleWithPackage::class);

            expect($requirements)->toHaveCount(1)
                ->and($requirements[0])->toBeInstanceOf(RequiresPackage::class)
                ->and($requirements[0]->package)->toBe('livewire/livewire')
                ->and($requirements[0]->constraint)->toBeNull();
        });

        it('returns requirement with constraint', function (): void {
            $evaluator = new PackageRequirementEvaluator;

            $requirements = $evaluator->getRequirements(TestRuleWithPackageAndConstraint::class);

            expect($requirements)->toHaveCount(1)
                ->and($requirements[0]->package)->toBe('livewire/livewire')
                ->and($requirements[0]->constraint)->toBe('^3.0');
        });

        it('returns multiple requirements for class with multiple attributes', function (): void {
            $evaluator = new PackageRequirementEvaluator;

            $requirements = $evaluator->getRequirements(TestRuleWithMultiplePackages::class);

            expect($requirements)->toHaveCount(2)
                ->and($requirements[0]->package)->toBe('livewire/livewire')
                ->and($requirements[1]->package)->toBe('laravel/framework');
        });
    });

    describe('evaluate', function (): void {
        it('returns satisfied for class without requirements', function (): void {
            $evaluator = new PackageRequirementEvaluator;

            $result = $evaluator->evaluate(TestRuleWithoutPackage::class);

            expect($result->satisfied)->toBeTrue();
        });

        it('returns unknown when no Dependencies is set', function (): void {
            $evaluator = new PackageRequirementEvaluator;

            $result = $evaluator->evaluate(TestRuleWithPackage::class);

            expect($result->satisfied)->toBeFalse()
                ->and($result->unknown)->toBeTrue();
        });

        it('returns satisfied when package is installed without constraint', function (): void {
            $checker = Dependencies::fromData(
                ['require' => ['livewire/livewire' => '^3.0']],
                ['packages' => [['name' => 'livewire/livewire', 'version' => 'v3.5.0']]]
            );

            $evaluator = new PackageRequirementEvaluator($checker);

            $result = $evaluator->evaluate(TestRuleWithPackage::class);

            expect($result->satisfied)->toBeTrue();
        });

        it('returns unsatisfied when package is not installed', function (): void {
            $checker = Dependencies::fromData(
                ['require' => ['laravel/framework' => '^11.0']],
                ['packages' => [['name' => 'laravel/framework', 'version' => 'v11.0.0']]]
            );

            $evaluator = new PackageRequirementEvaluator($checker);

            $result = $evaluator->evaluate(TestRuleWithPackage::class);

            expect($result->satisfied)->toBeFalse()
                ->and($result->unknown)->toBeFalse()
                ->and($result->unmetRequirements)->toHaveCount(1)
                ->and($result->unmetRequirements[0])->toContain('livewire/livewire')
                ->and($result->unmetRequirements[0])->toContain('not installed');
        });

        it('returns satisfied when package meets constraint', function (): void {
            $checker = Dependencies::fromData(
                ['require' => ['livewire/livewire' => '^3.0']],
                ['packages' => [['name' => 'livewire/livewire', 'version' => 'v3.5.0']]]
            );

            $evaluator = new PackageRequirementEvaluator($checker);

            $result = $evaluator->evaluate(TestRuleWithPackageAndConstraint::class);

            expect($result->satisfied)->toBeTrue();
        });

        it('returns unsatisfied when package does not meet constraint', function (): void {
            $checker = Dependencies::fromData(
                ['require' => ['livewire/livewire' => '^2.0']],
                ['packages' => [['name' => 'livewire/livewire', 'version' => 'v2.12.0']]]
            );

            $evaluator = new PackageRequirementEvaluator($checker);

            $result = $evaluator->evaluate(TestRuleWithPackageAndConstraint::class);

            expect($result->satisfied)->toBeFalse()
                ->and($result->unmetRequirements)->toHaveCount(1)
                ->and($result->unmetRequirements[0])->toContain('livewire/livewire')
                ->and($result->unmetRequirements[0])->toContain('^3.0')
                ->and($result->unmetRequirements[0])->toContain('installed: 2.12.0');
        });

        it('returns satisfied when all multiple requirements are met', function (): void {
            $checker = Dependencies::fromData(
                ['require' => [
                    'livewire/livewire' => '^3.0',
                    'laravel/framework' => '^11.0',
                ]],
                ['packages' => [
                    ['name' => 'livewire/livewire', 'version' => 'v3.5.0'],
                    ['name' => 'laravel/framework', 'version' => 'v11.0.0'],
                ]]
            );

            $evaluator = new PackageRequirementEvaluator($checker);

            $result = $evaluator->evaluate(TestRuleWithMultiplePackages::class);

            expect($result->satisfied)->toBeTrue();
        });

        it('returns unsatisfied when one of multiple requirements is not met', function (): void {
            $checker = Dependencies::fromData(
                ['require' => ['livewire/livewire' => '^3.0']],
                ['packages' => [['name' => 'livewire/livewire', 'version' => 'v3.5.0']]]
            );

            $evaluator = new PackageRequirementEvaluator($checker);

            $result = $evaluator->evaluate(TestRuleWithMultiplePackages::class);

            expect($result->satisfied)->toBeFalse()
                ->and($result->unmetRequirements)->toHaveCount(1)
                ->and($result->unmetRequirements[0])->toContain('laravel/framework');
        });
    });

    describe('setDependencies', function (): void {
        it('can set composer checker after construction', function (): void {
            $evaluator = new PackageRequirementEvaluator;

            $result = $evaluator->evaluate(TestRuleWithPackage::class);
            expect($result->unknown)->toBeTrue();

            $checker = Dependencies::fromData(
                ['require' => ['livewire/livewire' => '^3.0']],
                ['packages' => [['name' => 'livewire/livewire', 'version' => 'v3.5.0']]]
            );
            $evaluator->setDependencies($checker);

            $result = $evaluator->evaluate(TestRuleWithPackage::class);
            expect($result->satisfied)->toBeTrue();
        });
    });

});
