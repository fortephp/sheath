<?php

declare(strict_types=1);

use Forte\Parser\Directives\Directives;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Blade\Directives\UnclosedDirectivesRule;
use Forte\Sheath\Rules\RuleRegistry;

describe('UnclosedDirectivesRule', function (): void {
    it('passes for properly paired if directive', function (): void {
        $this->getRuleTester()->run(new UnclosedDirectivesRule, [
            'valid' => [
                '@if($condition) content @endif',
                '@if ($user) Hello {{ $user->name }} @endif',
                '@if($show)<div>Content</div>@endif',
            ],
        ]);
    });

    it('passes for properly paired foreach directive', function (): void {
        $this->getRuleTester()->run(new UnclosedDirectivesRule, [
            'valid' => [
                '@foreach($items as $item){{ $item }}@endforeach',
                '@foreach ($users as $user)<li>{{ $user->name }}</li>@endforeach',
            ],
        ]);
    });

    it('passes for properly paired nested directives', function (): void {
        $this->getRuleTester()->run(new UnclosedDirectivesRule, [
            'valid' => [
                '@if($condition) @foreach($items as $item){{ $item }}@endforeach @endif',
                '@auth @can("edit") Edit @endcan @endauth',
                <<<'BLADE'
@if ($show)
    Visible
@else
    @empty($first)
        First is empty
    @else
        First is present
    @endempty

    @empty($second)
        Second is empty
    @else
        Second is present
    @endempty
@endif
BLADE,
            ],
        ]);
    });

    it('passes for non-block directives', function (): void {
        $this->getRuleTester()->run(new UnclosedDirectivesRule, [
            'valid' => [
                '@extends("layouts.app")',
                '@include("partials.header")',
                '@yield("content")',
                '@props(["type" => "button"])',
                '@class(["btn", "btn-primary" => $primary])',
            ],
        ]);
    });

    it('fails for unclosed if directive', function (): void {
        $this->getRuleTester()->run(new UnclosedDirectivesRule, [
            'invalid' => [
                [
                    'code' => '@if($condition) content',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for unclosed foreach directive', function (): void {
        $this->getRuleTester()->run(new UnclosedDirectivesRule, [
            'invalid' => [
                [
                    'code' => '@foreach($items as $item){{ $item }}',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for orphaned endif directive', function (): void {
        $this->getRuleTester()->run(new UnclosedDirectivesRule, [
            'invalid' => [
                [
                    'code' => 'content @endif',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for orphaned branch directives that compile to invalid PHP', function (string $code): void {
        $this->getRuleTester()->run(new UnclosedDirectivesRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'else' => '@else',
        'elseif' => '@elseif($enabled)',
        'elsecan' => "@elsecan('update', \$post)",
        'elsecannot' => "@elsecannot('update', \$post)",
        'elsecanany' => "@elsecanany(['update'], \$post)",
    ]);

    it('fails for orphaned endforeach directive', function (): void {
        $this->getRuleTester()->run(new UnclosedDirectivesRule, [
            'invalid' => [
                [
                    'code' => '{{ $item }} @endforeach',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for multiple unclosed directives', function (): void {
        $this->getRuleTester()->run(new UnclosedDirectivesRule, [
            'invalid' => [
                [
                    'code' => '@if($a) @foreach($b as $c) content',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('respects custom directive list via options', function (): void {
        $rule = new UnclosedDirectivesRule;
        $rule->setOptions(['directives' => ['if']]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '@foreach($items as $item){{ $item }}',
            ],
            'invalid' => [
                [
                    'code' => '@if($condition) content',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for unclosed auth directive', function (): void {
        $this->getRuleTester()->run(new UnclosedDirectivesRule, [
            'invalid' => [
                [
                    'code' => '@auth Protected content',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for unclosed can directive', function (): void {
        $this->getRuleTester()->run(new UnclosedDirectivesRule, [
            'invalid' => [
                [
                    'code' => '@can("edit") Edit button',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('handles case insensitivity', function (): void {
        $this->getRuleTester()->run(new UnclosedDirectivesRule, [
            'valid' => [
                '@IF($condition) content @ENDIF',
            ],
        ]);
    });

    it('accepts custom Directives service via constructor', function (): void {
        $directives = new Directives;

        $rule = new UnclosedDirectivesRule($directives);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '@if($condition) content',
                '@foreach($items as $item){{ $item }}',
            ],
        ]);
    });

    it('uses injected Directives to determine which directives to check', function (): void {
        $directives = new Directives;
        $directives->loadJson(json_encode([
            [
                'name' => 'foreach',
                'structure' => [
                    'role' => 'open',
                    'terminators' => 'endforeach',
                ],
            ],
            [
                'name' => 'endforeach',
                'structure' => [
                    'role' => 'close',
                ],
            ],
        ]));

        $rule = new UnclosedDirectivesRule($directives);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '@if($condition) content',
            ],
            'invalid' => [
                [
                    'code' => '@foreach($items as $item){{ $item }}',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('uses default Directives when none injected', function (): void {
        $rule = new UnclosedDirectivesRule;

        $this->getRuleTester()->run($rule, [
            'invalid' => [
                [
                    'code' => '@if($condition) content',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('resolves Directives from container when using resolver', function (): void {
        $customDirectives = new Directives;

        $registry = new RuleRegistry;

        $registry->setResolver(function (string $ruleClass) use ($customDirectives) {
            if ($ruleClass === UnclosedDirectivesRule::class) {
                return new UnclosedDirectivesRule($customDirectives);
            }

            return new $ruleClass;
        });

        $registry->register(UnclosedDirectivesRule::class);

        $config = Config::make();
        $config->setRule('blade-unclosed-directives', [
            'severity' => 'error',
            'options' => [],
        ]);

        $linter = new Linter($registry);
        $result = $linter->lint('@if($condition) content', 'test.blade.php', $config);

        $violations = array_filter(
            $result->violations,
            fn ($v) => $v->ruleId === 'blade-unclosed-directives'
        );

        expect($violations)->toBeEmpty();
    });

    describe('directives that accept more than one terminator', function (): void {
        it('accepts them when correctly closed', function (string $code): void {
            $this->getRuleTester()->run(new UnclosedDirectivesRule, ['valid' => [$code]]);
        })->with([
            'push' => '@push("scripts")<script src="a.js" defer></script>@endpush',
            'push in a layout' => '<div>@push("styles")<link rel="stylesheet" href="a.css">@endpush</div>',
            'push closed by endpushOnce' => '@push("scripts")<script src="a.js" defer></script>@endpushOnce',
            'pushOnce' => '@pushOnce("scripts")<script src="a.js" defer></script>@endpush',
            'prepend' => '@prepend("head")<meta name="x" content="y">@endprepend',
            'component' => '@component("mail::message")<p>Body</p>@endcomponent',
            'push nested in once' => '@once @push("scripts")<script src="a.js" defer></script>@endpush @endonce',
            'two pushes' => '@push("a")<p>1</p>@endpush @push("b")<p>2</p>@endpush',
        ]);

        it('reports one that is never closed', function (string $code): void {
            $this->getRuleTester()->run(new UnclosedDirectivesRule, [
                'invalid' => [['code' => $code, 'errors' => 1]],
            ]);
        })->with([
            'push' => ['@push("scripts")<script src="a.js" defer></script>'],
            'prepend' => ['@prepend("head")<meta name="x" content="y">'],
            'component' => ['@component("mail::message")<p>Body</p>'],
        ]);

        it('reports a terminator with nothing to close', function (string $code): void {
            $this->getRuleTester()->run(new UnclosedDirectivesRule, [
                'invalid' => [['code' => $code, 'errors' => 1]],
            ]);
        })->with([
            'bare endpush' => ['<div>@endpush</div>'],
            'bare endcomponent' => ['<div>@endcomponent</div>'],
        ]);

        it('treats a terminator flush against text as ordinary content', function (): void {
            $this->getRuleTester()->run(new UnclosedDirectivesRule, [
                'invalid' => [[
                    'code' => '@component("mail::message")Body@endcomponent',
                    'errors' => 1,
                ]],
            ]);
        });
    });

    it('checks directive structure inside opening tags and attribute values', function (string $code): void {
        $this->getRuleTester()->run(new UnclosedDirectivesRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'unclosed opener' => [
            '<div @if($shown) title="Visible"></div>',
        ],
        'orphaned closer in value' => [
            '<div title="@endif"></div>',
        ],
    ]);

    describe('raw-block strays (@endphp / @endverbatim)', function (): void {
        it('passes for paired raw blocks', function (): void {
            $this->getRuleTester()->run(new UnclosedDirectivesRule, [
                'valid' => [
                    "@php\n\$x = 1;\n@endphp",
                    "@verbatim\n<p>{{ raw }}</p>\n@endverbatim",
                    '@php($x = 1)',
                ],
            ]);
        });

        it('passes for escaped and email-like spellings', function (): void {
            $this->getRuleTester()->run(new UnclosedDirectivesRule, [
                'valid' => [
                    '@@endverbatim is the escape',
                    '<p>mail docs@endverbatim.example for details</p>',
                    '{{-- @endverbatim in a comment is another rule\'s finding --}}',
                ],
            ]);
        });

        it('fails for a stray @endphp', function (): void {
            $this->getRuleTester()->run(new UnclosedDirectivesRule, [
                'invalid' => [
                    [
                        'code' => "<div>a</div>\n@endphp\n<div>b</div>",
                        'errors' => [
                            [
                                'line' => 2,
                            ],
                        ],
                    ],
                ],
            ]);
        });

        it('fails for a stray @endverbatim', function (): void {
            $this->getRuleTester()->run(new UnclosedDirectivesRule, [
                'invalid' => [
                    [
                        'code' => "<p>a</p>\n@endverbatim\n<p>b</p>",
                        'errors' => [
                            [
                                'line' => 2,
                            ],
                        ],
                    ],
                ],
            ]);
        });

        it('respects the directives option for raw-block strays', function (): void {
            $rule = new UnclosedDirectivesRule;
            $rule->setOptions(['directives' => ['if']]);

            $this->getRuleTester()->run($rule, [
                'valid' => [
                    "<div>a</div>\n@endphp",
                    "<p>a</p>\n@endverbatim",
                ],
            ]);
        });
    });

    describe('overlapping @section directives', function (): void {
        it('passes for sequential and inline sections', function (): void {
            $this->getRuleTester()->run(new UnclosedDirectivesRule, [
                'valid' => [
                    "@section('a')\nx\n@endsection\n@section('b')\ny\n@stop",
                    "@section('sidebar')\ncontent\n@show",
                    "@section('title', 'Home')\n@section('content')\nbody\n@endsection",
                    "@section('scripts')\n<script src=\"a.js\" defer></script>\n@append",
                ],
            ]);
        });

        it('passes for genuinely nested sections, each with a terminator', function (): void {
            $this->getRuleTester()->run(new UnclosedDirectivesRule, [
                'valid' => [
                    "@section('a')\n@section('b')\nx\n@endsection\n@endsection",
                ],
            ]);
        });

        it('reports section terminator aliases without an open section', function (string $directive): void {
            $this->getRuleTester()->run(new UnclosedDirectivesRule, [
                'invalid' => [[
                    'code' => "<p>before</p>\n@{$directive}",
                    'errors' => [[

                        'line' => 2,
                    ]],
                ]],
            ]);
        })->with(['stop', 'show', 'append', 'overwrite']);

        it('fails when a second @section opens while the first is never closed', function (): void {
            $this->getRuleTester()->run(new UnclosedDirectivesRule, [
                'invalid' => [
                    [
                        'code' => "@section('one')\nfirst\n@section('two')\nsecond\n@endsection",
                        'errors' => [
                            [
                                'line' => 3,
                            ],
                        ],
                    ],
                ],
            ]);
        });

        it('does not mistake a comma in a PHP comment for inline section content', function (string $comment): void {
            $first = "@section('one' {$comment})";

            $this->getRuleTester()->run(new UnclosedDirectivesRule, [
                'invalid' => [[
                    'code' => "{$first}\nfirst\n@section('two')\nsecond\n@endsection",
                    'errors' => 1,
                ]],
            ]);
        })->with([
            'block comment' => '/* , */',
            'line comment' => "// ,\n",
            'hash comment' => "# ,\n",
        ]);

        it('names each break in a longer chain', function (): void {
            $this->getRuleTester()->run(new UnclosedDirectivesRule, [
                'invalid' => [
                    [
                        'code' => "@section('a')\n@section('b')\n@section('c')\nx\n@endsection",
                        'errors' => [
                            ['line' => 2],
                            ['line' => 3],
                        ],
                    ],
                ],
            ]);
        });

        it('respects the directives option for section overlap', function (): void {
            $rule = new UnclosedDirectivesRule;
            $rule->setOptions(['directives' => ['if']]);

            $this->getRuleTester()->run($rule, [
                'valid' => [
                    "@section('one')\nfirst\n@section('two')\nsecond\n@endsection",
                ],
            ]);
        });
    });
});
