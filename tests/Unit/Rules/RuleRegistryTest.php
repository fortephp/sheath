<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Contracts\SharesRuleState;
use Forte\Sheath\Exceptions\RuleNotFoundException;
use Forte\Sheath\Packages\Dependencies;
use Forte\Sheath\Packages\PackageRequirementEvaluator;
use Forte\Sheath\Packages\PackageRequirementMode;
use Forte\Sheath\Packages\PackageRequirementResult;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Accessibility\Content\ImgAltTextRule;
use Forte\Sheath\Rules\BestPractices\Attributes\NoInlineStylesRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Rules\RuleRegistry;

#[RequiresPackage('livewire/livewire')]
class TestLivewireRule extends AbstractRule
{
    public function getId(): string
    {
        return 'test-livewire-rule';
    }

    public function getDescription(): string
    {
        return 'Test rule requiring Livewire';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void {}
}

#[RequiresPackage('livewire/livewire', '^3.0')]
class TestLivewireV3Rule extends AbstractRule
{
    public function getId(): string
    {
        return 'test-livewire-v3-rule';
    }

    public function getDescription(): string
    {
        return 'Test rule requiring Livewire 3';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void {}
}

class TestRuleWithoutPackageRequirement extends AbstractRule
{
    public function getId(): string
    {
        return 'test-no-package-rule';
    }

    public function getDescription(): string
    {
        return 'Test rule without package requirement';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void {}
}

class TestDuplicateAltTextRule extends TestRuleWithoutPackageRequirement
{
    public function getId(): string
    {
        return 'a11y-alt-text';
    }
}

class TestRuleWithDependency extends AbstractRule implements SharesRuleState
{
    public function __construct(
        public readonly ?object $dependency = null,
    ) {
        parent::__construct();
    }

    public function getId(): string
    {
        return 'test-rule-with-dependency';
    }

    public function getDescription(): string
    {
        return 'Test rule with constructor dependency';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void {}
}

class TestRuleWithImplicitMutableState extends TestRuleWithoutPackageRequirement
{
    public object $state;

    public function __construct()
    {
        $this->state = (object) ['checks' => 0];
        parent::__construct();
    }

    public function getId(): string
    {
        return 'test-rule-with-implicit-mutable-state';
    }
}

abstract class TestRuleWithPrivateInheritedStateBase extends TestRuleWithoutPackageRequirement
{
    private readonly object $state;

    public function __construct()
    {
        $this->state = (object) ['checks' => 0];
        parent::__construct();
    }

    public function inheritedState(): object
    {
        return $this->state;
    }
}

class TestRuleWithPrivateInheritedState extends TestRuleWithPrivateInheritedStateBase
{
    public function getId(): string
    {
        return 'test-rule-with-private-inherited-state';
    }
}

class TestResolverIdRule extends AbstractRule
{
    public function __construct(
        private readonly string $id = 'test-resolver-default',
    ) {
        parent::__construct();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDescription(): string
    {
        return 'Test resolver-dependent rule ID';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void {}
}

class TestNonCloneableRule extends TestRuleWithoutPackageRequirement
{
    private function __clone() {}

    public function getId(): string
    {
        return 'test-non-cloneable-rule';
    }
}

describe('Rule Registry', function (): void {
    beforeEach(function (): void {
        $this->registry = new RuleRegistry;
    });

    it('creates a registry populated with built-in rules by stable IDs', function (): void {
        $registry = RuleRegistry::withBuiltInRules();

        expect($registry->all())
            ->toContain('a11y-alt-text')
            ->toContain('blade-unclosed-directives')
            ->toContain('security-no-raw-echo');
    });

    it('can add built-in rules after registry collaborators are configured', function (): void {
        $resolved = [];
        $this->registry->setResolver(function (string $ruleClass) use (&$resolved) {
            $resolved[] = $ruleClass;

            return new $ruleClass;
        });

        $this->registry->registerBuiltInRules();

        expect($resolved)->not->toBeEmpty()
            ->and($this->registry->has('best-practices-button-type'))->toBeTrue();
    });

    it('rejects a class that does not implement Rule', function (): void {
        $this->registry->register(stdClass::class);
    })->throws(InvalidArgumentException::class);

    it('rejects two different rule classes with the same ID', function (): void {
        $this->registry->register(ImgAltTextRule::class);
        $this->registry->register(TestDuplicateAltTextRule::class);
    })->throws(InvalidArgumentException::class);

    it('does not retain a rejected class registration', function (): void {
        $this->registry->register(ImgAltTextRule::class);

        try {
            $this->registry->register(TestDuplicateAltTextRule::class);
        } catch (InvalidArgumentException) {
            // The failed registration must be atomic.
        }

        $this->registry->setPackageRequirementMode(PackageRequirementMode::DISABLE);
        $this->registry->setPackageRequirementMode(PackageRequirementMode::IGNORE);

        expect($this->registry->get('a11y-alt-text'))->toBeInstanceOf(ImgAltTextRule::class)
            ->and($this->registry->all())->toHaveCount(1);
    });

    it('rolls back evaluator changes when rebuilding registered rules fails', function (): void {
        $this->registry->register(ImgAltTextRule::class);
        $failing = new class extends PackageRequirementEvaluator
        {
            public function evaluate(string $className): PackageRequirementResult
            {
                throw new RuntimeException("Cannot evaluate {$className}");
            }
        };

        expect(fn () => $this->registry->setPackageRequirementEvaluator($failing))
            ->toThrow(RuntimeException::class)
            ->and($this->registry->getEvaluator())->toBeNull()
            ->and($this->registry->get('a11y-alt-text'))->toBeInstanceOf(ImgAltTextRule::class);

        $this->registry->setPackageRequirementMode(PackageRequirementMode::DISABLE);

        expect($this->registry->has('a11y-alt-text'))->toBeTrue();
    });

    it('allows the same rule class to be registered more than once', function (): void {
        $this->registry->register(ImgAltTextRule::class);
        $this->registry->register(ImgAltTextRule::class);

        expect($this->registry->get('a11y-alt-text'))->toBeInstanceOf(ImgAltTextRule::class);
    });

    it('registers and resolves multiple rule classes', function (): void {
        $this->registry->registerMany([
            ImgAltTextRule::class,
            NoInlineStylesRule::class,
        ]);

        expect($this->registry->all())->toBe([
            'a11y-alt-text',
            'best-practices-no-inline-styles',
        ])->and($this->registry->get('a11y-alt-text'))->toBeInstanceOf(ImgAltTextRule::class)
            ->and($this->registry->find('best-practices-no-inline-styles'))->toBeInstanceOf(NoInlineStylesRule::class)
            ->and($this->registry->allInstances())->toHaveCount(2);
    });

    it('throws exception for unknown rule', function (): void {
        $this->registry->get('unknown-rule');
    })->throws(RuleNotFoundException::class);

    it('find returns null for unknown rule', function (): void {
        expect($this->registry->find('unknown-rule'))->toBeNull();
    });

    describe('package requirement handling', function (): void {
        it('registers rules with package requirements when no evaluator is set', function (): void {
            $this->registry->register(TestLivewireRule::class);

            expect($this->registry->has('test-livewire-rule'))->toBeTrue();
        });

        describe('SKIP mode', function (): void {
            beforeEach(function (): void {
                $this->registry->setPackageRequirementMode(PackageRequirementMode::SKIP);
            });

            it('tracks skipped rules as known rules', function (): void {
                $checker = Dependencies::fromData(
                    ['require' => ['laravel/framework' => '^11.0']],
                    ['packages' => [['name' => 'laravel/framework', 'version' => 'v11.0.0']]]
                );
                $evaluator = new PackageRequirementEvaluator($checker);
                $this->registry->setPackageRequirementEvaluator($evaluator);

                $this->registry->register(TestLivewireRule::class);

                expect($this->registry->has('test-livewire-rule'))->toBeFalse()
                    ->and($this->registry->hasKnown('test-livewire-rule'))->toBeTrue()
                    ->and($this->registry->isSkippedDueToPackages('test-livewire-rule'))->toBeTrue()
                    ->and($this->registry->getSkippedDueToPackages())->toHaveKey('test-livewire-rule')
                    ->and($this->registry->getPackageRequirementResultForRuleId('test-livewire-rule'))->not->toBeNull();
            });

            it('registers rules when package is installed', function (): void {
                $checker = Dependencies::fromData(
                    ['require' => ['livewire/livewire' => '^3.0']],
                    ['packages' => [['name' => 'livewire/livewire', 'version' => 'v3.5.0']]]
                );
                $evaluator = new PackageRequirementEvaluator($checker);
                $this->registry->setPackageRequirementEvaluator($evaluator);

                $this->registry->register(TestLivewireRule::class);

                expect($this->registry->has('test-livewire-rule'))->toBeTrue();
            });

        });

        describe('DISABLE mode', function (): void {
            beforeEach(function (): void {
                $this->registry->setPackageRequirementMode(PackageRequirementMode::DISABLE);
            });

            it('tracks disabled rules when package is not installed', function (): void {
                $checker = Dependencies::fromData(
                    ['require' => ['laravel/framework' => '^11.0']],
                    ['packages' => [['name' => 'laravel/framework', 'version' => 'v11.0.0']]]
                );
                $evaluator = new PackageRequirementEvaluator($checker);
                $this->registry->setPackageRequirementEvaluator($evaluator);

                $this->registry->register(TestLivewireRule::class);

                expect($this->registry->has('test-livewire-rule'))->toBeTrue()
                    ->and($this->registry->isDisabledDueToPackages('test-livewire-rule'))->toBeTrue();
            });

        });

        describe('IGNORE mode', function (): void {
            beforeEach(function (): void {
                $this->registry->setPackageRequirementMode(PackageRequirementMode::IGNORE);
            });

            it('registers all rules regardless of package requirements', function (): void {
                $checker = Dependencies::fromData(
                    ['require' => ['laravel/framework' => '^11.0']],
                    ['packages' => [['name' => 'laravel/framework', 'version' => 'v11.0.0']]]
                );
                $evaluator = new PackageRequirementEvaluator($checker);
                $this->registry->setPackageRequirementEvaluator($evaluator);

                $this->registry->register(TestLivewireRule::class);
                $this->registry->register(TestLivewireV3Rule::class);

                expect($this->registry->has('test-livewire-rule'))->toBeTrue()
                    ->and($this->registry->has('test-livewire-v3-rule'))->toBeTrue();
            });
        });

        it('can get package requirement result for a rule', function (): void {
            $checker = Dependencies::fromData(
                ['require' => ['livewire/livewire' => '^3.0']],
                ['packages' => [['name' => 'livewire/livewire', 'version' => 'v3.5.0']]]
            );
            $evaluator = new PackageRequirementEvaluator($checker);
            $this->registry->setPackageRequirementEvaluator($evaluator);

            $this->registry->register(TestLivewireRule::class);

            $result = $this->registry->getPackageRequirementResult(TestLivewireRule::class);

            expect($result)->not->toBeNull()
                ->and($result->satisfied)->toBeTrue();
        });

        it('returns null for rule without stored result', function (): void {
            $result = $this->registry->getPackageRequirementResult('NonExistentClass');

            expect($result)->toBeNull();
        });

        it('rebuilds registration when package mode changes', function (): void {
            $checker = Dependencies::fromData(
                ['require' => ['laravel/framework' => '^11.0']],
                ['packages' => [['name' => 'laravel/framework', 'version' => 'v11.0.0']]]
            );
            $evaluator = new PackageRequirementEvaluator($checker);
            $this->registry->setPackageRequirementEvaluator($evaluator);
            $this->registry->setPackageRequirementMode(PackageRequirementMode::SKIP);

            $this->registry->register(TestLivewireRule::class);

            expect($this->registry->has('test-livewire-rule'))->toBeFalse();

            $this->registry->setPackageRequirementMode(PackageRequirementMode::DISABLE);

            expect($this->registry->has('test-livewire-rule'))->toBeTrue()
                ->and($this->registry->isDisabledDueToPackages('test-livewire-rule'))->toBeTrue();

            $this->registry->setPackageRequirementMode(PackageRequirementMode::IGNORE);

            expect($this->registry->has('test-livewire-rule'))->toBeTrue()
                ->and($this->registry->isDisabledDueToPackages('test-livewire-rule'))->toBeFalse();
        });
    });

    describe('instance registration', function (): void {
        it('rejects a non-cloneable rule instance during registration', function (): void {
            expect(fn () => $this->registry->register(new TestNonCloneableRule))
                ->toThrow(InvalidArgumentException::class);
        });

        it('rejects a non-cloneable rule class during registration', function (): void {
            expect(fn () => $this->registry->register(TestNonCloneableRule::class))
                ->toThrow(InvalidArgumentException::class);
        });

        it('rejects implicit object state without an explicit clone contract', function (): void {
            expect(fn () => $this->registry->register(new TestRuleWithImplicitMutableState))
                ->toThrow(InvalidArgumentException::class);
        });

        it('rejects inherited private object state without an explicit clone contract', function (Rule|string $rule): void {
            expect(fn () => $this->registry->register($rule))
                ->toThrow(InvalidArgumentException::class);
        })->with([
            'instance' => fn (): Rule => new TestRuleWithPrivateInheritedState,
            'class' => TestRuleWithPrivateInheritedState::class,
        ]);

        it('preserves a registered rule instance across every lookup', function (): void {
            $dependency = new stdClass;
            $dependency->value = 'injected';

            $rule = new TestRuleWithDependency($dependency);
            $this->registry->register($rule);

            expect($this->registry->get('test-rule-with-dependency'))->toBe($rule)
                ->and($this->registry->get('test-rule-with-dependency')->dependency)->toBe($dependency)
                ->and($this->registry->find('test-rule-with-dependency'))->toBe($rule)
                ->and($this->registry->allInstances())->toHaveCount(1)
                ->and($this->registry->allInstances()[0])->toBe($rule);
        });

        it('applies package requirements to registered instances and preserves them across mode changes', function (): void {
            $checker = Dependencies::fromData(
                ['require' => ['laravel/framework' => '^11.0']],
                ['packages' => [['name' => 'laravel/framework', 'version' => 'v11.0.0']]]
            );
            $this->registry->setPackageRequirementEvaluator(new PackageRequirementEvaluator($checker));
            $this->registry->setPackageRequirementMode(PackageRequirementMode::SKIP);

            $rule = new TestLivewireRule;
            $this->registry->register($rule);

            expect($this->registry->has('test-livewire-rule'))->toBeFalse()
                ->and($this->registry->hasKnown('test-livewire-rule'))->toBeTrue()
                ->and($this->registry->isSkippedDueToPackages('test-livewire-rule'))->toBeTrue();

            $this->registry->setPackageRequirementMode(PackageRequirementMode::DISABLE);

            expect($this->registry->get('test-livewire-rule'))->toBe($rule)
                ->and($this->registry->isDisabledDueToPackages('test-livewire-rule'))->toBeTrue();

            $this->registry->setPackageRequirementMode(PackageRequirementMode::IGNORE);

            expect($this->registry->get('test-livewire-rule'))->toBe($rule)
                ->and($this->registry->isDisabledDueToPackages('test-livewire-rule'))->toBeFalse();
        });
    });

    describe('custom resolver', function (): void {
        it('uses custom resolver when set', function (): void {
            $dependency = new stdClass;
            $dependency->value = 'injected';

            $this->registry->setResolver(function (string $ruleClass) use ($dependency) {
                if ($ruleClass === TestRuleWithDependency::class) {
                    return new TestRuleWithDependency($dependency);
                }

                return new $ruleClass;
            });

            $this->registry->register(TestRuleWithDependency::class);
            $rule = $this->registry->get('test-rule-with-dependency');

            expect($rule)->toBeInstanceOf(TestRuleWithDependency::class)
                ->and($rule->dependency)->toBe($dependency)
                ->and($rule->dependency->value)->toBe('injected');
        });

        it('rejects a resolver result that is not an instance of the requested rule class', function (): void {
            $this->registry->setResolver(
                fn (string $ruleClass): Rule => new TestRuleWithoutPackageRequirement
            );

            expect(fn () => $this->registry->register(TestResolverIdRule::class))
                ->toThrow(InvalidArgumentException::class);
        });

        it('rebuilds class registrations when the resolver changes', function (): void {
            $this->registry->register(TestResolverIdRule::class);

            expect($this->registry->has('test-resolver-default'))->toBeTrue();

            $this->registry->setResolver(function (string $ruleClass): Rule {
                if ($ruleClass === TestResolverIdRule::class) {
                    return new TestResolverIdRule('test-resolver-injected');
                }

                return new $ruleClass;
            });

            expect($this->registry->has('test-resolver-default'))->toBeFalse()
                ->and($this->registry->hasKnown('test-resolver-default'))->toBeFalse()
                ->and($this->registry->has('test-resolver-injected'))->toBeTrue()
                ->and($this->registry->get('test-resolver-injected')->getId())->toBe('test-resolver-injected');
        });

        it('rolls back a resolver that cannot rebuild registered classes', function (): void {
            $this->registry->register(ImgAltTextRule::class);

            expect(fn () => $this->registry->setResolver(
                fn (string $ruleClass): Rule => throw new RuntimeException("Cannot resolve {$ruleClass}")
            ))->toThrow(RuntimeException::class)
                ->and($this->registry->all())->toBe(['a11y-alt-text'])
                ->and($this->registry->get('a11y-alt-text'))->toBeInstanceOf(ImgAltTextRule::class);
        });
    });
});
