<?php

declare(strict_types=1);

use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Rules\RuleRegistry;

/** @return array<string, array{Rule}> */
function everyRuleForIdChecks(): array
{
    $registry = new RuleRegistry;
    $registry->discoverRules(__DIR__.'/../../src/Rules');
    $cases = [];

    foreach ($registry->allInstances() as $rule) {
        $cases[$rule->getId()] = [$rule];
    }

    return $cases;
}

it('keeps every built-in rule ID in its documented shape', function (Rule $rule): void {
    expect($rule->getId())->toMatch('/^[a-z][a-z0-9]*(-[a-z0-9]+)+$/');
})->with(everyRuleForIdChecks());
