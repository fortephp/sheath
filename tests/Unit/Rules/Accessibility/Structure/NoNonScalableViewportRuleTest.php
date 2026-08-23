<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Structure\NoNonScalableViewportRule;

describe('NoNonScalableViewportRule', function (): void {
    it('passes for viewport without scaling restrictions', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'valid' => [
                '<meta name="viewport" content="width=device-width, initial-scale=1">',
                '<meta name="viewport" content="width=device-width">',
                '<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=5">',
                '<meta name="viewport" content="width=device-width, maximum-scale=2">',
                '<meta name="viewport" content="width=device-width, maximum-scale=-1">',
                '<meta name="viewport" content="width=device-width, maximum-scale={{ $maximumScale }}">',
                '<meta name="viewport" :content="$viewport">',
            ],
        ]);
    });

    it('passes for viewport with user-scalable=yes', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'valid' => [
                '<meta name="viewport" content="width=device-width, user-scalable=yes">',
                '<meta name="viewport" content="width=device-width, user-scalable=1">',
            ],
        ]);
    });

    it('passes for non-viewport meta tags', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'valid' => [
                '<meta name="description" content="user-scalable=no">',
                '<meta charset="utf-8">',
                "<meta name=\"\x0Bviewport\" content=\"user-scalable=no\">",
            ],
        ]);
    });

    it('fails for user-scalable=no', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [
                [
                    'code' => '<meta name="viewport" content="width=device-width, user-scalable=no">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for user-scalable=0', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [
                [
                    'code' => '<meta name="viewport" content="width=device-width, user-scalable=0">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('uses the viewport numeric and unknown-value conversion for user-scalable', function (string $value): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [[
                'code' => '<meta name="viewport" content="user-scalable='.$value.'">',
                'errors' => 1,
            ]],
        ]);
    })->with(['0.0', '0.5', '-0.5']);

    it('uses the viewport unknown-value conversion for user-scalable', function (string $content): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [[
                'code' => '<meta name="viewport" content="'.$content.'">',
                'errors' => 1,
            ]],
        ]);
    })->with([
        'unknown user-scalable' => 'user-scalable=invalid',
        'vertical tab before no' => "user-scalable=\x0Bno",
    ]);

    it('parses whitespace-separated viewport properties and applies last declaration wins', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'valid' => [
                '<meta name="viewport" content="maximum-scale=1 maximum-scale=5">',
                '<meta name="viewport" content="maximum-scale=1, maximum-scale=5">',
                '<meta name="viewport" content="user-scalable=no user-scalable=yes">',
                '<meta name="viewport" content="user-scalable=no; user-scalable=yes">',
                '<meta name="viewport" content="maximum-scale=device-width trailing">',
                '<meta name="viewport" content="user-scalable=device-width">',
                '<meta name="viewport" content="user-scalable=device-height">',
            ],
            'invalid' => [
                ['code' => '<meta name="viewport" content="width=device-width maximum-scale=1">', 'errors' => 1],
                ['code' => '<meta name="viewport" content="initial-scale=1 user-scalable=no">', 'errors' => 1],
                ['code' => '<meta name="viewport" content="maximum-scale=5 maximum-scale=1">', 'errors' => 1],
                ['code' => '<meta name="viewport" content="maximum-scale=5, maximum-scale=1">', 'errors' => 1],
                ['code' => '<meta name="viewport" content="user-scalable=yes user-scalable=no">', 'errors' => 1],
            ],
        ]);
    });

    it('removes every earlier occurrence of an effectively restrictive property', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [[
                'code' => '<meta name="viewport" content="width=device-width maximum-scale=1, maximum-scale=1">',
                'errors' => [['fix' => '<meta name="viewport" content="width=device-width">']],
                'output' => '<meta name="viewport" content="width=device-width">',
            ]],
        ]);
    });

    it('uses the viewport keyword and unknown-value conversion for maximum-scale', function (string $value): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [[
                'code' => '<meta name="viewport" content="maximum-scale='.$value.'">',
                'errors' => 1,
            ]],
        ]);
    })->with(['yes', 'no', 'invalid', "\x0B1"]);

    it('keeps device dimensions and drops negative maximum-scale values', function (string $value): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'valid' => ['<meta name="viewport" content="maximum-scale='.$value.'">'],
        ]);
    })->with(['device-width', 'device-height', '-1']);

    it('fails when maximum-scale prevents 200% zoom', function (string $scale): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [
                [
                    'code' => '<meta name="viewport" content="width=device-width, maximum-scale='.$scale.'">',
                    'errors' => 1,
                ],
            ],
        ]);
    })->with(['1', '1.0', '1.01', '1.5', '1.99', '1oops', '1.5junk']);

    it('accepts semicolons as viewport directive separators', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [[
                'code' => '<meta name="viewport" content="width=device-width; user-scalable=no">',
                'errors' => 1,
                'output' => '<meta name="viewport" content="width=device-width">',
            ]],
        ]);
    });

    it('reports both issues when present', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [
                [
                    'code' => '<meta name="viewport" content="width=device-width, user-scalable=no, maximum-scale=1">',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('is case insensitive', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [
                [
                    'code' => '<meta name="VIEWPORT" content="width=device-width, USER-SCALABLE=NO">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('provides fix to remove user-scalable=no', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [
                [
                    'code' => '<meta name="viewport" content="width=device-width, user-scalable=no">',
                    'errors' => [
                        [
                            'hasFixAvailable' => true,
                            'hasDangerousFix' => true,
                        ],
                    ],
                    'output' => '<meta name="viewport" content="width=device-width">',
                ],
            ],
        ]);
    });

    it('withholds zoom-interaction changes unless dangerous fixes are enabled', function (): void {
        $code = '<meta name="viewport" content="width=device-width, user-scalable=no">';
        $tester = $this->getRuleTester();

        expect($tester->fix(new NoNonScalableViewportRule, $code))->toBe($code)
            ->and($tester->fix(new NoNonScalableViewportRule, $code, dangerous: true))
            ->toBe('<meta name="viewport" content="width=device-width">');
    });

    it('provides fix to remove user-scalable=0', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [
                [
                    'code' => '<meta name="viewport" content="width=device-width, user-scalable=0">',
                    'errors' => [
                        [
                            'hasFixAvailable' => true,
                        ],
                    ],
                    'output' => '<meta name="viewport" content="width=device-width">',
                ],
            ],
        ]);
    });

    it('provides fix to remove maximum-scale=1', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [
                [
                    'code' => '<meta name="viewport" content="width=device-width, maximum-scale=1">',
                    'errors' => [
                        [
                            'hasFixAvailable' => true,
                        ],
                    ],
                    'output' => '<meta name="viewport" content="width=device-width">',
                ],
            ],
        ]);
    });

    it('provides fix to remove maximum-scale=1.0', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [
                [
                    'code' => '<meta name="viewport" content="width=device-width, maximum-scale=1.0">',
                    'errors' => [
                        [
                            'hasFixAvailable' => true,
                        ],
                    ],
                    'output' => '<meta name="viewport" content="width=device-width">',
                ],
            ],
        ]);
    });

    it('provides fixes for both issues when present', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [
                [
                    'code' => '<meta name="viewport" content="width=device-width, user-scalable=no, maximum-scale=1">',
                    'errors' => [
                        [
                            'hasFixAvailable' => true,
                        ],
                        [
                            'hasFixAvailable' => true,
                        ],
                    ],
                    'output' => '<meta name="viewport" content="width=device-width">',
                ],
            ],
        ]);
    });

    it('provides fix preserving other viewport settings', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [
                [
                    'code' => '<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no, shrink-to-fit=no">',
                    'errors' => [
                        [
                            'hasFixAvailable' => true,
                        ],
                    ],
                    'output' => '<meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">',
                ],
            ],
        ]);
    });

    it('provides fix with single quotes', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [
                [
                    'code' => "<meta name='viewport' content='width=device-width, user-scalable=no'>",
                    'errors' => [
                        [
                            'hasFixAvailable' => true,
                        ],
                    ],
                    'output' => "<meta name='viewport' content='width=device-width'>",
                ],
            ],
        ]);
    });

    it('checks restrictive viewport content in attribute render branches', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [[
                'code' => '<meta name="viewport" @if($x) content="width=device-width, maximum-scale=1" @else content="width=device-width" @endif>',
                'errors' => [[

                    'hasFixAvailable' => false,
                ]],
            ]],
        ]);
    });

    it('correlates viewport names and content on the same attribute path', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'valid' => [
                '<meta @if($x) name="viewport" content="width=device-width" @else name="theme-color" content="maximum-scale=1" @endif>',
            ],
        ]);
    });

    it('stands down when separate attribute conditionals require predicate correlation', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'valid' => [
                '<meta @if($x) name="viewport" @else name="theme-color" @endif @if($x) content="width=device-width" @else content="maximum-scale=1" @endif>',
            ],
        ]);
    });

    it('reports split attribute conditionals when every Cartesian path is restrictive', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [[
                'code' => '<meta @if($x) name="viewport" @else name="viewport" @endif @if($y) content="maximum-scale=1" @else content="user-scalable=no" @endif>',
                'errors' => 1,
            ]],
        ]);
    });
});

describe('viewport yes translation', function (): void {
    it('treats maximum-scale=yes as maximum-scale=1', function (): void {
        $this->getRuleTester()->run(new NoNonScalableViewportRule, [
            'invalid' => [[
                'code' => '<meta name="viewport" content="maximum-scale=YES">',
                'errors' => 1,
                'hasFix' => true,
            ]],
        ]);
    });
});
