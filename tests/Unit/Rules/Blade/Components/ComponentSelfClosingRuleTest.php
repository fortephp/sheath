<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Components\ComponentSelfClosingRule;

describe('ComponentSelfClosingRule', function (): void {
    it('passes for self-closing components', function (): void {
        $this->getRuleTester()->run(new ComponentSelfClosingRule, [
            'valid' => [
                '<x-icon name="home" />',
                '<x-button />',
                '<x-alert type="warning" />',
            ],
        ]);
    });

    it('passes for components with content', function (): void {
        $this->getRuleTester()->run(new ComponentSelfClosingRule, [
            'valid' => [
                '<x-button>Click me</x-button>',
                '<x-card><p>Content</p></x-card>',
                '<x-layout>@yield("content")</x-layout>',
            ],
        ]);
    });

    it('passes for regular HTML elements', function (): void {
        $this->getRuleTester()->run(new ComponentSelfClosingRule, [
            'valid' => [
                '<div></div>',
                '<span></span>',
            ],
        ]);
    });

    it('fails for empty x- components not self-closing', function (): void {
        $this->getRuleTester()->run(new ComponentSelfClosingRule, [
            'invalid' => [
                [
                    'code' => '<x-icon name="home"></x-icon>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('preserves components with whitespace-only slot content', function (): void {
        $this->getRuleTester()->run(new ComponentSelfClosingRule, [
            'valid' => [
                '<x-button>   </x-button>',
                "<x-button>\n</x-button>",
            ],
        ]);
    });

    it('provides auto-fix to make component self-closing', function (): void {
        $this->getRuleTester()->run(new ComponentSelfClosingRule, [
            'invalid' => [
                [
                    'code' => '<x-icon name="home"></x-icon>',
                    'errors' => [
                        [
                            'hasFixAvailable' => true,
                        ],
                    ],
                    'output' => '<x-icon name="home" />',
                ],
            ],
        ]);
    });

    it('does not remove whitespace-only slot content while applying safe fixes', function (): void {
        $code = '<x-button> </x-button>';

        expect($this->getRuleTester()->fix(new ComponentSelfClosingRule, $code))->toBe($code);
    });
});
