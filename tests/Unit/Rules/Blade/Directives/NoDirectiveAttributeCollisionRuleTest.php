<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Blade\Directives\NoDirectiveAttributeCollisionRule;
use Forte\Sheath\Rules\Blade\Directives\UnclosedDirectivesRule;
use Forte\Sheath\Rules\Blade\Directives\ValidDirectiveArgumentsRule;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Tests\Fixtures\LivewireTagPrecompilerPresenceStub;

require_once __DIR__.'/../../../../Fixtures/LivewireTagPrecompilerPresenceStub.php';

beforeAll(function (): void {
    $compiler = 'Livewire\\Mechanisms\\CompileLivewireTags\\LivewireTagPrecompiler';

    if (! class_exists($compiler)) {
        class_alias(LivewireTagPrecompilerPresenceStub::class, $compiler);
    }
});

describe('NoDirectiveAttributeCollisionRule', function (): void {
    it('reports collisions with legal HTML whitespace around the equals sign', function (string $code): void {
        $this->getRuleTester()->run(new NoDirectiveAttributeCollisionRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'spaces' => '<div @csrf = "handler"></div>',
        'modifier and spaces' => '<div @error.stop = "handler"></div>',
        'tab and newline' => "<div @empty\t=\n\"handler\"></div>",
        'component' => '<x-panel @csrf = "handler" />',
        'kebab suffix' => '<div @csrf-token="handler"></div>',
        'colon suffix' => '<div @csrf:token="handler"></div>',
    ]);

    it('passes for unknown event names', function (): void {
        $this->getRuleTester()->run(new NoDirectiveAttributeCollisionRule, [
            'valid' => [
                '<button @click="open = true">x</button>',
                '<form @submit.prevent="save">x</form>',
                '<div @keydown.escape="close()">x</div>',
                '<div @custom-event="handle">x</div>',
                '<div @custom:event="handle">x</div>',
            ],
        ]);
    });

    it('passes for intentional blade directives in attribute position', function (): void {
        $this->getRuleTester()->run(new NoDirectiveAttributeCollisionRule, [
            'valid' => [
                '<x-input @checked($isChecked) />',
                '<x-input @disabled($isDisabled) />',
                '<div @class([\'p-4\', \'font-bold\' => $active])>x</div>',
                '<option @selected($isSelected)>x</option>',
            ],
        ]);
    });

    it('passes for escaped directive attributes', function (): void {
        $this->getRuleTester()->run(new NoDirectiveAttributeCollisionRule, [
            'valid' => [
                '<img :src="src" @@error="handleError">',
                '<div @@empty="reload()">x</div>',
            ],
        ]);
    });

    it('passes child-listener attributes that the installed Livewire compiler consumes first', function (string $source): void {
        $this->getRuleTester()->run(new NoDirectiveAttributeCollisionRule, [
            'valid' => [$source],
        ]);
    })->with([
        '<livewire:child @error="handleError" />',
        '<livewire:child @empty="handleEmpty" />',
        '<livewire:child @if="handleCondition" />',
    ]);

    it('does not leak Blade structure diagnostics for installed Livewire listener attributes', function (string $source): void {
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

        expect((new Linter($registry))->lint($source, 'component.blade.php', $config)->violations)->toBe([]);
    })->with([
        '<livewire:child @error="handleError" />',
        '<livewire:child @empty="handleEmpty" />',
        '<livewire:child @if.window="handleCondition" />',
    ]);

    it('passes for plain attributes', function (): void {
        $this->getRuleTester()->run(new NoDirectiveAttributeCollisionRule, [
            'valid' => [
                '<div class="test" data-error="x">x</div>',
                '<img src="a.png" onerror="handleError()">',
            ],
        ]);
    });

    it('fails for a vue @error listener on an element', function (): void {
        $this->getRuleTester()->run(new NoDirectiveAttributeCollisionRule, [
            'invalid' => [
                [
                    'code' => '<img :src="src" @error="handleError">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for an alpine @empty listener', function (): void {
        $this->getRuleTester()->run(new NoDirectiveAttributeCollisionRule, [
            'invalid' => [
                [
                    'code' => '<div @empty="reload()">x</div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('recognizes Laravel fonts as a compiler directive in attribute position', function (): void {
        $this->getRuleTester()->run(new NoDirectiveAttributeCollisionRule, [
            'invalid' => [[
                'code' => '<div @fonts="handler"></div>',
                'errors' => [['line' => 1]],
                'hasFix' => false,
            ]],
        ]);
    });

    it('fails for listeners with modifiers', function (): void {
        $this->getRuleTester()->run(new NoDirectiveAttributeCollisionRule, [
            'invalid' => [
                [
                    'code' => '<div @error.window="handle">x</div>',
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });

    it('fails for collisions on component tags', function (): void {
        $this->getRuleTester()->run(new NoDirectiveAttributeCollisionRule, [
            'invalid' => [
                [
                    'code' => '<x-alert @error="handleError" />',
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });

    it('offers no fix', function (): void {
        $this->getRuleTester()->run(new NoDirectiveAttributeCollisionRule, [
            'invalid' => [
                [
                    'code' => '<div @empty="reload()">x</div>',
                    'errors' => [
                        ['hasFixAvailable' => false],
                    ],
                ],
            ],
        ]);
    });
});
