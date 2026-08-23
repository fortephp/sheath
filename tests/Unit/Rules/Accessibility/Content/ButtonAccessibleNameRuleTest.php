<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Content\ButtonAccessibleNameRule;

describe('ButtonAccessibleNameRule', function (): void {
    it('correlates exclusion alternatives and conditional descendant visibility', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<button @if($x) hidden @else inert @endif></button>',
            ],
            'invalid' => [[
                'code' => '<button><span @if($x) hidden @endif>Save</span></button>',
                'errors' => 1,
            ]],
        ]);
    });

    it('uses the first accessible-name attribute on each render path', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [[
                'code' => '<button @if($x) aria-label="" @endif aria-label="Save"></button>',
                'errors' => 1,
            ]],
        ]);
    });
    it('passes for buttons with text content', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<button>Click me</button>',
                '<button>Submit</button>',
                '<button><span>Icon</span> Label</button>',
            ],
        ]);
    });

    it('passes for buttons with aria-label', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<button aria-label="Close dialog"></button>',
                '<button aria-label="Menu"><i class="icon-menu"></i></button>',
            ],
        ]);
    });

    it('passes for buttons with aria-labelledby', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<span id="btn-label">Save</span><button aria-labelledby="btn-label"></button>',
                '@if($show)<span id="btn-label">Save</span><button aria-labelledby="btn-label"></button>@endif',
                '<span id="btn-label">Save</span><template x-teleport="body"><button aria-labelledby="btn-label"></button></template>',
            ],
        ]);
    });

    it('requires aria-labelledby targets on every path that renders the button', function (string $label): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [[
                'code' => "<!doctype html><html><body>{$label}<button aria-labelledby=\"label\"></button></body></html>",
                'errors' => 1,
            ]],
        ]);
    })->with([
        'conditional target' => '@if($show)<span id="label">Save</span>@endif',
        'loop target' => '@foreach($labels as $label)<span id="label">Save</span>@endforeach',
        'captured target' => '@push("labels")<span id="label">Save</span>@endpush',
        'inert target' => '<template><span id="label">Save</span></template>',
    ]);

    it('fails for unresolved aria-labelledby in a complete document', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [[
                'code' => '<!doctype html><html><body><button aria-labelledby="missing"></button></body></html>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for unresolved aria-labelledby on every button representation', function (string $code): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [[
                'code' => "<!doctype html><html><body>{$code}</body></html>",
                'errors' => 1,
            ]],
        ]);
    })->with([
        'role button' => [
            '<div role="button" aria-labelledby="missing"></div>',
        ],
        'input button' => [
            '<input type="button" aria-labelledby="missing">',
        ],
    ]);

    it('ignores button representations outside the accessibility tree', function (string $code): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, ['valid' => [$code]]);
    })->with([
        'hidden button' => '<button hidden></button>',
        'aria-hidden role button' => '<div role="button" aria-hidden="true"></div>',
        'inert input button' => '<section inert><input type="button"></section>',
    ]);

    it('resolves aria-labelledby against exact untrimmed HTML ids', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [[
                'code' => '<html><span id=" foo ">Name</span><button aria-labelledby="foo"></button></html>',
                'errors' => 1,
            ]],
        ]);
    });

    it('uses the non-empty contribution from multiple aria-labelledby references', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<span id="empty"></span><span id="label">Save</span><button aria-labelledby="empty label missing"></button>',
            ],
        ]);
    });

    it('passes for buttons with title attribute', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<button title="Close"><i class="icon-x"></i></button>',
            ],
        ]);
    });

    it('passes for buttons with image alt text', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<button><img src="icon.png" alt="Search"></button>',
            ],
        ]);
    });

    it('passes when a child SVG contributes the accessible name', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<button><svg aria-label="Close"></svg></button>',
                '<span id="save-label">Save</span><button><svg aria-labelledby="save-label"></svg></button>',
            ],
        ]);
    });

    it('fails for empty buttons', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [
                [
                    'code' => '<button></button>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('does not count content inside an inert descendant', function (string $code): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'static attribute' => '<button><span inert>Save</span></button>',
        'conditional attribute' => '<button><span @if($hidden) inert @endif>Save</span></button>',
    ]);

    it('does not treat non-rendering Blade or PHP as an accessible name', function (string $content): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [[
                'code' => "<button>{$content}</button>",
                'errors' => 1,
            ]],
        ]);
    })->with([
        'empty conditional' => '@if($condition)@endif',
        'CSRF directive' => '@csrf',
        'inline PHP assignment' => '@php($label = null)',
        'PHP tag assignment' => '<?php $label = null; ?>',
        'PHP block assignment' => '@php $label = null; @endphp',
    ]);

    it('recognizes output nested inside control directives and PHP', function (string $content): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => ["<button>{$content}</button>"],
        ]);
    })->with([
        'PHP echo' => '<?php echo $label; ?>',
        'short echo' => '<?= $label ?>',
        'PHP output call' => '<?php printf("%s", $label); ?>',
    ]);

    it('requires a name on every rendered path', function (string $content): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [[
                'code' => "<button>{$content}</button>",
                'errors' => 1,
            ]],
        ]);
    })->with([
        'optional conditional text' => '@if($condition)Save@endif',
        'conditional continue produces no text' => '@foreach($items as $item)@continue(false)@endforeach',
    ]);

    it('checks elements whose effective fallback role is button', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<div role="button" aria-label="Save"></div>',
                '<div role="checkbox button"></div>',
            ],
            'invalid' => [
                [
                    'code' => '<div role="button"></div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<div role="future-role button checkbox"></div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('does not split fallback roles on vertical tab', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => ["<div role=\"future-role\x0Bbutton\"></div>"],
        ]);
    });

    it('correlates conditional role classification with name attributes', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<div @if($x) role="button" aria-label="Save" @endif></div>',
            ],
            'invalid' => [
                [
                    'code' => '<div @if($x) role="button" @endif></div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<div @if($x) role="button" @else aria-label="Save" @endif></div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('correlates conditional input-button types with their names', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<input @if($x) type="button" value="Go" @endif>',
            ],
            'invalid' => [[
                'code' => '<input @if($x) type="button" @endif>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for buttons with only icon and no accessible name', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [
                [
                    'code' => '<button><i class="icon-close"></i></button>',
                    'errors' => 1,
                ],
                [
                    'code' => '<!doctype html><html><body><button><svg id="icon" aria-labelledby="icon"></svg></button></body></html>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('treats aria-hidden values case-insensitively when evaluating nested content', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [[
                'code' => '<button><svg aria-hidden="TRUE" aria-label="Icon"><title>Icon</title></svg></button>',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for input buttons with value', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<input type="button" value="Click me">',
                '<input type="submit" value="Send">',
                '<input type="reset" value="Clear">',
            ],
        ]);
    });

    it('passes for submit/reset inputs without value (have default labels)', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<input type="submit">',
                '<input type="reset">',
            ],
        ]);
    });

    it('fails for submit and reset inputs whose explicit value is empty', function (string $code): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'submit' => '<input type="submit" value="">',
        'reset' => '<input type="reset" value="">',
    ]);

    it('fails for input type="button" without value', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [
                [
                    'code' => '<input type="button">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('does not treat an empty aria-labelledby as an accessible name for an input button', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [[
                'code' => '<input type="button" aria-labelledby="">',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for image inputs with alt', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<input type="image" src="btn.png" alt="Submit">',
            ],
        ]);
    });

    it('fails for image inputs without alt', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [
                [
                    'code' => '<input type="image" src="btn.png">',
                    'errors' => 1,
                ],
                [
                    'code' => '<input type="image" src="btn.png" value="Submit">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('skips inputs with dynamic type attributes', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<input :type="inputType">',
                '<input type="{{ $type }}">',
                '<input type="{{ $isSubmit ? \'submit\' : \'button\' }}">',
            ],
        ]);
    });

    it('stands down when an opaque provider may supply an input button name', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => ['<input type="button" {{ $attributes->merge([\'class\' => \'x\']) }}>'],
        ]);
    });

    it('passes for buttons whose text is rendered by Alpine or Livewire', function (string $code): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, ['valid' => [$code]]);
    })->with([
        'x-text' => '<button type="button" x-text="label"></button>',
        'x-html' => '<button type="button" x-html="labelHtml"></button>',
        'wire:text' => '<button type="button" wire:text="label"></button>',
    ]);

    it('uses client-rendered text referenced by aria-labelledby', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<span id="label" x-text="label"></span><button aria-labelledby="label"></button>',
                '<span id="label" x-html="labelHtml"></span><button aria-labelledby="label"></button>',
                '<span id="label" wire:text="label"></span><button aria-labelledby="label"></button>',
            ],
            'invalid' => [[
                'code' => '<span id="label" x-text=""></span><button aria-labelledby="label"></button>',
                'errors' => 1,
            ]],
        ]);
    });

    it('rejects empty client-rendered text directives', function (string $code): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'empty x-text' => '<button type="button" x-text=""></button>',
        'whitespace x-text' => '<button type="button" x-text="  "></button>',
        'bare x-html' => '<button type="button" x-html></button>',
        'empty wire:text' => '<button type="button" wire:text=""></button>',
        'bare wire:text' => '<button type="button" wire:text></button>',
        'empty Alpine literal replaces static children' => '<button type="button" x-text="\'\'">Save</button>',
        'empty Alpine HTML replaces static children' => '<button type="button" x-html="\'\'"><span>Save</span></button>',
        'empty Livewire text replaces static children' => '<button type="button" wire:text="">Save</button>',
    ]);

    it('does not use conditionally visible reactive content as the only name', function (string $code): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'Alpine show' => '<button type="button"><span x-show="visible">Save</span></button>',
        'Livewire show' => '<button type="button"><span wire:show="visible">Save</span></button>',
        'Livewire loading' => '<button type="button"><span wire:loading>Saving</span></button>',
        'Livewire loading remove' => '<button type="button"><span wire:loading.remove>Save</span></button>',
        'Livewire dirty' => '<button type="button"><span wire:dirty>Unsaved</span></button>',
        'Livewire offline' => '<button type="button"><span wire:offline>Offline</span></button>',
    ]);

    it('keeps content mutated only by reactive classes or attributes', function (string $code): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, ['valid' => [$code]]);
    })->with([
        '<button type="button"><span wire:loading.class="opacity-50">Save</span></button>',
        '<button type="button"><span wire:dirty.attr="aria-busy">Save</span></button>',
        '<button type="button"><span wire:offline.class.remove="online">Save</span></button>',
        '<button type="button"><span x-show="true">Save</span></button>',
    ]);

    it('does not count Alpine directives that x-ignore prevents from initializing', function (string $code): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'ignored x-text owner' => '<button type="button" x-ignore x-text="label"></button>',
        'ignored Alpine subtree' => '<button type="button"><span x-ignore><span x-text="label"></span></span></button>',
        'ignored Livewire subtree' => '<button type="button"><span x-ignore><span wire:text="label"></span></span></button>',
        'ignored x-if template' => '<button type="button"><span x-ignore><template x-if="open"><span>Save</span></template></span></button>',
        'self-ignored x-if template' => '<button type="button"><template x-ignore.self x-if="open"><span>Save</span></template></button>',
    ]);

    it('keeps directives that Alpine or Livewire still initializes across x-ignore.self', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<button type="button"><span x-ignore.self><span x-text="label"></span></span></button>',
                '<button type="button" x-ignore wire:text="label"></button>',
                '<button type="button"><span x-ignore x-show="false">Save</span></button>',
            ],
        ]);
    });
});

describe('native label associations for buttons', function (): void {
    it('accepts explicit and wrapping native labels', function (string $code): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, ['valid' => [$code]]);
    })->with([
        '<label for="save">Save</label><button id="save"></button>',
        '<label>Save <button></button></label>',
        '<label for="save">Save</label><input id="save" type="button">',
        '<label>Save <input type="button"></label>',
        '<label for="{{ $buttonId }}">Save</label><button id="{{ $buttonId }}"></button>',
    ]);

    it('does not accept an empty external label', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [[
                'code' => '<label for="save"></label><button id="save"></button>',
                'errors' => 1,
            ]],
        ]);
    });

    it('correlates shared Alpine and Livewire visibility for names', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<button x-show="open"><span x-show="open">Save</span></button>',
                '<button wire:show="open"><span x-show="open">Save</span></button>',
                '<button wire:loading wire:target="save"><span wire:loading wire:target="save">Saving</span></button>',
                '<span id="label" x-show="open">Save</span><button x-show="open" aria-labelledby="label"></button>',
                '<template x-if="open"><span id="label">Save</span><button aria-labelledby="label"></button></template>',
            ],
            'invalid' => [[
                'code' => '<span id="label" x-show="open">Save</span><button aria-labelledby="label"></button>',
                'errors' => 1,
            ], [
                'code' => '<template x-if="open"><span id="label">Save</span></template><button aria-labelledby="label"></button>',
                'errors' => 1,
            ], [
                'code' => '<button x-show="open"><span x-show="! open">Save</span></button>',
                'errors' => 1,
            ]],
        ]);
    });

    it('uses the first labelable descendant for an implicit label', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [[
                'code' => '<label>Name <input type="text"><button></button></label>',
                'errors' => 1,
            ]],
            'valid' => [
                '<label>Save <input type="hidden" name="token"><button></button></label>',
            ],
        ]);
    });

    it('correlates the first implicit control across exclusive branches', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'valid' => [
                '<label>Name @if($x)<input type="text">@else<button></button>@endif</label>',
            ],
        ]);
    });

    it('reports a button path with an optional earlier labelable control', function (): void {
        $this->getRuleTester()->run(new ButtonAccessibleNameRule, [
            'invalid' => [[
                'code' => '<label>Name @if($x)<input type="text">@endif<button></button></label>',
                'errors' => 1,
            ]],
        ]);
    });
});
