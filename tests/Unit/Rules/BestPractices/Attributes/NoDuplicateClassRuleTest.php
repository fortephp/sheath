<?php

declare(strict_types=1);

use Forte\Sheath\Rules\BestPractices\Attributes\NoDuplicateClassRule;

describe('NoDuplicateClassRule', function (): void {
    it('passes for elements with unique classes', function (): void {
        $this->getRuleTester()->run(new NoDuplicateClassRule, [
            'valid' => [
                '<div class="foo bar baz"></div>',
                '<div class="container mx-auto p-4"></div>',
                '<span class="text-red-500 font-bold"></span>',
                '<div class="Card card CARD"></div>',
                "<div class=\"foo\x0Bfoo\"></div>",
            ],
        ]);
    });

    it('passes for elements without class attribute', function (): void {
        $this->getRuleTester()->run(new NoDuplicateClassRule, [
            'valid' => [
                '<div></div>',
                '<span id="test"></span>',
            ],
        ]);
    });

    it('ignores later duplicate class attributes when checking class tokens', function (): void {
        $this->getRuleTester()->run(new NoDuplicateClassRule, [
            'valid' => [
                '<div class="a" @if($x) class="x x" @endif></div>',
            ],
        ]);
    });

    it('fails for elements with duplicate classes', function (): void {
        $this->getRuleTester()->run(new NoDuplicateClassRule, [
            'invalid' => [
                [
                    'code' => '<div class="foo bar foo"></div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<div class="container container"></div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('detects multiple different duplicates', function (): void {
        $this->getRuleTester()->run(new NoDuplicateClassRule, [
            'invalid' => [
                [
                    'code' => '<div class="foo bar foo bar"></div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('provides auto-fix to deduplicate classes', function (): void {
        $this->getRuleTester()->run(new NoDuplicateClassRule, [
            'invalid' => [
                [
                    'code' => '<div class="foo bar foo"></div>',
                    'errors' => [
                        [
                            'hasFixAvailable' => true,
                        ],
                    ],
                    'output' => '<div class="foo bar"></div>',
                ],
            ],
        ]);
    });

    it('preserves order when deduplicating', function (): void {
        $this->getRuleTester()->run(new NoDuplicateClassRule, [
            'invalid' => [
                [
                    'code' => '<div class="a b c b a"></div>',
                    'errors' => 1,
                    'output' => '<div class="a b c"></div>',
                ],
            ],
        ]);
    });

    it('preserves the quote style it found', function (): void {
        $this->getRuleTester()->run(new NoDuplicateClassRule, [
            'invalid' => [
                [
                    'code' => "<div class='foo bar foo'></div>",
                    'errors' => 1,
                    'output' => "<div class='foo bar'></div>",
                ],
            ],
        ]);
    });

    it('re-encodes character references that decode to the active quote', function (string $code, string $output): void {
        $this->getRuleTester()->run(new NoDuplicateClassRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
                'output' => $output,
            ]],
        ]);
    })->with([
        'double-quoted named reference' => [
            '<div class="foo foo &quot;bar"></div>',
            '<div class="foo &quot;bar"></div>',
        ],
        'single-quoted numeric reference' => [
            "<div class='foo foo &#39;bar'></div>",
            "<div class='foo &#39;bar'></div>",
        ],
        'ampersand is not decoded twice' => [
            '<div class="foo foo a&amp;copy"></div>',
            '<div class="foo a&amp;copy"></div>',
        ],
    ]);

    describe('Blade inside the class attribute', function (): void {
        it('does not treat echo delimiters as class names', function (): void {
            $this->getRuleTester()->run(new NoDuplicateClassRule, [
                'valid' => [
                    '<div class="{{ $a }} {{ $b }}"></div>',
                    '<div class="{{ $a }} card {{ $b }}"></div>',
                    '<div class="{!! $a !!} {!! $b !!}"></div>',
                    '<div class="absolute {{ $s[\'tick\'] }} border-t {{ $c[\'tick\'] }}"></div>',
                ],
            ]);
        });

        it('does not treat directive delimiters as class names', function (): void {
            $this->getRuleTester()->run(new NoDuplicateClassRule, [
                'valid' => [
                    '<div class="@class([\'a\' => $x]) @class([\'b\' => $y])"></div>',
                    '<div class="p-2 @if($a) m-2 @endif @if($b) g-2 @endif"></div>',
                ],
            ]);
        });

        it('ignores a name a Blade construct completes', function (): void {
            $this->getRuleTester()->run(new NoDuplicateClassRule, [
                'valid' => [
                    '<div class="btn-{{ $size }} btn-{{ $size }}"></div>',
                    '<div class="{{ $prefix }}-card {{ $prefix }}-card"></div>',
                ],
            ]);
        });

        it('does not read class names out of an expression', function (): void {
            $this->getRuleTester()->run(new NoDuplicateClassRule, [
                'valid' => [
                    '<div class="{{ $mobile ? \'text-xs font-mono\' : \'text-xs font-mono\' }}"></div>',
                ],
            ]);
        });

        it('still reports a genuine duplicate beside a Blade construct', function (): void {
            $this->getRuleTester()->run(new NoDuplicateClassRule, [
                'invalid' => [
                    [
                        'code' => '<div class="card {{ $modifier }} card"></div>',
                        'errors' => 1,
                    ],
                ],
            ]);
        });

        it('withholds the fix when the value is not static', function (): void {
            $this->getRuleTester()->run(new NoDuplicateClassRule, [
                'invalid' => [
                    [
                        'code' => '<div class="card {{ $modifier }} card"></div>',
                        'errors' => [
                            [
                                'hasFixAvailable' => false,
                            ],
                        ],
                    ],
                ],
            ]);
        });
    });
});
