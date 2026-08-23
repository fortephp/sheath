<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Echoes\NoUnquotedEchoAttributeRule;

describe('NoUnquotedEchoAttributeRule', function (): void {
    it('passes for quoted echo attribute values', function (): void {
        $this->getRuleTester()->run(new NoUnquotedEchoAttributeRule, [
            'valid' => [
                '<div class="{{ $classes }}">x</div>',
                "<div class='{{ \$classes }}'>x</div>",
                '<a href="{{ route(\'home\') }}">Home</a>',
                '<div class="btn {{ $active ? \'active\' : \'\' }}">x</div>',
            ],
        ]);
    });

    it('passes for unquoted values without echoes', function (): void {
        $this->getRuleTester()->run(new NoUnquotedEchoAttributeRule, [
            'valid' => [
                '<div class=foo>x</div>',
                '<input type=text>',
            ],
        ]);
    });

    it('passes for attribute-position constructs and boolean attributes', function (): void {
        $this->getRuleTester()->run(new NoUnquotedEchoAttributeRule, [
            'valid' => [
                '<div {{ $attributes }}>x</div>',
                '<div @class([\'p-4\'])>x</div>',
                '<input disabled>',
            ],
        ]);
    });

    it('passes for escaped echoes and bound shorthand', function (): void {
        $this->getRuleTester()->run(new NoUnquotedEchoAttributeRule, [
            'valid' => [
                '<div class=@{{ x }}>y</div>',
                '<x-input :value=$val />',
            ],
        ]);
    });

    it('leaves component bound attributes to blade-component-tag-integrity', function (): void {
        $this->getRuleTester()->run(new NoUnquotedEchoAttributeRule, [
            'valid' => [
                '<x-input :value={{ $x }} />',
            ],
        ]);
    });

    it('fails for an unquoted echo attribute value', function (): void {
        $this->getRuleTester()->run(new NoUnquotedEchoAttributeRule, [
            'invalid' => [
                [
                    'code' => '<div class={{ $classes }}>x</div>',
                    'errors' => [
                        [
                            'line' => 1,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('fails for an unquoted value that mixes an echo with text', function (): void {
        $this->getRuleTester()->run(new NoUnquotedEchoAttributeRule, [
            'invalid' => [
                [
                    'code' => '<div class={{ $x }}-card>x</div>',
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });

    it('fails for unquoted echo values on component tags', function (): void {
        $this->getRuleTester()->run(new NoUnquotedEchoAttributeRule, [
            'invalid' => [
                [
                    'code' => '<x-alert class={{ $x }} />',
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });

    it('wraps the value in quotes as a safe fix', function (): void {
        $this->getRuleTester()->run(new NoUnquotedEchoAttributeRule, [
            'invalid' => [
                [
                    'code' => '<div class={{ $classes }}>x</div>',
                    'errors' => [
                        ['hasFixAvailable' => true, 'hasDangerousFix' => false],
                    ],
                    'output' => '<div class="{{ $classes }}">x</div>',
                ],
                [
                    'code' => '<div class={{ $x }}-card>x</div>',
                    'errors' => [
                        ['hasFixAvailable' => true],
                    ],
                    'output' => '<div class="{{ $x }}-card">x</div>',
                ],
                [
                    'code' => '<option value={{ $id }}>A</option>',
                    'errors' => [
                        ['hasFixAvailable' => true],
                    ],
                    'output' => '<option value="{{ $id }}">A</option>',
                ],
            ],
        ]);
    });

    it('uses single quotes when the value contains a double quote', function (): void {
        $this->getRuleTester()->run(new NoUnquotedEchoAttributeRule, [
            'invalid' => [
                [
                    'code' => '<div data-x={{ $a ? "y" : "n" }}>x</div>',
                    'errors' => [
                        ['hasFixAvailable' => true],
                    ],
                    'output' => "<div data-x='{{ \$a ? \"y\" : \"n\" }}'>x</div>",
                ],
            ],
        ]);
    });

    it('does not quote raw echoes automatically because runtime quotes can change the parsed attributes', function (): void {
        $code = '<div title={!! $value !!}></div>';
        $tester = $this->getRuleTester();

        $tester->run(new NoUnquotedEchoAttributeRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [[
                    'hasFixAvailable' => false,
                    'hasDangerousFix' => false,
                ]],
            ]],
        ]);

        expect($tester->fix(new NoUnquotedEchoAttributeRule, $code))->toBe($code)
            ->and($tester->fix(new NoUnquotedEchoAttributeRule, $code, dangerous: true))->toBe($code);
    });

    it('reports each offending attribute', function (): void {
        $this->getRuleTester()->run(new NoUnquotedEchoAttributeRule, [
            'invalid' => [
                [
                    'code' => '<div class={{ $a }} id={{ $b }}>x</div>',
                    'errors' => [
                        ['line' => 1],
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });
});
