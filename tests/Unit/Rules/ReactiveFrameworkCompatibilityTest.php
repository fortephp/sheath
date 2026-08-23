<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Accessibility\Content\AnchorContentRule;
use Forte\Sheath\Rules\Accessibility\Content\ButtonAccessibleNameRule;
use Forte\Sheath\Rules\Accessibility\Content\FormLabelRule;
use Forte\Sheath\Rules\Accessibility\Headings\NoEmptyHeadingsRule;
use Forte\Sheath\Rules\BestPractices\Attributes\ButtonTypeRule;
use Forte\Sheath\Rules\Blade\Directives\NoDirectiveAttributeCollisionRule;
use Forte\Sheath\Rules\Blade\Directives\UnclosedDirectivesRule;
use Forte\Sheath\Rules\Blade\Directives\ValidDirectiveArgumentsRule;
use Forte\Sheath\Rules\RuleRegistry;

it('does not treat unrelated Livewire directives as an accessible name', function (string $directive): void {
    $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
        'invalid' => [[
            'code' => "<button type=\"button\" {$directive}></button>",
            'errors' => 1,
        ]],
    ]);
})->with([
    'wire:click' => 'wire:click="save"',
    'wire:model' => 'wire:model="choice"',
    'wire:loading' => 'wire:loading',
    'wire:navigate' => 'wire:navigate',
    'wire:current' => 'wire:current="active"',
    'wire:cloak' => 'wire:cloak',
    'wire:dirty' => 'wire:dirty',
    'wire:confirm' => 'wire:confirm="Continue?"',
    'wire:transition' => 'wire:transition',
    'wire:init' => 'wire:init="load"',
    'wire:intersect' => 'wire:intersect="load"',
    'wire:poll' => 'wire:poll="refresh"',
    'wire:offline' => 'wire:offline',
    'wire:ignore' => 'wire:ignore',
    'wire:ref' => 'wire:ref="button"',
    'wire:replace' => 'wire:replace',
    'wire:show' => 'wire:show="visible"',
    'wire:sort' => 'wire:sort="reorder"',
    'wire:stream' => 'wire:stream="answer"',
]);

it('does not treat unrelated Alpine directives as an accessible name', function (string $directive): void {
    $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
        'invalid' => [[
            'code' => "<button type=\"button\" {$directive}></button>",
            'errors' => 1,
        ]],
    ]);
})->with([
    'x-data' => 'x-data="{}"',
    'x-init' => 'x-init="load()"',
    'x-show' => 'x-show="visible"',
    'x-model' => 'x-model="choice"',
    'x-modelable' => 'x-modelable="choice"',
    'x-for' => 'x-for="item in items"',
    'x-transition' => 'x-transition',
    'x-effect' => 'x-effect="sync()"',
    'x-ignore' => 'x-ignore',
    'x-ref' => 'x-ref="button"',
    'x-cloak' => 'x-cloak',
    'x-teleport' => 'x-teleport="body"',
    'x-if' => 'x-if="visible"',
    'x-id' => 'x-id="[\'button\']"',
    'x-on' => 'x-on:click="save()"',
]);

it('keeps native requirements active for look-alike reactive behavior', function (object $rule, string $code): void {
    $this->getRuleTester()->run($rule, [
        'invalid' => [[
            'code' => $code,
            'errors' => 1,
        ]],
    ]);
})->with([
    'Livewire click is not a button type' => [
        new ButtonTypeRule,
        '<form method="post"><button wire:click="save">Save</button></form>',
    ],
    'Alpine click is not a button type' => [
        new ButtonTypeRule,
        '<form method="post"><button @click="save()">Save</button></form>',
    ],
    'wire:model is not a label' => [
        new FormLabelRule,
        '<input type="text" wire:model="name">',
    ],
    'x-model is not a label' => [
        new FormLabelRule,
        '<input type="text" x-model="name">',
    ],
    'wire:navigate is not anchor content' => [
        new AnchorContentRule,
        '<a href="/settings" wire:navigate></a>',
    ],
    'Alpine click is not anchor content' => [
        new AnchorContentRule,
        '<a href="#" @click.prevent="open = true"></a>',
    ],
    'wire:show is not heading content' => [
        new NoEmptyHeadingsRule,
        '<h2 wire:show="visible"></h2>',
    ],
    'x-show is not heading content' => [
        new NoEmptyHeadingsRule,
        '<h2 x-show="visible"></h2>',
    ],
    'empty Alpine object binding is not an attribute provider' => [
        new ButtonTypeRule,
        '<button x-bind="">Save</button>',
    ],
]);

it('reports one actionable error for an Alpine shorthand collision', function (string $source): void {
    $rules = [
        new NoDirectiveAttributeCollisionRule,
        new UnclosedDirectivesRule,
        new ValidDirectiveArgumentsRule,
    ];
    $registry = new RuleRegistry;
    $config = Config::make()->setPreset('empty');

    foreach ($rules as $rule) {
        $registry->register($rule);
        $config->setRule($rule->getId(), 'error');
    }

    $violations = (new Linter($registry))->lint($source, 'component.blade.php', $config)->violations;

    expect($violations)->toHaveCount(1)
        ->and($violations[0]->ruleId)->toBe('blade-no-directive-attribute-collision');
})->with([
    '<div @empty="reload()"></div>',
    '<div @error.window="handle"></div>',
    '<div @if="handle"></div>',
    '<div @isset="handle"></div>',
]);
