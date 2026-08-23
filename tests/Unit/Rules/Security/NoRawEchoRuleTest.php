<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Security\NoRawEchoRule;

describe('NoRawEchoRule', function (): void {
    it('passes for escaped echo statements', function (): void {
        $this->getRuleTester()->run(new NoRawEchoRule, [
            'valid' => [
                '<div>{{ $variable }}</div>',
                '<div>{{{ $variable }}}</div>',
                '<p>{{ $user->name }}</p>',
                '<input {!! $attributes->merge([\'class\' => \'field\']) !!}>',
                '<button {!! $attributes->class([\'active\' => $active]) !!}>x</button>',
                '<div {!! $attributes->only([\'id\', \'class\']) !!}>',
                '<input {!! $attributes->whereStartsWith(\'wire:model\') !!}>',
                '<div {!! $attributes->except(\'class\') !!}>',
                '<div {!! $attributes->filter(fn ($value, $key) => $key !== \'onclick\')->style([\'color: red\']) !!}>',
            ],
        ]);
    });

    it('does not treat arbitrary expressions rooted at the attribute bag as safe', function (): void {
        $this->getRuleTester()->run(new NoRawEchoRule, [
            'invalid' => [[
                'code' => '<input {!! $attributes->get(\'onclick\') !!}>',
                'errors' => 1,
            ], [
                'code' => '<input {!! $attributes->merge([]) . unsafe() !!}>',
                'errors' => 1,
            ], [
                'code' => '<input {!! $attributes->merge([])->get(\'onclick\') !!}>',
                'errors' => 1,
            ], [
                'code' => '<input {!! $attributes->unknownMacro() !!}>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for raw echo statements', function (): void {
        $this->getRuleTester()->run(new NoRawEchoRule, [
            'invalid' => [
                [
                    'code' => '<div>{!! $variable !!}</div>',
                    'errors' => 1,
                ],
                [
                    'code' => '{!! $html !!}',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('allows exact and wildcard patterns', function (): void {
        $rule = new NoRawEchoRule;
        $rule->setOptions(['allowed' => ['$trustedHtml', '$trustedComponent*']]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '{!! $trustedHtml !!}',
                "{!! \$trustedComponent\n    ->render() !!}",
            ],
            'invalid' => [
                [
                    'code' => '{!! $untrustedHtml !!}',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('detects multiple raw echo statements', function (): void {
        $this->getRuleTester()->run(new NoRawEchoRule, [
            'invalid' => [
                [
                    'code' => '<div>{!! $html1 !!}{!! $html2 !!}</div>',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('detects raw echoes embedded in element and component attributes', function (): void {
        $this->getRuleTester()->run(new NoRawEchoRule, [
            'invalid' => [
                ['code' => '<div title="{!! $value !!}"></div>', 'errors' => 1],
                ['code' => '<x-card label="{!! $value !!}" />', 'errors' => 1],
                [
                    'code' => '<div data-a="{!! $a !!}" data-b="{!! $b !!}"></div>',
                    'errors' => 2,
                ],
            ],
        ]);
    });
});
