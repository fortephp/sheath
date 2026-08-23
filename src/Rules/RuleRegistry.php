<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules;

use Closure;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Contracts\SharesRuleState;
use Forte\Sheath\Exceptions\RuleNotFoundException;
use Forte\Sheath\Packages\PackageRequirementEvaluator;
use Forte\Sheath\Packages\PackageRequirementMode;
use Forte\Sheath\Packages\PackageRequirementResult;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;
use Symfony\Component\Finder\Finder;
use Throwable;
use UnitEnum;

class RuleRegistry
{
    private int $revision = 0;

    /**
     * @var array<class-string<Rule>, true>
     */
    private array $classRules = [];

    /**
     * @var array<string, class-string<Rule>>
     */
    private array $rules = [];

    /**
     * @var array<string, class-string<Rule>>
     */
    private array $knownRules = [];

    /**
     * @var array<string, Rule>
     */
    private array $instances = [];

    /**
     * @var array<string, class-string<Rule>>
     */
    private array $disabledRules = [];

    /**
     * @var array<string, class-string<Rule>>
     */
    private array $skippedRules = [];

    /**
     * @var array<class-string, PackageRequirementResult>
     */
    private array $requirementResults = [];

    /**
     * @var array<string, PackageRequirementResult>
     */
    private array $requirementResultsByRuleId = [];

    private PackageRequirementMode $packageMode = PackageRequirementMode::SKIP;

    private ?PackageRequirementEvaluator $evaluator = null;

    /**
     * @var (Closure(class-string<Rule>): Rule)|null
     */
    private ?Closure $resolver = null;

    public static function withBuiltInRules(): self
    {
        $registry = new self;
        $registry->registerBuiltInRules();

        return $registry;
    }

    public function registerBuiltInRules(): void
    {
        $this->discoverRules(__DIR__);
    }

    /**
     * @throws Throwable
     */
    public function setPackageRequirementMode(PackageRequirementMode $mode): void
    {
        if ($this->packageMode === $mode) {
            return;
        }

        $previous = $this->packageMode;
        $this->packageMode = $mode;

        try {
            $this->rebuildRuleMap();
            $this->revision++;
        } catch (Throwable $exception) {
            $this->packageMode = $previous;

            throw $exception;
        }
    }

    /**
     * @throws Throwable
     */
    public function setPackageRequirementEvaluator(PackageRequirementEvaluator $evaluator): void
    {
        $previous = $this->evaluator;
        $this->evaluator = $evaluator;

        try {
            $this->rebuildRuleMap();
            $this->revision++;
        } catch (Throwable $exception) {
            $this->evaluator = $previous;

            throw $exception;
        }
    }

    /**
     * @param  Closure(class-string<Rule>): Rule  $resolver
     *
     * @throws Throwable
     */
    public function setResolver(Closure $resolver): void
    {
        $previous = $this->resolver;
        $this->resolver = $resolver;

        try {
            $this->rebuildRuleMap();
            $this->revision++;
        } catch (Throwable $exception) {
            $this->resolver = $previous;

            throw $exception;
        }
    }

    /**
     * @param  class-string<Rule>  $ruleClass
     */
    private function resolve(string $ruleClass): Rule
    {
        if ($this->resolver !== null) {
            $resolved = ($this->resolver)($ruleClass);

            if (! $resolved instanceof $ruleClass) {
                $resolvedClass = $resolved::class;

                throw new InvalidArgumentException(
                    "Rule resolver must return an instance of [{$ruleClass}]; [{$resolvedClass}] returned."
                );
            }

            return $resolved;
        }

        return new $ruleClass;
    }

    /**
     * @param  class-string<Rule>|Rule  $rule
     *
     * Rule instances are treated as prototypes and cloned for each lint run.
     *
     * @throws InvalidArgumentException when a rule instance cannot be cloned
     */
    public function register(string|Rule $rule): void
    {
        if ($rule instanceof Rule) {
            $ruleId = $rule->getId();
            $this->assertRuleIdAvailable($ruleId, $rule::class);
            $this->syncRuleInstance($rule);
            $this->instances[$ruleId] = $rule;
            $this->revision++;

            return;
        }

        $ruleClass = $rule;
        if (! is_subclass_of($ruleClass, Rule::class)) {
            throw new InvalidArgumentException("Rule class [{$ruleClass}] must implement ".Rule::class.'.');
        }

        $this->syncRuleClass($ruleClass);
        $this->classRules[$ruleClass] = true;
        $this->revision++;
    }

    /** @internal Monotonic version used to invalidate prepared lint plans. */
    public function revision(): int
    {
        return $this->revision;
    }

    /**
     * @param  class-string<Rule>  $ruleClass
     */
    private function syncRuleClass(string $ruleClass): void
    {
        $this->syncRuleInstance($this->resolve($ruleClass));
    }

    private function syncRuleInstance(Rule $instance): void
    {
        $ruleClass = $instance::class;
        $ruleId = $instance->getId();
        $this->assertRuleIdAvailable($ruleId, $ruleClass);
        $this->assertRuleIsCloneable($instance);
        $result = null;

        if ($this->packageMode !== PackageRequirementMode::IGNORE && $this->evaluator !== null) {
            $result = $this->evaluator->evaluate($ruleClass);
        }

        $this->knownRules[$ruleId] = $ruleClass;

        unset($this->disabledRules[$ruleId], $this->skippedRules[$ruleId]);

        if ($result !== null) {
            $this->requirementResults[$ruleClass] = $result;
            $this->requirementResultsByRuleId[$ruleId] = $result;

            if (! $result->satisfied && ! $result->unknown) {
                if ($this->packageMode === PackageRequirementMode::SKIP) {
                    unset($this->rules[$ruleId]);
                    $this->skippedRules[$ruleId] = $ruleClass;

                    return;
                }

                $this->disabledRules[$ruleId] = $ruleClass;
            }
        } else {
            unset($this->requirementResults[$ruleClass]);
            unset($this->requirementResultsByRuleId[$ruleId]);
        }

        $this->rules[$ruleId] = $ruleClass;
    }

    private function assertRuleIsCloneable(Rule $rule): void
    {
        $ruleClass = $rule::class;
        $reflection = new ReflectionClass($rule);
        if (! $reflection->isCloneable()) {
            throw new InvalidArgumentException(
                "Rule [{$ruleClass}] must be cloneable because registered rules are used as execution prototypes."
            );
        }

        if ($rule instanceof SharesRuleState
            || $reflection->hasMethod('__clone')
            || ! $this->containsSharedObjectState($rule, $reflection)) {
            return;
        }

        throw new InvalidArgumentException(
            "Rule [{$ruleClass}] contains object or resource state and must implement __clone() "
            .'or '.SharesRuleState::class.' to define how that state is isolated between lint runs.'
        );
    }

    /** @param ReflectionClass<Rule> $reflection */
    private function containsSharedObjectState(Rule $rule, ReflectionClass $reflection): bool
    {
        $current = $reflection;

        while ($current !== false) {
            foreach ($current->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $current->getName()
                    || $property->isStatic()
                    || ! $property->isInitialized($rule)) {
                    continue;
                }

                if ($this->valueContainsSharedState($property->getValue($rule))) {
                    return true;
                }
            }

            $current = $current->getParentClass();
        }

        return false;
    }

    private function valueContainsSharedState(mixed $value, int $depth = 0): bool
    {
        if (is_resource($value)) {
            return true;
        }

        if (is_object($value)) {
            return ! $value instanceof UnitEnum;
        }

        if (! is_array($value)) {
            return false;
        }

        if ($depth >= 16) {
            return true;
        }

        foreach ($value as $item) {
            if ($this->valueContainsSharedState($item, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  class-string<Rule>  $ruleClass
     */
    private function assertRuleIdAvailable(string $ruleId, string $ruleClass): void
    {
        if (trim($ruleId) === '') {
            throw new InvalidArgumentException("Rule class [{$ruleClass}] returned an empty rule ID.");
        }

        $registered = $this->knownRules[$ruleId] ?? null;

        if ($registered !== null && $registered !== $ruleClass) {
            throw new InvalidArgumentException(
                "Rule ID [{$ruleId}] is already registered by [{$registered}]; [{$ruleClass}] cannot replace it."
            );
        }
    }

    private function rebuildRuleMap(): void
    {
        $previous = [
            'rules' => $this->rules,
            'knownRules' => $this->knownRules,
            'disabledRules' => $this->disabledRules,
            'skippedRules' => $this->skippedRules,
            'requirementResults' => $this->requirementResults,
            'requirementResultsByRuleId' => $this->requirementResultsByRuleId,
        ];

        $this->rules = [];
        $this->knownRules = [];
        $this->disabledRules = [];
        $this->skippedRules = [];
        $this->requirementResults = [];
        $this->requirementResultsByRuleId = [];

        try {
            foreach ($this->instances as $instance) {
                $this->syncRuleInstance($instance);
            }

            foreach (array_keys($this->classRules) as $ruleClass) {
                $this->syncRuleClass($ruleClass);
            }
        } catch (Throwable $exception) {
            $this->rules = $previous['rules'];
            $this->knownRules = $previous['knownRules'];
            $this->disabledRules = $previous['disabledRules'];
            $this->skippedRules = $previous['skippedRules'];
            $this->requirementResults = $previous['requirementResults'];
            $this->requirementResultsByRuleId = $previous['requirementResultsByRuleId'];

            throw $exception;
        }
    }

    /**
     * @param  array<class-string<Rule>>  $ruleClasses
     */
    public function registerMany(array $ruleClasses): void
    {
        foreach ($ruleClasses as $ruleClass) {
            $this->register($ruleClass);
        }
    }

    /**
     * @throws RuleNotFoundException
     */
    public function get(string $ruleId): Rule
    {
        if (! isset($this->rules[$ruleId])) {
            throw RuleNotFoundException::forId($ruleId);
        }

        return $this->instances[$ruleId] ?? $this->resolve($this->rules[$ruleId]);
    }

    public function find(string $ruleId): ?Rule
    {
        if (! isset($this->rules[$ruleId])) {
            return null;
        }

        return $this->instances[$ruleId] ?? $this->resolve($this->rules[$ruleId]);
    }

    public function has(string $ruleId): bool
    {
        return isset($this->rules[$ruleId]);
    }

    public function hasKnown(string $ruleId): bool
    {
        return isset($this->knownRules[$ruleId]);
    }

    /**
     * @return array<string>
     */
    public function all(): array
    {
        return array_keys($this->rules);
    }

    /**
     * @return array<Rule>
     */
    public function allInstances(): array
    {
        return array_map(
            $this->get(...),
            array_keys($this->rules)
        );
    }

    /**
     * @return array<string, class-string<Rule>>
     */
    public function getAllKnownRules(): array
    {
        return $this->knownRules;
    }

    public function isDisabledDueToPackages(string $ruleId): bool
    {
        return isset($this->disabledRules[$ruleId]);
    }

    public function isSkippedDueToPackages(string $ruleId): bool
    {
        return isset($this->skippedRules[$ruleId]);
    }

    /**
     * @return array<string, class-string<Rule>>
     */
    public function getSkippedDueToPackages(): array
    {
        return $this->skippedRules;
    }

    /**
     * @return array<string, class-string<Rule>>
     */
    public function getDisabledDueToPackages(): array
    {
        return $this->disabledRules;
    }

    /**
     * @param  class-string  $ruleClass
     */
    public function getPackageRequirementResult(string $ruleClass): ?PackageRequirementResult
    {
        return $this->requirementResults[$ruleClass] ?? null;
    }

    public function getPackageRequirementResultForRuleId(string $ruleId): ?PackageRequirementResult
    {
        return $this->requirementResultsByRuleId[$ruleId] ?? null;
    }

    public function getEvaluator(): ?PackageRequirementEvaluator
    {
        return $this->evaluator;
    }

    public function discoverRules(string $rulesPath, string $namespace = 'Forte\\Sheath\\Rules'): void
    {
        if (! is_dir($rulesPath)) {
            return;
        }

        $finder = new Finder;
        $finder->files()->in($rulesPath)->name('*Rule.php');

        $realPath = realpath($rulesPath);
        if ($realPath === false) {
            return;
        }
        $rulesPath = str_replace('\\', '/', $realPath);

        foreach ($finder as $file) {
            $filePath = str_replace('\\', '/', $file->getRealPath());

            $relativePath = str_replace($rulesPath.'/', '', $filePath);

            $relativePath = str_replace('/', '\\', $relativePath);
            $className = $namespace.'\\'.str_replace('.php', '', $relativePath);

            if (! class_exists($className)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($className);

                if ($reflection->isAbstract() || ! $reflection->implementsInterface(Rule::class)) {
                    continue;
                }

                /** @var class-string<Rule> $className */
                $this->register($className);
            } catch (ReflectionException) {
                continue;
            }
        }
    }
}
