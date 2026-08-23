<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Accessibility\Headings\NoSkipHeadingLevelsRule;
use Forte\Sheath\Rules\Accessibility\Structure\TableHeadersRule;
use Forte\Sheath\Rules\BestPractices\Attributes\NoDuplicateIdRule;
use Forte\Sheath\Rules\BestPractices\Markup\NoNestedInteractiveRule;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Rules\Seo\NoMultipleH1Rule;

function reactiveIgnoreViolationCount(Rule $rule, string $source): int
{
    $registry = new RuleRegistry;
    $registry->register($rule);

    return count((new Linter($registry))->lint(
        $source,
        'component.blade.php',
        Config::make()->setRule($rule->getId(), 'error'),
    )->violations);
}

it('treats Alpine templates below x-ignore as inert', function (Rule $rule, string $source): void {
    expect(reactiveIgnoreViolationCount($rule, $source))->toBe(0);
})->with([
    'x-for does not duplicate an inert ID' => [
        new NoDuplicateIdRule,
        '<div x-ignore><template x-for="item in items"><div id="row"></div></template></div>',
    ],
    'x-if does not add another h1' => [
        new NoMultipleH1Rule,
        '<h1>Main</h1><div x-ignore><template x-if="open"><h1>Ignored</h1></template></div>',
    ],
    'ignored x-show leaves the heading visible' => [
        new NoSkipHeadingLevelsRule,
        '<h1>A</h1><h2 x-ignore x-show="false">B</h2><h3>C</h3>',
    ],
    'ignored x-show leaves the table header visible' => [
        new TableHeadersRule,
        '<table><tr><th x-ignore x-show="false">Name</th></tr><tr><td>A</td></tr></table>',
    ],
    'ignored x-if content stays outside the button tree' => [
        new NoNestedInteractiveRule,
        '<button type="button">Menu<div x-ignore><template x-if="open"><a href="/x">Link</a></template></div></button>',
    ],
]);

it('does not confuse x-ignore.self with an ignored descendant subtree', function (): void {
    expect(reactiveIgnoreViolationCount(
        new NoNestedInteractiveRule,
        '<button type="button">Menu<div x-ignore.self><template x-if="open"><a href="/x">Link</a></template></div></button>',
    ))->toBe(1);
});

it('keeps ordinary HTML descendants real inside x-ignore', function (): void {
    expect(reactiveIgnoreViolationCount(
        new NoNestedInteractiveRule,
        '<button type="button">Menu<div x-ignore><a href="/x">Link</a></div></button>',
    ))->toBe(1);
});
