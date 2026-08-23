<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Echoes\ValidEchoExpressionRule;

describe('ValidEchoExpressionRule', function (): void {
    it('passes for ordinary echoes', function (): void {
        $this->getRuleTester()->run(new ValidEchoExpressionRule, [
            'valid' => [
                '{{ $user->name }}',
                '{{ $count + 1 }}',
                "{{ \$user->isAdmin() ? 'Admin' : 'Member' }}",
                '{!! $trustedHtml !!}',
                '{{{ $legacy }}}',
                "{{ \$name ?? 'Guest' }}",
                '{{ collect($items)->map(fn ($i) => $i * 2)->sum() }}',
                "{{ __('messages.welcome') }}",
                '{{ $value; }}',
                '{!! $trustedHtml; !!}',
                '{{{ $legacy; }}}',
            ],
        ]);
    });

    it('passes for multiline echoes', function (): void {
        $this->getRuleTester()->run(new ValidEchoExpressionRule, [
            'valid' => [
                "{{ \$user\n  ->name }}",
            ],
        ]);
    });

    it('passes for echoes in attribute values', function (): void {
        $this->getRuleTester()->run(new ValidEchoExpressionRule, [
            'valid' => [
                '<div class="{{ $classes }}">x</div>',
                '<a href="{{ route(\'home\') }}">x</a>',
            ],
        ]);
    });

    it('passes for escaped echoes and verbatim content', function (): void {
        $this->getRuleTester()->run(new ValidEchoExpressionRule, [
            'valid' => [
                '<p>@{{ anything here }}</p>',
                "@verbatim\n{{ }}\n{{ vueExpression }}\n@endverbatim",
            ],
        ]);
    });

    it('passes for raw echoes containing a double closing brace', function (): void {
        $this->getRuleTester()->run(new ValidEchoExpressionRule, [
            'valid' => [
                '{!! $a . "}}" !!}',
            ],
        ]);
    });

    it('fails for empty echoes', function (): void {
        $this->getRuleTester()->run(new ValidEchoExpressionRule, [
            'invalid' => [
                [
                    'code' => '<p>{{ }}</p>',
                    'errors' => 1,
                ],
                [
                    'code' => '<p>{{}}</p>',
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });

    it('fails for empty echoes inside attribute values', function (): void {
        $this->getRuleTester()->run(new ValidEchoExpressionRule, [
            'invalid' => [
                [
                    'code' => '<div class="{{ }}">x</div>',
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });

    it('fails when a string literal contains the echo terminator', function (): void {
        $this->getRuleTester()->run(new ValidEchoExpressionRule, [
            'invalid' => [
                [
                    'code' => '<p>{{ "literal }} inside" }}</p>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails when a raw echo string contains its terminator', function (): void {
        $this->getRuleTester()->run(new ValidEchoExpressionRule, [
            'invalid' => [
                [
                    'code' => '<p>{!! "x!!} y" !!}</p>',
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });

    it('fails for content that is not a php expression', function (): void {
        $this->getRuleTester()->run(new ValidEchoExpressionRule, [
            'invalid' => [
                [
                    'code' => '<p>{{ $x; $y }}</p>',
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
                [
                    'code' => '<p>{{ $x;; }}</p>',
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });

    it('offers no fix', function (): void {
        $this->getRuleTester()->run(new ValidEchoExpressionRule, [
            'invalid' => [
                [
                    'code' => '<p>{{ }}</p>',
                    'errors' => [
                        ['hasFixAvailable' => false],
                    ],
                ],
            ],
        ]);
    });
});
