<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Components\ComponentTagIntegrityRule;
use Forte\Sheath\Tests\Fixtures\LivewireTagPrecompilerPresenceStub;

require_once __DIR__.'/../../../../Fixtures/LivewireTagPrecompilerPresenceStub.php';

beforeAll(function (): void {
    $compiler = 'Livewire\\Mechanisms\\CompileLivewireTags\\LivewireTagPrecompiler';

    if (! class_exists($compiler)) {
        class_alias(LivewireTagPrecompilerPresenceStub::class, $compiler);
    }
});

describe('ComponentTagIntegrityRule', function (): void {
    it('passes for well-formed component tags', function (): void {
        $this->getRuleTester()->run(new ComponentTagIntegrityRule, [
            'valid' => [
                '<x-alert type="error" :message="$message" />',
                "<x-alert>\n<p>content</p>\n</x-alert>",
                '<x-alert :message="$msg" />',
                '<x-input :value="old(\'name\', $user->name)" />',
                '<x-alert message="{{ $msg }}" />',
                '<x-button ::class="{ danger: isDeleting }" />',
                '<x-button x-bind:disabled="isDeleting" />',
            ],
        ]);
    });

    it('rejects JavaScript syntax accidentally bound as a PHP component expression', function (): void {
        $this->getRuleTester()->run(new ComponentTagIntegrityRule, [
            'invalid' => [[
                'code' => '<x-button :class="{ danger: isDeleting }" />',
                'errors' => 1,
            ], [
                'code' => '<x-button :disabled="" />',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for the attribute shapes the component regex admits', function (): void {
        $this->getRuleTester()->run(new ComponentTagIntegrityRule, [
            'valid' => [
                "<x-button @class(['p-4', 'font-bold' => \$active]) />",
                "<x-button @style(['color: red' => \$hasError]) />",
                '<x-alert {{ $attributes }} />',
                '<x-alert {{ $attributes->merge([\'class\' => \'alert\']) }} />',
                '<x-alert {{ $attributes->whereStartsWith(\'wire:model\') }} />',
                '<x-alert {{ $attributes->filter(fn ($value, $key) => $key !== \'id\') }} />',
                '<x-alert {{ $attributes->futureBagMethod()->anotherBagMethod() }} />',
                '<x-input :$value />',
            ],
        ]);
    });

    it('passes for directive-shaped attributes on plain elements', function (): void {
        $this->getRuleTester()->run(new ComponentTagIntegrityRule, [
            'valid' => [
                '<input type="checkbox" @checked($checked) />',
                '<div @class([\'p-4\'])>x</div>',
                '<option @selected($isSelected)>A</option>',
            ],
        ]);
    });

    it('passes for stray closers of plain HTML tags', function (): void {
        $this->getRuleTester()->run(new ComponentTagIntegrityRule, [
            'valid' => [
                "<p>a</p>\n</div>",
            ],
        ]);
    });

    it('leaves component prefixes without a known compiler alone', function (): void {
        $this->getRuleTester()->run(new ComponentTagIntegrityRule, [
            'valid' => [
                '<flux:button @if($x) type="a" @endif />',
                '<statamic:collection>',
            ],
        ]);
    });

    it('fails for an echo inside a bound attribute', function (): void {
        $this->getRuleTester()->run(new ComponentTagIntegrityRule, [
            'invalid' => [
                [
                    'code' => '<x-alert :message="{{ $msg }}" />',
                    'errors' => [
                        [
                            'line' => 1,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('offers a safe fix when the bound value is exactly one echo', function (): void {
        $this->getRuleTester()->run(new ComponentTagIntegrityRule, [
            'invalid' => [
                [
                    'code' => '<x-alert :message="{{ $msg }}" />',
                    'errors' => [
                        ['hasFixAvailable' => true, 'hasDangerousFix' => false],
                    ],
                    'output' => '<x-alert :message="$msg" />',
                ],
                [
                    'code' => '<x-alert :message="{!! $msg !!}" />',
                    'errors' => [
                        ['hasFixAvailable' => true],
                    ],
                    'output' => '<x-alert :message="$msg" />',
                ],
            ],
        ]);
    });

    it('offers no fix when the bound value is more than the echo', function (): void {
        $this->getRuleTester()->run(new ComponentTagIntegrityRule, [
            'invalid' => [
                [
                    'code' => '<x-alert :message="{{ $msg }} extra" />',
                    'errors' => [
                        ['hasFixAvailable' => false],
                    ],
                ],
            ],
        ]);
    });

    it('fails for a directive block in a component attribute list', function (): void {
        $this->getRuleTester()->run(new ComponentTagIntegrityRule, [
            'invalid' => [
                [
                    'code' => '<x-alert @if($x) type="a" @endif />',
                    'errors' => [
                        [
                            'line' => 1,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('fails for a directive with arguments in a component attribute list', function (): void {
        $this->getRuleTester()->run(new ComponentTagIntegrityRule, [
            'invalid' => [
                [
                    'code' => '<x-input @checked($value) />',
                    'errors' => [
                        [
                            'line' => 1,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('fails for a non-attributes echo in a component attribute list', function (): void {
        $this->getRuleTester()->run(new ComponentTagIntegrityRule, [
            'invalid' => [
                [
                    'code' => '<x-alert {{ $foo }} />',
                    'errors' => [
                        [
                            'line' => 1,
                        ],
                    ],
                ],
                [
                    'code' => '<x-alert {!! $attrs !!} />',
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
                [
                    'code' => '<x-alert {{ $attributesEvil }} />',
                    'errors' => [['line' => 1]],
                ],
                [
                    'code' => '<x-alert {{ $attributes + $other }} />',
                    'errors' => [['line' => 1]],
                ],
                [
                    'code' => '<x-alert {{ $attributes->method() . $other }} />',
                    'errors' => [['line' => 1]],
                ],
            ],
        ]);
    });

    it('fails for an unclosed component tag', function (): void {
        $this->getRuleTester()->run(new ComponentTagIntegrityRule, [
            'invalid' => [
                [
                    'code' => "<x-alert>\n<p>content</p>",
                    'errors' => [
                        [
                            'line' => 1,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('fails for a stray component closing tag', function (): void {
        $this->getRuleTester()->run(new ComponentTagIntegrityRule, [
            'invalid' => [
                [
                    'code' => "<p>a</p>\n</x-alert>",
                    'errors' => [
                        [
                            'line' => 2,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('diagnoses mispaired nesting as the inner tag left unclosed', function (): void {
        $this->getRuleTester()->run(new ComponentTagIntegrityRule, [
            'invalid' => [
                [
                    'code' => "<x-card>\n<x-badge>\n</x-card>",
                    'errors' => [
                        ['line' => 2],
                    ],
                ],
            ],
        ]);
    });

    it('applies Laravel component integrity checks to the x colon spelling', function (): void {
        $this->getRuleTester()->run(new ComponentTagIntegrityRule, [
            'valid' => [
                '<x:alert :message="$message" />',
                '<x:alert>content</x:alert>',
            ],
            'invalid' => [[
                'code' => '<x:alert :message="{{ $message }}" />',
                'errors' => [['hasFixAvailable' => true]],
                'output' => '<x:alert :message="$message" />',
            ], [
                'code' => '<x:alert @if($urgent) type="error" @endif />',
                'errors' => [['line' => 1]],
            ], [
                'code' => '<x:alert>content',
                'errors' => [['line' => 1]],
            ]],
        ]);
    });

    it('applies compiler integrity checks to Livewire component tags when Livewire is installed', function (): void {
        $this->getRuleTester()->run(new ComponentTagIntegrityRule, [
            'valid' => [
                '<livewire:counter :count="$count" />',
                '<livewire:counter ::class="{ danger: isDeleting }" />',
                '<livewire:counter @class(["active" => $active]) />',
                '<livewire:counter @error="handleError" />',
                '<livewire:counter @if.window="handleCondition" />',
                '<livewire:counter>content</livewire:counter>',
            ],
            'invalid' => [[
                'code' => '<livewire:counter :class="{ danger: isDeleting }" />',
                'errors' => [['line' => 1]],
            ], [
                'code' => '<livewire:counter :message="{{ $message }}" />',
                'errors' => [['hasFixAvailable' => true]],
                'output' => '<livewire:counter :message="$message" />',
            ], [
                'code' => '<livewire:counter @if($active) mode="active" @endif />',
                'errors' => [['line' => 1]],
            ], [
                'code' => '<livewire:counter @checked($active) />',
                'errors' => [['line' => 1]],
            ], [
                'code' => '<livewire:counter>content',
                'errors' => [['line' => 1]],
            ], [
                'code' => '</livewire:counter>',
                'errors' => [['line' => 1]],
            ]],
        ]);
    });
});
