<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Helpers\MethodFieldRule;

describe('MethodFieldRule', function (): void {
    it('passes for GET and POST forms', function (): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'valid' => [
                '<form method="GET"></form>',
                '<form method="POST">@csrf</form>',
                '<form method="post">@csrf</form>',
                '<form></form>',
            ],
        ]);
    });

    it('requires POST and a dangerous fix even when PUT/PATCH/DELETE forms already have @method', function (): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [
                [
                    'code' => '<form method="PUT">@method("PUT")@csrf</form>',
                    'errors' => [['hasFixAvailable' => true, 'hasDangerousFix' => true]],
                    'output' => '<form method="POST">@method("PUT")@csrf</form>',
                ],
                [
                    'code' => '<form method="PATCH">@method("PATCH")@csrf</form>',
                    'errors' => [['hasFixAvailable' => true, 'hasDangerousFix' => true]],
                    'output' => '<form method="POST">@method("PATCH")@csrf</form>',
                ],
                [
                    'code' => '<form method="DELETE">@method("DELETE")@csrf</form>',
                    'errors' => [['hasFixAvailable' => true, 'hasDangerousFix' => true]],
                    'output' => '<form method="POST">@method("DELETE")@csrf</form>',
                ],
            ],
        ]);
    });

    it('reports a mismatched static method directive and keeps both fixes dangerous', function (): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [[
                'code' => '<form method="PUT">@method("DELETE")</form>',
                'errors' => [
                    [
                        'hasDangerousFix' => true,
                    ],
                    [
                        'hasDangerousFix' => true,
                    ],
                ],
                'output' => '<form method="POST">@method(\'PUT\')</form>',
            ]],
        ]);
    });

    it('withholds an incomplete fix when the method directive cannot be verified', function (): void {
        $code = '<form method="PUT">@method($method)</form>';
        $tester = $this->getRuleTester();

        $tester->run(new MethodFieldRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [[

                    'hasFixAvailable' => false,
                ]],
            ]],
        ]);

        expect($tester->fix(new MethodFieldRule, $code, dangerous: true))->toBe($code);
    });

    it('repairs every statically mismatched method directive', function (string $directiveMethod): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [[
                'code' => '<form method="PUT">@method("'.$directiveMethod.'")</form>',
                'errors' => [
                    ['hasDangerousFix' => true],
                    [
                        'hasDangerousFix' => true,
                    ],
                ],
                'output' => '<form method="POST">@method(\'PUT\')</form>',
            ]],
        ]);
    })->with(['GET', 'POST', 'OPTIONS']);

    it('fails for PUT forms without @method', function (): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [
                [
                    'code' => '<form method="PUT">@csrf</form>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for PATCH forms without @method', function (): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [
                [
                    'code' => '<form method="PATCH">@csrf</form>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for DELETE forms without @method', function (): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [
                [
                    'code' => '<form method="DELETE">@csrf</form>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('does not count method directives that render unsuccessful controls', function (string $code): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [[

                    'hasDangerousFix' => true,
                ]],
            ]],
        ]);
    })->with([
        'template' => '<form method="PUT"><template>@method("PUT")</template></form>',
        'datalist' => '<form method="PUT"><datalist>@method("PUT")</datalist></form>',
        'disabled fieldset' => '<form method="PUT"><fieldset disabled>@method("PUT")</fieldset></form>',
        'conditionally disabled fieldset' => '<form method="PUT"><fieldset @if($off) disabled @endif>@method("PUT")</fieldset></form>',
        'disabled directive fieldset' => '<form method="PUT"><fieldset @disabled($off)>@method("PUT")</fieldset></form>',
    ]);

    it('accepts a method directive inside the first legend of a disabled fieldset', function (): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [[
                'code' => '<form method="PUT"><fieldset disabled><legend>@method("PUT")</legend></fieldset></form>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fixes the method attribute and inserts the directive together', function (): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [
                [
                    'code' => '<form method="PUT"></form>',
                    'errors' => [
                        ['hasFixAvailable' => true, 'hasDangerousFix' => true],
                    ],
                    'output' => "<form method=\"POST\">\n    @method('PUT')</form>",
                ],
            ],
        ]);
    });

    it('withholds method-spoofing changes unless dangerous fixes are enabled', function (string $code, string $output): void {
        $tester = $this->getRuleTester();

        expect($tester->fix(new MethodFieldRule, $code))->toBe($code)
            ->and($tester->fix(new MethodFieldRule, $code, dangerous: true))->toBe($output);
    })->with([
        'missing directive' => [
            '<form method="DELETE"></form>',
            "<form method=\"POST\">\n    @method('DELETE')</form>",
        ],
        'existing directive' => [
            '<form method="DELETE">@method("DELETE")</form>',
            '<form method="POST">@method("DELETE")</form>',
        ],
    ]);

    it('preserves attributes between method and the tag end when fixing', function (): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [
                [
                    'code' => '<form method="DELETE" action="{{ route(\'u.destroy\', [\'id\' => $id]) }}" class="inline"></form>',
                    'errors' => [
                        ['hasFixAvailable' => true],
                    ],
                    'output' => "<form method=\"POST\" action=\"{{ route('u.destroy', ['id' => \$id]) }}\" class=\"inline\">\n    @method('DELETE')</form>",
                ],
            ],
        ]);
    });

    it('preserves single-quoted method attributes when fixing', function (): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [
                [
                    'code' => "<form method='PUT'></form>",
                    'errors' => [
                        ['hasFixAvailable' => true],
                    ],
                    'output' => "<form method='POST'>\n    @method('PUT')</form>",
                ],
            ],
        ]);
    });

    it('handles case insensitivity', function (): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [
                [
                    'code' => '<form method="put"></form>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('preserves CRLF line endings when inserting @method', function (): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [[
                'code' => "<form method=\"DELETE\">\r\n    @csrf\r\n</form>",
                'errors' => [['hasFixAvailable' => true]],
                'output' => "<form method=\"POST\">\r\n    @method('DELETE')\r\n    @csrf\r\n</form>",
            ]],
        ]);
    });

    it('reports conditional method spoofing without offering an incomplete fix', function (string $code): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [[

                    'hasFixAvailable' => false,
                ]],
            ]],
        ]);
    })->with([
        'if without else' => '<form method="PUT">@if($allowed) @method("PUT") @endif</form>',
        'loop may not run' => '<form method="PUT">@foreach($items as $item) @method("PUT") @endforeach</form>',
        'switch without default' => '<form method="PUT">@switch($kind) @case("a") @method("PUT") @break @endswitch</form>',
        'forelse missing method in empty branch' => '<form method="PUT">@forelse($items as $item) @method("PUT") @empty Empty @endforelse</form>',
    ]);

    it('recognizes method spoofing guaranteed across every branch', function (string $code): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [[

                    'hasDangerousFix' => true,
                ]],
            ]],
        ]);
    })->with([
        'if and else' => '<form method="PUT">@if($a) @method("PUT") @else @method("PUT") @endif</form>',
        'switch with default' => '<form method="PUT">@switch($kind) @case("a") @method("PUT") @break @default @method("PUT") @endswitch</form>',
        'forelse and empty' => '<form method="PUT">@forelse($items as $item) @method("PUT") @empty @method("PUT") @endforelse</form>',
    ]);

    it('reports mismatched method directives in every guaranteed branch', function (): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [[
                'code' => '<form method="PUT">@if($a) @method("DELETE") @else @method("PATCH") @endif</form>',
                'errors' => 3,
                'output' => '<form method="POST">@if($a) @method(\'PUT\') @else @method(\'PUT\') @endif</form>',
            ]],
        ]);
    });

    it('reports method spoofing introduced by conditional attributes without an unsafe fix', function (): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [
                [
                    'code' => '<form @if($update) method="PUT" @else method="POST" @endif></form>',
                    'errors' => [[

                        'hasFixAvailable' => false,
                    ]],
                ],
                [
                    'code' => '<form @if($update) method="PUT" @else method="DELETE" @endif></form>',
                    'errors' => [[

                        'hasFixAvailable' => false,
                    ]],
                ],
            ],
        ]);
    });

    it('does not lose the method when unrelated conditionals exceed the path bound', function (): void {
        $unrelated = implode('', array_map(
            static fn (int $index): string => "@if(\$flag{$index}) data-{$index}=\"x\" @endif ",
            range(1, 8),
        ));

        $this->getRuleTester()->run(new MethodFieldRule, [
            'invalid' => [[
                'code' => '<form '.$unrelated.'method="PUT"></form>',
                'errors' => 1,
            ]],
        ]);
    });

    it('ignores spoofed methods only on paths whose native submission is durably intercepted', function (): void {
        $this->getRuleTester()->run(new MethodFieldRule, [
            'valid' => [
                '<form method="DELETE" wire:submit="remove"></form>',
                '<form method="PATCH" x-data @submit.prevent="save()"></form>',
                '<form method="PUT" x-on:submit.prevent="save()"></form>',
                '<form @if($reactive) method="DELETE" wire:submit="remove" @else method="POST" @endif></form>',
            ],
            'invalid' => [
                [
                    'code' => '<form method="DELETE" wire:submit.once="remove"></form>',
                    'errors' => 1,
                ],
                [
                    'code' => '<form method="PATCH" @submit="save()"></form>',
                    'errors' => 1,
                ],
                [
                    'code' => '<form method="PUT" @if($reactive) wire:submit="save" @endif></form>',
                    'errors' => 1,
                ],
                [
                    'code' => '<form @if($reactive) method="DELETE" @else method="POST" wire:submit="save" @endif></form>',
                    'errors' => 1,
                ],
                [
                    'code' => '<form x-ignore method="DELETE" @submit.prevent="remove()"></form>',
                    'errors' => 1,
                ],
                [
                    'code' => '<div x-ignore><form method="DELETE" wire:submit="remove"></form></div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });
});
