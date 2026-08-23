<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Focus\NoAutofocusRule;

describe('NoAutofocusRule', function (): void {
    it('passes for elements without autofocus', function (): void {
        $this->getRuleTester()->run(new NoAutofocusRule, [
            'valid' => ['<input type="text" name="email">'],
        ]);
    });

    it('provides dangerous fix for removal', function (): void {
        $this->getRuleTester()->run(new NoAutofocusRule, [
            'invalid' => [
                [
                    'code' => '<input type="text" autofocus>',
                    'errors' => [
                        [
                            'hasDangerousFix' => true,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('reports dynamic autofocus values without offering a fix', function (): void {
        $this->getRuleTester()->run(new NoAutofocusRule, [
            'invalid' => [
                [
                    'code' => '<input type="text" autofocus="{{ $shouldFocus }}">',
                    'errors' => [
                        [
                            'hasFix' => false,
                        ],
                    ],
                ],
            ],
        ]);
    });
});
