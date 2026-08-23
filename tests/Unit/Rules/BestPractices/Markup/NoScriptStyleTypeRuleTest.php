<?php

declare(strict_types=1);

use Forte\Sheath\Rules\BestPractices\Markup\NoScriptStyleTypeRule;

describe('NoScriptStyleTypeRule', function (): void {
    it('passes for script/style without type', function (): void {
        $this->getRuleTester()->run(new NoScriptStyleTypeRule, [
            'valid' => [
                '<script src="app.js"></script>',
                '<script>console.log("hello")</script>',
                '<style>.foo { color: red; }</style>',
                '<style type=""></style>',
                '<style type="text/less"></style>',
            ],
        ]);
    });

    it('passes for non-default script types', function (): void {
        $this->getRuleTester()->run(new NoScriptStyleTypeRule, [
            'valid' => [
                '<script type="application/ld+json">{"@context":"https://schema.org"}</script>',
                '<script type="text/template"><div>Template</div></script>',
            ],
        ]);
    });

    it('fails for unnecessary script type', function (): void {
        $this->getRuleTester()->run(new NoScriptStyleTypeRule, [
            'invalid' => [
                [
                    'code' => '<script type="text/javascript">console.log("hello")</script>',
                    'errors' => 1,
                ],
                [
                    'code' => '<script type="application/javascript" src="app.js"></script>',
                    'errors' => 1,
                ],
                [
                    'code' => '<SCRIPT type="text/javascript"></SCRIPT>',
                    'errors' => 1,
                ],
                [
                    'code' => "<script type=\"\t text/javascript \n\"></script>",
                    'errors' => 1,
                ],
                [
                    'code' => '<script type="   "></script>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for unnecessary style type', function (): void {
        $this->getRuleTester()->run(new NoScriptStyleTypeRule, [
            'invalid' => [
                [
                    'code' => '<style type="text/css">.foo { color: red; }</style>',
                    'errors' => 1,
                ],
                [
                    'code' => '<style type=" text/css ">.foo { color: red; }</style>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('provides auto-fix to remove type attribute', function (): void {
        $this->getRuleTester()->run(new NoScriptStyleTypeRule, [
            'invalid' => [
                [
                    'code' => '<script type="text/javascript"></script>',
                    'errors' => [
                        [
                            'hasFixAvailable' => true,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('handles case insensitivity', function (): void {
        $this->getRuleTester()->run(new NoScriptStyleTypeRule, [
            'invalid' => [
                [
                    'code' => '<script type="TEXT/JAVASCRIPT"></script>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('recognizes every standard JavaScript MIME essence', function (): void {
        $this->getRuleTester()->run(new NoScriptStyleTypeRule, [
            'invalid' => [[
                'code' => '<script type="text/javascript1.5"></script>',
                'errors' => 1,
            ], [
                'code' => '<script type="application/x-javascript"></script>',
                'errors' => 1,
            ]],
        ]);
    });

    it('skips dynamic type attributes', function (): void {
        $this->getRuleTester()->run(new NoScriptStyleTypeRule, [
            'valid' => [
                '<script :type="scriptType"></script>',
                '<style :type="styleType"></style>',
                '<script type="{{ $type }}"></script>',
                '<style type="{{ $type }}"></style>',
                '<script type="{{ \'text/javascript\' }}"></script>',
            ],
        ]);
    });

    it('finds unnecessary type attributes in Blade attribute branches', function (): void {
        $this->getRuleTester()->run(new NoScriptStyleTypeRule, [
            'invalid' => [
                [
                    'code' => '<script @if($legacy) type="text/javascript" @endif></script>',
                    'errors' => [['hasFixAvailable' => true]],
                    'output' => '<script @if($legacy) @endif></script>',
                ],
                [
                    'code' => '<style @if($legacy) type="text/css" @endif></style>',
                    'errors' => [['hasFixAvailable' => true]],
                    'output' => '<style @if($legacy) @endif></style>',
                ],
            ],
        ]);
    });

    it('only checks the first type attribute emitted on each render path', function (): void {
        $this->getRuleTester()->run(new NoScriptStyleTypeRule, [
            'valid' => [
                '<script type="text/plain" @if($legacy) type="text/javascript" @endif></script>',
            ],
        ]);
    });
});
