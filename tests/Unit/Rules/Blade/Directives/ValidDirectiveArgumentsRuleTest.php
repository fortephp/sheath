<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Directives\ValidDirectiveArgumentsRule;

describe('ValidDirectiveArgumentsRule', function (): void {
    it('passes for well-formed directives', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'valid' => [
                "@if(\$user->isAdmin())\nAdmin\n@endif",
                "@foreach(\$items as \$item)\n<li>{{ \$item }}</li>\n@endforeach",
                "@forelse(\$users as \$user)\nx\n@empty\ny\n@endforelse",
                "@include('partials.nav')",
                "@section('title', 'Home')",
                "@can('update', \$post)\nx\n@endcan",
                '<div @class([\'p-4\', \'font-bold\' => $active])>x</div>',
                '@dd()',
                '@dump()',
                '@fonts',
                '@fonts()',
                "@for(\$i = 0; \$i < 10; \$i++)\nx\n@endfor",
                "@while(\$running)\nx\n@endwhile",
                '@php($counter = 1)',
            ],
        ]);
    });

    it('passes for directives that are legitimately bare', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'valid' => [
                "@auth\nx\n@endauth",
                "@guest\nx\n@endguest",
                "@once\nx\n@endonce",
                '@csrf',
                "@production\nx\n@endproduction",
                "@php\n\$x = 1;\n@endphp",
                "@verbatim\nx\n@endverbatim",
                "@lang\nWelcome\n@endlang",
                "@if(\$a)\nx\n@else\ny\n@endif",
            ],
        ]);
    });

    it('passes for string-aware argument parsing', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'valid' => [
                "@if(\$x == ')')\nx\n@endif",
            ],
        ]);
    });

    it('passes for escapes and unknown directives', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'valid' => [
                '<p>@@if is literal</p>',
                '<p>Contact john@stillat.com or @john on Slack.</p>',
                '<style>@media (min-width: 640px) { .a { color: red } }</style>',
                "@verbatim\n@if\n@endverbatim",
            ],
        ]);
    });

    it('fails for a bare @if in prose', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'invalid' => [
                [
                    'code' => '<p>You should @if something is wrong check twice.</p>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for scss and css collisions', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'invalid' => [
                [
                    'code' => '<style>@use "sass:math";</style>',
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });

    it('fails for bare known directives that require arguments', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'invalid' => [
                [
                    'code' => '<form>@method</form>',
                    'errors' => [['line' => 1]],
                ],
                [
                    'code' => '<script>var x = @json;</script>',
                    'errors' => [['line' => 1]],
                ],
            ],
        ]);
    });

    it('fails for every argument-requiring core directive added after the original set', function (string $code): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [['line' => 1]],
            ]],
        ]);
    })->with([
        'session' => '@session @endsession',
        'context' => '@context @endcontext',
        'fragment' => '@fragment @endfragment',
        'hasstack' => '@hasstack @endif',
        'pushif' => '@pushif @endpushif',
        'includeisolated' => '@includeisolated',
        'extendsfirst' => '@extendsfirst',
        'componentfirst' => '@componentfirst @endcomponentfirst',
        'bool' => '@bool',
        'unset' => '@unset',
        'dd' => '@dd',
        'dump' => '@dump',
    ]);

    it('fails for @vite without an entrypoint', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'invalid' => [[
                'code' => '@vite',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for empty inline php statements', function (string $code): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [['line' => 1]],
            ]],
        ]);
    })->with([
        'empty' => '@php()',
        'whitespace' => '@php( )',
        'comment only' => '@php(/* no statement */)',
    ]);

    it('rejects empty required argument lists', function (string $code): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'if' => '@if()x@endif',
        'unless whitespace' => '@unless(   )x@endunless',
        'isset comment only' => '@isset(/* no expression */)x@endisset',
        'empty conditional' => '@empty()x@endempty',
        'switch and case' => '@switch($x)@case()x@endswitch',
        'include' => '@include()',
        'method' => '@method()',
        'standalone lang' => '@lang()',
        'vite' => '@vite()',
        'inside attribute' => '<div title="@include()"></div>',
    ]);

    it('rejects comma expressions in single-condition directives', function (string $code): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [['line' => 1]],
            ]],
        ]);
    })->with([
        'if' => '@if($a, $b)x@endif',
        'unless' => '@unless($a, $b)x@endunless',
        'switch' => '@switch($a, $b)@default x @endswitch',
        'case' => '@switch($value)@case($a, $b)x@endswitch',
        'checked' => '@checked($a, $b)',
        'selected' => '@selected($a, $b)',
        'disabled' => '@disabled($a, $b)',
        'readonly' => '@readonly($a, $b)',
        'required' => '@required($a, $b)',
        'bool' => '@bool($a, $b)',
    ]);

    it('validates @use using Laravel\'s compiled statement shape', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'valid' => [
                "@use('App\\Models\\Flight')",
                "@use('App\\Models\\Flight', 'FlightModel')",
                "@use('function App\\Support\\format_name')",
                "@use('const App\\Support\\DEFAULT_NAME')",
                "@use('App\\Models\\{Flight, Airport}')",
            ],
            'invalid' => [[
                'code' => '@use($class)',
                'errors' => [['line' => 1]],
            ], [
                'code' => '@use(Foo\\Bar::class)',
                'errors' => [['line' => 1]],
            ]],
        ]);
    });

    it('validates Laravel fonts directive arguments', function (string $code): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [['line' => 1]],
            ]],
        ]);
    })->with([
        'incomplete expression' => '@fonts($fonts +)',
        'malformed comma expression' => '@fonts(,)',
    ]);

    it('requires exactly two inject arguments', function (string $code): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'one argument' => "@inject('service')",
        'three arguments' => "@inject('service', Service::class, 'extra')",
    ]);

    it('enforces directive-specific minimum argument counts', function (string $code, int $minimum): void {
        $name = strtolower(substr($code, 1, strpos($code, '(') - 1));

        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'each' => ["@each('view')", 3],
        'choice' => ["@choice('key')", 2],
        'includeWhen' => ['@includeWhen(true)', 2],
        'includeUnless' => ['@includeUnless(false)', 2],
        'pushIf' => ['@pushIf($ok)', 2],
        'elsePushIf' => ['@elsePushIf($ok)', 2],
    ]);

    it('treats @empty contextually', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'valid' => [
                "@forelse(\$users as \$user)\nx\n@empty\ny\n@endforelse",
                "@empty(\$list)\nnothing\n@endempty",
            ],
            'invalid' => [
                [
                    'code' => '<style>div:empty { color: red } @empty</style>',
                    'errors' => 1,
                ],
                [
                    'code' => '<p>The list may be @empty today.</p>',
                    'errors' => [['line' => 1]],
                ],
            ],
        ]);
    });

    it('fails when the arguments open on the next line', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'invalid' => [
                [
                    'code' => "@if\n(\$x)\nbody\n@endif",
                    'errors' => 1,
                ],
                [
                    'code' => "@vite\n(['resources/js/app.js'])",
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for smart quotes in arguments', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'invalid' => [
                [
                    'code' => '@include(‘partials.nav’)',
                    'errors' => 1,
                ],
                [
                    'code' => '@if($status == “active”)x@endif',
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });

    it('allows smart quote characters inside valid php strings and comments', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'valid' => [
                "@if(\$label === '“')x@endif",
                '@if($label === "’")x@endif',
                '@if($ok /* “quoted prose” */)x@endif',
            ],
        ]);
    });

    it('fails for @foreach without as', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'invalid' => [
                [
                    'code' => "@foreach(\$items)\n<li>x</li>\n@endforeach",
                    'errors' => 1,
                ],
                [
                    'code' => "@forelse(\$items)\nx\n@empty\ny\n@endforelse",
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });

    it('fails for arguments that are not valid php', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'invalid' => [
                [
                    'code' => "@include('partial', ['count' => {{ \$n }}])",
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });

    it('validates arguments supplied to directives whose arguments are optional', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'valid' => [
                '@auth($guard) x @endauth',
                '@guest($guard) x @endguest',
            ],
            'invalid' => [
                [
                    'code' => '@auth($guard +) x @endauth',
                    'errors' => [['line' => 1]],
                ],
                [
                    'code' => '@guest($guard +) x @endguest',
                    'errors' => [['line' => 1]],
                ],
            ],
        ]);
    });

    it('validates the PHP shape emitted by Laravel for compiler-sensitive directives', function (string $code): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [['line' => 1]],
            ]],
        ]);
    })->with([
        'conditional empty' => '@empty($value,)x@endempty',
        'include' => '@include("partial",)',
        'include if' => '@includeIf("partial",)',
        'include when' => '@includeWhen(true, "partial",)',
        'include unless' => '@includeUnless(false, "partial",)',
        'include first' => '@includeFirst(["partial"],)',
        'extends' => '@extends("layout",)',
        'extends first' => '@extendsFirst(["layout"],)',
        'push if' => '@pushIf(true, "scripts",)x@endPushIf',
        'json' => '@json($value,)',
        'props' => '@props([],)',
        'aware' => '@aware([],)',
    ]);

    it('preserves valid trailing commas and incomplete block fragments', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'valid' => [
                '@includeIsolated("partial",)',
                '@can("update",)x@endcan',
                '@env("production",)x@endenv',
                '@section("title",)',
                '@js($value,)',
                '@choice("messages.count", 1,)',
                '@auth($guard)',
                '@foreach($items as $item)',
            ],
        ]);
    });

    it('offers no fix', function (): void {
        $this->getRuleTester()->run(new ValidDirectiveArgumentsRule, [
            'invalid' => [
                [
                    'code' => '<p>You should @if something is wrong check twice.</p>',
                    'errors' => [
                        ['hasFixAvailable' => false],
                    ],
                ],
            ],
        ]);
    });
});
