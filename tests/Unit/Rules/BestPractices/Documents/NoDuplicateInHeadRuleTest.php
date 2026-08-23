<?php

declare(strict_types=1);

use Forte\Sheath\Rules\BestPractices\Documents\NoDuplicateInHeadRule;

describe('NoDuplicateInHeadRule', function (): void {
    it('uses an explicit meta name before a later opaque provider', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'valid' => ['<head><meta {{ $attributes }} name="description"><meta name="description"></head>'],
            'invalid' => [[
                'code' => '<head><meta name="description" {{ $attributes }}><meta name="description"></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for single title tag', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'valid' => [
                '<head><title>Page Title</title></head>',
            ],
        ]);
    });

    it('passes for single charset meta', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'valid' => [
                '<head><meta charset="utf-8"></head>',
                '<head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>',
                '<head><meta charset="utf-8"><meta http-equiv="Content-Type" content="text/html"></head>',
            ],
        ]);
    });

    it('recognizes exhaustive conditional charset attributes as one declaration', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'invalid' => [[
                'code' => '<head><meta @if($x) charset="utf-8" @else charset="utf-8" @endif><meta charset="utf-8"></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for single unique meta names', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'valid' => [
                '<head><meta name="viewport" content="width=device-width"></head>',
                '<head><meta name="description" content="Page description"></head>',
                '<head><meta name="viewport" content="width=device-width"><meta name="description" content="Page description"></head>',
            ],
        ]);
    });

    it('allows theme-color declarations with distinct media values', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'valid' => [
                '<head><meta name="theme-color" content="#fff" media="(prefers-color-scheme: light)"><meta name="theme-color" content="#000" media="(prefers-color-scheme: dark)"></head>',
                '<head><meta name="theme-color" content="#fff" media="SCREEN"><meta name="theme-color" content="#000" media="screen"></head>',
                '<head><meta name="theme-color" content="#fff"><meta name="theme-color" content="#000" media=""></head>',
            ],
            'invalid' => [[
                'code' => '<head><meta name="theme-color" content="#fff" media="(prefers-color-scheme: light)"><meta name="theme-color" content="#eee" media="(prefers-color-scheme: light)"></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('does not trim non-HTML whitespace from meta names', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'valid' => [
                "<head><meta name=\"description\" content=\"a\"><meta name=\"\x0Bdescription\" content=\"b\"></head>",
            ],
        ]);
    });

    it('does not count declarations captured for later output at their definition site', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'valid' => [
                '<head>@push("x")<title>A</title>@endpush<title>B</title></head>',
                '<head>@pushIf($enabled, "x")<title>A</title>@endPushIf<title>B</title></head>',
            ],
        ]);
    });

    it('allows repeatable Open Graph and Twitter image tags', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'valid' => [
                '<head><meta property="og:image" content="a"><meta property="og:image" content="b"><meta name="twitter:image" content="a"><meta name="twitter:image" content="b"></head>',
            ],
        ]);
    });

    it('passes for documents without head', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'valid' => [
                '<div>Content</div>',
            ],
        ]);
    });

    it('fails for duplicate title tags', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'invalid' => [
                [
                    'code' => '<head><title>First</title><title>Second</title></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for multiple duplicate title tags', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'invalid' => [
                [
                    'code' => '<head><title>First</title><title>Second</title><title>Third</title></head>',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('fails for duplicate charset metas', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'invalid' => [
                [
                    'code' => '<head><meta charset="utf-8"><meta charset="iso-8859-1"></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('counts malformed declarations as duplicate attempts without treating them as valid encodings', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'invalid' => [[
                'code' => '<head><meta charset="utf-8"><meta http-equiv="content-type" content="application/json; charset = bogus"></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for duplicate viewport metas', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'invalid' => [
                [
                    'code' => '<head><meta name="viewport" content="width=device-width"><meta name="viewport" content="initial-scale=1"></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for duplicate description metas', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'invalid' => [
                [
                    'code' => '<head><meta name="description" content="First"><meta name="description" content="Second"></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('lets projects replace the meta names that must be unique', function (): void {
        $rule = new NoDuplicateInHeadRule;
        $rule->setOptions(['uniqueMetaNames' => ['application-name']]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<head><meta name="description" content="First"><meta name="description" content="Second"></head>',
            ],
            'invalid' => [[
                'code' => '<head><meta name="application-name" content="One"><meta name="application-name" content="Two"></head>',
                'errors' => 1,
            ]],
        ]);
    });

    it('reports multiple types of duplicates', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'invalid' => [
                [
                    'code' => '<head><title>A</title><title>B</title><meta charset="utf-8"><meta charset="utf-8"></head>',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('reports unique head elements that a Blade loop may repeat', function (string $code): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'invalid' => [[
                'code' => '<head>'.$code.'</head>',
                'errors' => 1,
            ]],
        ]);
    })->with([
        'title in foreach' => [
            '@foreach($items as $item)<title>Item</title>@endforeach',
        ],
        'charset in for' => [
            '@for($i = 0; $i < 2; $i++)<meta charset="utf-8">@endfor',
        ],
        'viewport in while' => [
            '@while($item)<meta name="viewport" content="width=device-width">@endwhile',
        ],
        'description in forelse body' => [
            '@forelse($items as $item)<meta name="description" content="Item">@empty<title>Empty</title>@endforelse',
        ],
    ]);

    it('does not treat a unique head element in the forelse empty arm as repeating', function (): void {
        $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
            'valid' => [
                '<head>@forelse($items as $item)<meta name="theme" content="item">@empty<title>Empty</title>@endforelse</head>',
            ],
        ]);
    });

    describe('conditional branches', function (): void {
        it('passes for head elements on mutually exclusive branches', function (string $code): void {
            $this->getRuleTester()->run(new NoDuplicateInHeadRule, ['valid' => [$code]]);
        })->with([
            'title per if/else arm' => '<head>@if($seo)<title>{{ $seoTitle }}</title>@else<title>Default</title>@endif</head>',
            'title per if/elseif/else arm' => '<head>@if($a)<title>A</title>@elseif($b)<title>B</title>@else<title>C</title>@endif</head>',
            'description meta per arm' => '<head><title>x</title>@if($page)<meta name="description" content="a">@else<meta name="description" content="b">@endif</head>',
        ]);

        it('fails for duplicates within one branch', function (): void {
            $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
                'invalid' => [
                    [
                        'code' => '<head>@if($x)<title>A</title><title>B</title>@endif</head>',
                        'errors' => 1,
                    ],
                ],
            ]);
        });

        it('fails when duplicate head elements can render through switch fallthrough', function (): void {
            $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
                'invalid' => [[
                    'code' => '<head>@switch($x)@case(1)<title>A</title>@case(2)<title>B</title>@break @endswitch</head>',
                    'errors' => 1,
                ]],
            ]);
        });

        it('fails when a branch duplicate joins an unconditional element', function (): void {
            $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
                'invalid' => [
                    [
                        'code' => '<head><title>Always</title>@if($x)<title>Sometimes</title>@endif</head>',
                        'errors' => 1,
                    ],
                ],
            ]);
        });

        it('finds a duplicate selected by an attribute render branch', function (): void {
            $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
                'invalid' => [[
                    'code' => '<head><meta name="description" content="a"><meta @if($x) name="description" @else name="author" @endif content="b"></head>',
                    'errors' => 1,
                ]],
            ]);
        });

        it('does not treat unrelated conditional attributes as meta classification controls', function (): void {
            $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
                'invalid' => [[
                    'code' => '<head><meta name="description" @if($x) media="screen" @endif><meta @if($y) name="description" @else name="author" @endif></head>',
                    'errors' => 1,
                ]],
            ]);
        });

        it('does not combine mutually exclusive names on one meta element', function (): void {
            $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
                'valid' => [
                    '<head><meta @if($x) name="description" @else name="author" @endif content="a"></head>',
                ],
            ]);
        });

        it('stands down for matching metas controlled by separate conditional blocks', function (): void {
            $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
                'valid' => [
                    '<head>@if($x)<meta name="description" content="x">@endif @if(!$x)<meta name="description" content="y">@endif</head>',
                    '<head>@if($x)<meta charset="utf-8">@endif @if(!$x)<meta charset="utf-8">@endif</head>',
                    '<head><meta @if($x) name="description" @else name="viewport" @endif><meta @if($x) name="viewport" @else name="description" @endif></head>',
                    '<head>@if($x)@if($a)<meta name="description">@endif @endif @if(!$x)@if($a)<meta name="description">@endif @endif</head>',
                    '<head>@if($x)@if($a)<meta charset="utf-8">@endif @endif @if(!$x)@if($a)<meta charset="utf-8">@endif @endif</head>',
                    '<head><meta @if($x) @if($a) name="description" @endif @endif><meta @if($x) @if(!$a) name="description" @endif @endif></head>',
                ],
            ]);
        });

        it('allows titles controlled by complementary predicates across separate blocks', function (): void {
            $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
                'valid' => [
                    '<head>@if($x)<title>A</title>@endif @if(!$x)<title>B</title>@endif</head>',
                    '<head>@if($x)@if($a)<title>A</title>@endif @endif @if(!$x)@if($a)<title>B</title>@endif @endif</head>',
                ],
            ]);
        });

        it('reports matching metas controlled by the same predicate in separate blocks', function (): void {
            $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
                'invalid' => [
                    [
                        'code' => '<head>@if($x)<meta charset="utf-8">@endif @if($x)<meta charset="utf-8">@endif</head>',
                        'errors' => 1,
                    ],
                    [
                        'code' => '<head>@if($x)<meta name="description">@endif @if($x)<meta name="description">@endif</head>',
                        'errors' => 1,
                    ],
                    [
                        'code' => '<head><meta @if($x) name="description" @else name="viewport" @endif><meta @if($x) name="description" @else name="author" @endif></head>',
                        'errors' => 1,
                    ],
                    [
                        'code' => '<head>@if($x)<meta name="description">@endif @if($y)<meta name="description">@endif</head>',
                        'errors' => 1,
                    ],
                    [
                        'code' => '<head><meta @if($x) @if($a) name="description" @endif @endif><meta @if($x) @if($a) name="description" @endif @endif></head>',
                        'errors' => 1,
                    ],
                ],
            ]);
        });

        it('reports titles controlled by the same predicate in separate blocks', function (): void {
            $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
                'invalid' => [[
                    'code' => '<head>@if($x)<title>A</title>@endif @if($x)<title>B</title>@endif</head>',
                    'errors' => 1,
                ]],
            ]);
        });
    });
});
