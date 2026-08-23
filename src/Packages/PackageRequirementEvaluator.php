<?php

declare(strict_types=1);

namespace Forte\Sheath\Packages;

use Forte\Sheath\Attributes\RequiresPackage;
use ReflectionClass;
use ReflectionException;

class PackageRequirementEvaluator
{
    public function __construct(
        private ?Dependencies $checker = null,
    ) {}

    public function setDependencies(Dependencies $checker): void
    {
        $this->checker = $checker;
    }

    /**
     * @param  class-string  $className
     * @return array<RequiresPackage>
     *
     * @throws ReflectionException
     */
    public function getRequirements(string $className): array
    {
        $reflection = new ReflectionClass($className);
        $attributes = $reflection->getAttributes(RequiresPackage::class);

        return array_map(
            fn ($attr) => $attr->newInstance(),
            $attributes
        );
    }

    /**
     * @param  class-string  $className
     *
     * @throws ReflectionException
     */
    public function evaluate(string $className): PackageRequirementResult
    {
        $requirements = $this->getRequirements($className);

        if (empty($requirements)) {
            return PackageRequirementResult::satisfied();
        }

        if ($this->checker === null) {
            return PackageRequirementResult::unknown('Dependencies not available');
        }

        $unmet = [];

        foreach ($requirements as $req) {
            if (! $this->checker->has($req->package)) {
                $unmet[] = $this->formatUnmet($req, 'not installed');

                continue;
            }

            if ($req->constraint !== null) {
                if (! $this->checker->satisfies($req->package, $req->constraint)) {
                    $installed = $this->checker->version($req->package) ?? 'unknown';
                    $unmet[] = $this->formatUnmet($req, "installed: {$installed}");
                }
            }
        }

        if (empty($unmet)) {
            return PackageRequirementResult::satisfied();
        }

        return PackageRequirementResult::unsatisfied($unmet);
    }

    private function formatUnmet(RequiresPackage $req, string $reason): string
    {
        $constraint = $req->constraint !== null ? " {$req->constraint}" : '';

        return "{$req->package}{$constraint} ({$reason})";
    }
}
