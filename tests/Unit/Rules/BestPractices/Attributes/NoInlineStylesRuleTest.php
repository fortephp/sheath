<?php

declare(strict_types=1);

use Forte\Sheath\Rules\BestPractices\Attributes\NoInlineStylesRule;

describe('NoInlineStylesRule', function (): void {
    it('recognizes the Blade style directive as an inline style producer', function (): void {
        $this->getRuleTester()->run(new NoInlineStylesRule, [
            'invalid' => [[
                'code' => '<div @style([\'color: red\'])>Alert</div>',
                'errors' => 1,
                'hasFix' => false,
            ]],
        ]);
    });

    it('applies allowedProperties to literal Blade style directives without guessing dynamic maps', function (): void {
        $rule = new NoInlineStylesRule;
        $rule->setOptions(['allowedProperties' => ['animation-delay']]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<div @style([\'animation-delay: 0.8s\'])>Alert</div>',
                '<div @style($styles)>Alert</div>',
            ],
            'invalid' => [[
                'code' => '<div @style([\'animation-delay: 0.8s\', \'position: fixed\' => $fixed])>Alert</div>',
                'errors' => 1,
                'hasFix' => false,
            ]],
        ]);
    });

    it('ignores constant-false Blade style entries but retains true and dynamic entries', function (): void {
        $rule = new NoInlineStylesRule;
        $rule->setOptions(['allowedProperties' => ['color']]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<div @style([\'color: red\' => true, \'position: fixed\' => false])></div>',
                '<div @style([\'color: red\' => true, \'position: fixed\' => (false)])></div>',
            ],
            'invalid' => [[
                'code' => '<div @style([\'color: red\' => true, \'position: fixed\' => true])></div>',
                'errors' => 1,
            ], [
                'code' => '<div @style([\'color: red\' => true, \'position: fixed\' => $fixed])></div>',
                'errors' => 1,
            ]],
        ]);
    });

    it('uses an unconditional Blade style directive before later duplicate style attributes', function (): void {
        $rule = new NoInlineStylesRule;
        $rule->setOptions(['allowedProperties' => ['color']]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<div @style([\'color: red\']) style="position: fixed"></div>',
                '<div @style($styles) style="position: fixed"></div>',
            ],
        ]);
    });

    it('passes for elements without style attribute', function (): void {
        $this->getRuleTester()->run(new NoInlineStylesRule, [
            'valid' => [
                '<div>content</div>',
                '<div class="foo">content</div>',
                '<p>text</p>',
                '<span id="test">text</span>',
            ],
        ]);
    });

    it('fails for elements with inline styles', function (): void {
        $this->getRuleTester()->run(new NoInlineStylesRule, [
            'invalid' => [
                [
                    'code' => '<div style="color: red">content</div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<p style="margin: 10px">text</p>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('detects multiple inline styles', function (): void {
        $this->getRuleTester()->run(new NoInlineStylesRule, [
            'invalid' => [
                [
                    'code' => '<div style="color: red"><p style="margin: 10px">text</p></div>',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('works with blade syntax', function (): void {
        $this->getRuleTester()->run(new NoInlineStylesRule, [
            'valid' => [
                '<div>{{ $variable }}</div>',
                '@if($condition)<div>content</div>@endif',
            ],
            'invalid' => [
                [
                    'code' => '<div style="color: red">{{ $variable }}</div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('still reports a style attribute built from Blade, but offers no fix', function (string $code): void {
        $this->getRuleTester()->run(new NoInlineStylesRule, [
            'invalid' => [
                [
                    'code' => $code,
                    'errors' => 1,
                    'hasFix' => false,
                ],
            ],
        ]);
    })->with([
        'echo in the value' => '<div style="width: {{ $w }}px">s</div>',
        'echo is the whole value' => '<div style="{{ $styles }}">s</div>',
        'two echoes' => '<div style="width: {{ $w }}px; height: {{ $h }}px">s</div>',
    ]);

    it('still fixes a style attribute that is static all the way through', function (): void {
        $this->getRuleTester()->run(new NoInlineStylesRule, [
            'invalid' => [
                [
                    'code' => '<div style="color: red">{{ $variable }}</div>',
                    'errors' => 1,
                    'hasFix' => true,
                ],
            ],
        ]);
    });

    it('allows style attributes that only declare allowed properties', function (): void {
        $rule = new NoInlineStylesRule;
        $rule->setOptions(['allowedProperties' => ['animation-delay']]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<circle style="animation-delay: 0.8s" />',
                '<div style="animation-delay: 0.8s;">content</div>',
            ],
        ]);
    });

    it('checks only the first style attribute emitted on each render path', function (): void {
        $rule = new NoInlineStylesRule;
        $rule->setOptions(['allowedProperties' => ['animation-delay']]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<div style="animation-delay: 0.8s" @if($legacy) style="color: red" @endif>content</div>',
            ],
        ]);
    });

    it('scans CSS declarations without splitting semicolons inside values', function (string $code): void {
        $rule = new NoInlineStylesRule;
        $rule->setOptions(['allowedProperties' => ['--message', '--theme', 'background-image', 'color']]);

        $this->getRuleTester()->run($rule, ['valid' => [$code]]);
    })->with([
        'quoted string' => '<div style="--message: \'one;two\'">content</div>',
        'data URL function' => '<div style=\'background-image: url("data:image/svg+xml;charset=utf-8,%3Csvg%3E")\'>content</div>',
        'custom property block' => '<div style="--theme: { color: red; background: blue; }">content</div>',
        'escaped semicolon' => '<div style="--message: one\\;two">content</div>',
        'comment' => '<div style="color: red /* ; background: blue */">content</div>',
        'comment before property' => '<div style="/* ; background: blue */ color: red">content</div>',
    ]);

    it('still fires when a disallowed property appears alongside allowed ones', function (): void {
        $rule = new NoInlineStylesRule;
        $rule->setOptions(['allowedProperties' => ['animation-delay']]);

        $this->getRuleTester()->run($rule, [
            'invalid' => [
                [
                    'code' => '<div style="animation-delay: 0.8s; color: red">content</div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<div style="animation-delay: var(--delay, \'0;1s\'); color: red">content</div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('matches allowed properties case-insensitively', function (): void {
        $rule = new NoInlineStylesRule;
        $rule->setOptions(['allowedProperties' => ['Animation-Delay']]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<div style="ANIMATION-DELAY: 0.8s">content</div>',
            ],
        ]);
    });

    it('keeps CSS custom property names case-sensitive', function (): void {
        $rule = new NoInlineStylesRule;
        $rule->setOptions(['allowedProperties' => ['--Theme']]);

        $this->getRuleTester()->run($rule, [
            'valid' => ['<div style="--Theme: red">content</div>'],
            'invalid' => [[
                'code' => '<div style="--theme: red">content</div>',
                'errors' => 1,
            ]],
        ]);
    });

    it('keeps the default behavior when allowedProperties is empty', function (): void {
        $rule = new NoInlineStylesRule;
        $rule->setOptions(['allowedProperties' => []]);

        $this->getRuleTester()->run($rule, [
            'invalid' => [
                [
                    'code' => '<div style="animation-delay: 0.8s">content</div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('still reports dynamic values even when the static parts are allowed', function (): void {
        $rule = new NoInlineStylesRule;
        $rule->setOptions(['allowedProperties' => ['animation-delay', 'width']]);

        $this->getRuleTester()->run($rule, [
            'invalid' => [
                [
                    'code' => '<div style="animation-delay: {{ $delay }}s">content</div>',
                    'errors' => 1,
                    'hasFix' => false,
                ],
                [
                    'code' => '<div style="width: {{ $w }}px">content</div>',
                    'errors' => 1,
                    'hasFix' => false,
                ],
            ],
        ]);
    });
});
