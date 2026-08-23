<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Echoes\NoTripleEchoRule;

describe('NoTripleEchoRule', function (): void {
    it('passes for double-brace echo statements', function (): void {
        $this->getRuleTester()->run(new NoTripleEchoRule, [
            'valid' => [
                '<div>{{ $variable }}</div>',
                '<p>{{ $user->name }}</p>',
                '{{ $value }}',
                '<span>{{ config("app.name") }}</span>',
            ],
        ]);
    });

    it('passes for raw echo statements', function (): void {
        $this->getRuleTester()->run(new NoTripleEchoRule, [
            'valid' => [
                '<div>{!! $html !!}</div>',
                '{!! $content !!}',
            ],
        ]);
    });

    it('fails for triple-brace echo statements', function (): void {
        $this->getRuleTester()->run(new NoTripleEchoRule, [
            'invalid' => [
                [
                    'code' => '<div>{{{ $variable }}}</div>',
                    'errors' => 1,
                ],
                [
                    'code' => '{{{ $value }}}',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('detects multiple triple-brace statements', function (): void {
        $this->getRuleTester()->run(new NoTripleEchoRule, [
            'invalid' => [
                [
                    'code' => '<p>{{{ $name }}}</p><p>{{{ $email }}}</p>',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('provides safe auto-fix to convert to double-brace', function (): void {
        $this->getRuleTester()->run(new NoTripleEchoRule, [
            'invalid' => [
                [
                    'code' => '{{{ $variable }}}',
                    'errors' => [
                        [
                            'hasFixAvailable' => true,
                            'hasDangerousFix' => false,
                        ],
                    ],
                    'output' => '{{ $variable }}',
                ],
            ],
        ]);
    });

    it('auto-fixes triple-brace in HTML context', function (): void {
        $this->getRuleTester()->run(new NoTripleEchoRule, [
            'invalid' => [
                [
                    'code' => '<div class="name">{{{ $user->name }}}</div>',
                    'errors' => 1,
                    'output' => '<div class="name">{{ $user->name }}</div>',
                ],
            ],
        ]);
    });

    it('reports and fixes triple echoes embedded in attributes', function (): void {
        $this->getRuleTester()->run(new NoTripleEchoRule, [
            'invalid' => [[
                'code' => '<div title="{{{ $title }}}"></div>',
                'errors' => [['hasFixAvailable' => true, 'hasDangerousFix' => false]],
                'output' => '<div title="{{ $title }}"></div>',
            ]],
        ]);
    });

    it('does not offer an unsafe fix when the expression contains the regular echo terminator', function (): void {
        $this->getRuleTester()->run(new NoTripleEchoRule, [
            'invalid' => [[
                'code' => "{{{ '}}' }}}",
                'errors' => [[
                    'hasFixAvailable' => false,
                ]],
                'output' => null,
            ]],
        ]);
    });
});
