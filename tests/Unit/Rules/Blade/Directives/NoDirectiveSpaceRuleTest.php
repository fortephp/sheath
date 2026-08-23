<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Directives\NoDirectiveSpaceRule;

describe('NoDirectiveSpaceRule', function (): void {
    it('passes for directives without space before arguments', function (): void {
        $this->getRuleTester()->run(new NoDirectiveSpaceRule, [
            'valid' => [
                '<x-input @checked($isChecked) />',
                '<x-input @disabled($isDisabled) />',
                '<x-input @readonly($isReadonly) />',
                '<x-input @required($isRequired) />',
                '<x-select @selected($isSelected) />',
                '<x-input @checked($a) @disabled($b) />',
            ],
        ]);
    });

    it('passes for directives without arguments', function (): void {
        $this->getRuleTester()->run(new NoDirectiveSpaceRule, [
            'valid' => [
                '<x-input @checked />',
                '<x-input @disabled />',
            ],
        ]);
    });

    it('passes for livewire and flux components', function (): void {
        $this->getRuleTester()->run(new NoDirectiveSpaceRule, [
            'valid' => [
                '<livewire:form @checked($val) />',
                '<flux:input @disabled($val) />',
            ],
        ]);
    });

    it('passes for non-component elements', function (): void {
        $this->getRuleTester()->run(new NoDirectiveSpaceRule, [
            'valid' => [
                '<div class="test"></div>',
                '<input type="text">',
            ],
        ]);
    });

    it('fails for space between directive name and arguments', function (): void {
        $this->getRuleTester()->run(new NoDirectiveSpaceRule, [
            'invalid' => [
                [
                    'code' => '<x-input @checked ($isChecked) />',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for multiple spaces', function (): void {
        $this->getRuleTester()->run(new NoDirectiveSpaceRule, [
            'invalid' => [
                [
                    'code' => '<x-input @checked  ($isChecked) />',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for different directives with space', function (): void {
        $this->getRuleTester()->run(new NoDirectiveSpaceRule, [
            'invalid' => [
                [
                    'code' => '<x-input @disabled ($isDisabled) />',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('detects multiple violations in one tag', function (): void {
        $this->getRuleTester()->run(new NoDirectiveSpaceRule, [
            'invalid' => [
                [
                    'code' => '<x-input @checked ($a) @disabled ($b) />',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('detects violations in livewire components', function (): void {
        $this->getRuleTester()->run(new NoDirectiveSpaceRule, [
            'invalid' => [
                [
                    'code' => '<livewire:form @checked ($val) />',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('detects violations in flux components', function (): void {
        $this->getRuleTester()->run(new NoDirectiveSpaceRule, [
            'invalid' => [
                [
                    'code' => '<flux:input @disabled ($val) />',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('detects violations in paired components', function (): void {
        $this->getRuleTester()->run(new NoDirectiveSpaceRule, [
            'invalid' => [
                [
                    'code' => '<x-input @checked ($val)>Content</x-input>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('provides auto-fix to remove the space', function (): void {
        $this->getRuleTester()->run(new NoDirectiveSpaceRule, [
            'invalid' => [
                [
                    'code' => '<x-input @checked ($isChecked) />',
                    'errors' => [
                        ['hasFixAvailable' => true],
                    ],
                    'output' => '<x-input @checked($isChecked) />',
                ],
                [
                    'code' => '<x-input @checked  ($isChecked) />',
                    'errors' => [
                        ['hasFixAvailable' => true],
                    ],
                    'output' => '<x-input @checked($isChecked) />',
                ],
                [
                    'code' => '<x-input @checked ($a) @disabled ($b) />',
                    'errors' => [
                        ['hasFixAvailable' => true],
                        ['hasFixAvailable' => true],
                    ],
                    'output' => '<x-input @checked($a) @disabled($b) />',
                ],
            ],
        ]);
    });
});
