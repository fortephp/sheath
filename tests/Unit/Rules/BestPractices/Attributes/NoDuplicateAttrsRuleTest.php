<?php

declare(strict_types=1);

use Forte\Sheath\Rules\BestPractices\Attributes\NoDuplicateAttrsRule;

describe('NoDuplicateAttrsRule', function (): void {
    it('passes for elements without duplicate attributes', function (): void {
        $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
            'valid' => [
                '<div class="container" id="main">Content</div>',
                '<img src="image.jpg" alt="Image" width="100" height="100">',
                '<a href="/page" title="Link" class="btn">Link</a>',
                '<input type="text" name="username" value="" placeholder="Username">',
            ],
        ]);
    });

    it('fails for elements with duplicate attributes', function (): void {
        $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
            'invalid' => [
                [
                    'code' => '<div class="foo" class="bar">Content</div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<img src="a.jpg" src="b.jpg" alt="Image">',
                    'errors' => 1,
                ],
                [
                    'code' => '<a href="/one" href="/two">Link</a>',
                    'errors' => 1,
                ],
                [
                    'code' => '<div id="first" id="second" x-bind:title="title">Content</div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('handles case insensitivity', function (): void {
        $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
            'invalid' => [
                [
                    'code' => '<div CLASS="foo" class="bar">Content</div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<div ID="foo" id="bar">Content</div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('detects multiple duplicates on same element', function (): void {
        $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
            'invalid' => [
                [
                    'code' => '<div class="a" class="b" id="x" id="y">Content</div>',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('checks each element independently', function (): void {
        $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
            'valid' => [
                '<div class="foo"></div><div class="bar"></div>',
            ],
            'invalid' => [
                [
                    'code' => '<div class="a" class="b"></div><span id="x" id="y"></span>',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('works with blade syntax', function (): void {
        $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
            'valid' => [
                '<div class="{{ $class }}" id="{{ $id }}">Content</div>',
            ],
            'invalid' => [
                [
                    'code' => '<div class="static" class="{{ $dynamic }}">Content</div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('detects triple or more duplicates', function (): void {
        $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
            'invalid' => [
                [
                    'code' => '<div class="a" class="b" class="c">Content</div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('offers no fix when the repeat carries a Blade expression', function (string $code): void {
        $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
            'invalid' => [
                [
                    'code' => $code,
                    'errors' => 1,
                    'hasFix' => false,
                ],
            ],
        ]);
    })->with([
        'echo is the whole value' => '<div data-x="{{ $a }}" data-x="{{ $b }}">d</div>',
        'echo inside the value' => '<div data-x="a" data-x="b-{{ $c }}">d</div>',
    ]);

    it('still fixes a repeat whose value is static', function (): void {
        $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
            'invalid' => [
                [
                    'code' => '<div data-x="a" data-x="b">d</div>',
                    'errors' => 1,
                    'hasFix' => true,
                ],
            ],
        ]);
    });

    it('withholds fixes unless the retained duplicate dominates the removed attribute', function (string $code): void {
        $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
                'hasFix' => false,
            ]],
        ]);
    })->with([
        'conditional first HTML attribute' => '<div @if($x) title="a" @endif title="b"></div>',
    ]);

    it('applies the mirrored dominance rule for component last-write semantics', function (): void {
        $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
            'invalid' => [[
                'code' => '<x-c title="a" @if($x) title="b" @endif />',
                'errors' => 1,
                'hasFix' => false,
            ]],
        ]);
    });

    it('does not invent duplicates across complementary independent blocks', function (): void {
        $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
            'valid' => ['<div @if($x) title="a" @endif @if(!$x) title="b" @endif></div>'],
        ]);
    });

    it('recognizes Blade directives that emit named attributes', function (string $code): void {
        $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
                'hasFix' => false,
            ]],
        ]);
    })->with([
        'style directive' => '<div @style([\'color: red\']) style="color: blue"></div>',
        'class directive' => '<div @class([\'notice\']) class="active"></div>',
    ]);

    it('does not treat a constant-false boolean directive as an emitted attribute', function (): void {
        $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
            'valid' => ['<div @disabled(false) disabled></div>'],
        ]);
    });

    it('respects unconditional loop exits while enumerating duplicate paths', function (): void {
        $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
            'valid' => [
                '<div @foreach($xs as $x) class="a" @break @endforeach></div>',
                '<div @foreach($xs as $x) @continue class="a" @endforeach class="a"></div>',
            ],
        ]);
    });

    describe('component tags', function (): void {
        it('fails for duplicate attributes on component tags', function (): void {
            $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
                'invalid' => [
                    [
                        'code' => '<x-button class="a" class="b">Save</x-button>',
                        'errors' => 1,
                        'output' => '<x-button class="b">Save</x-button>',
                    ],
                ],
            ]);
        });

        it('passes for unique attributes on component tags', function (): void {
            $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
                'valid' => [
                    '<x-button class="a" type="submit">Save</x-button>',
                    '<x-button :label="$label" label-position="top" />',
                    '<x-button foo="lower" FOO="upper" />',
                    '<x-button ::foo="literal" foo="plain" />',
                ],
            ]);
        });

        it('treats bound and static names as the same compiled key', function (): void {
            $this->getRuleTester()->run(new NoDuplicateAttrsRule, [
                'invalid' => [[
                    'code' => '<x-button :label="$label" label="fallback" />',
                    'errors' => 1,
                ]],
            ]);
        });
    });
});
