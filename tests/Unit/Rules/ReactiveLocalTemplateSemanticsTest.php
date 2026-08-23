<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Linter;
use Forte\Sheath\Results\Violation;
use Forte\Sheath\Rules\Accessibility\Content\FormLabelRule;
use Forte\Sheath\Rules\Accessibility\Headings\NoEmptyHeadingsRule;
use Forte\Sheath\Rules\Accessibility\Headings\NoHeadingInsideButtonRule;
use Forte\Sheath\Rules\Accessibility\Structure\ListSemanticsRule;
use Forte\Sheath\Rules\BestPractices\Attributes\ButtonTypeRule;
use Forte\Sheath\Rules\BestPractices\Markup\NoNestedInteractiveRule;
use Forte\Sheath\Rules\RuleRegistry;

/** @return list<Violation> */
function reactiveLocalTemplateViolations(Rule $rule, string $source): array
{
    $registry = new RuleRegistry;
    $registry->register($rule);

    return (new Linter($registry))->lint(
        $source,
        'component.blade.php',
        Config::make()->setRule($rule->getId(), 'error'),
    )->violations;
}

describe('Alpine local template rendering', function (): void {
    it('retains form and label ancestry for x-if and x-for roots', function (Rule $rule, string $source): void {
        expect(reactiveLocalTemplateViolations($rule, $source))->toBe([]);
    })->with([
        'x-if explicit label' => [
            new FormLabelRule,
            '<label for="name">Name</label><template x-if="open"><input id="name" type="text"></template>',
        ],
        'x-for list item' => [
            new ListSemanticsRule,
            '<ul><template x-for="item in items"><li x-text="item"></li></template></ul>',
        ],
    ]);

    it('reports invalid relationships that become real when a local template renders', function (Rule $rule, string $source): void {
        expect(reactiveLocalTemplateViolations($rule, $source))->toHaveCount(1);
    })->with([
        'x-if nested interactive element' => [
            new NoNestedInteractiveRule,
            '<button type="button">Menu<template x-if="open"><a href="/x">Link</a></template></button>',
        ],
        'x-for nested interactive element' => [
            new NoNestedInteractiveRule,
            '<button type="button">Menu<template x-for="item in items"><a href="/x">Link</a></template></button>',
        ],
        'x-for misplaced list item' => [
            new ListSemanticsRule,
            '<div><template x-for="item in items"><li x-text="item"></li></template></div>',
        ],
        'x-if heading inside button' => [
            new NoHeadingInsideButtonRule,
            '<button type="button"><template x-if="open"><h2>Menu</h2></template></button>',
        ],
    ]);

    it('uses the local runtime parent for button fix safety and type choice', function (): void {
        $violation = reactiveLocalTemplateViolations(
            new ButtonTypeRule,
            '<form><template x-if="show"><button>Save</button></template></form>',
        )[0] ?? null;

        expect($violation)->not->toBeNull()
            ->and($violation?->fix?->replacement)->toBe(' type="submit"')
            ->and($violation?->fix?->dangerous)->toBeFalse();
    });

    it('validates empty headings inside native and reactive templates', function (string $source): void {
        expect(reactiveLocalTemplateViolations(new NoEmptyHeadingsRule, $source))->toHaveCount(1);
    })->with([
        'native template' => '<template><h2></h2></template>',
        'x-if template' => '<template x-if="show"><h2></h2></template>',
        'x-for template' => '<template x-for="item in items"><h2></h2></template>',
        'x-teleport template' => '<template x-teleport="body"><h2></h2></template>',
    ]);

    it('keeps native and teleported templates detached from their source parent', function (string $source): void {
        expect(reactiveLocalTemplateViolations(new NoNestedInteractiveRule, $source))->toBe([]);
    })->with([
        'native template' => '<button type="button">Menu<template><a href="/x">Link</a></template></button>',
        'x-teleport template' => '<button type="button">Menu<template x-teleport="body"><a href="/x">Link</a></template></button>',
    ]);
});
