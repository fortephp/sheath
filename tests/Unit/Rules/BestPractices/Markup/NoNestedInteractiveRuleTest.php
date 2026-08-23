<?php

declare(strict_types=1);

use Forte\Sheath\Rules\BestPractices\Markup\NoNestedInteractiveRule;

describe('NoNestedInteractiveRule', function (): void {
    it('treats invalid input type keywords as the default interactive text state', function (string $code): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'ASCII whitespace' => '<button><input type=" hidden "></button>',
        'vertical tab' => "<button><input type=\"\x0Bhidden\"></button>",
    ]);

    it('uses the first duplicate semantic attribute', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'valid' => [
                '<button><input type="hidden" @if($x) type="text" @endif></button>',
            ],
        ]);
    });

    it('evaluates conditional input types on every render path', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'valid' => ['<button><input @if($x) type="hidden" @else type="hidden" @endif></button>'],
            'invalid' => [[
                'code' => '<button><input @if($x) type="hidden" @endif></button>',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for non-nested interactive elements', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'valid' => [
                '<a href="#">Link</a>',
                '<button>Click me</button>',
                '<div><a href="#">Link</a><button>Button</button></div>',
                '<details><summary>Actions</summary><button type="button">Run</button></details>',
                '<div tabindex="0"><button type="button">Run</button></div>',
            ],
        ]);
    });

    it('fails for button inside anchor', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'invalid' => [
                [
                    'code' => '<a href="#"><button>Click</button></a>',
                    'errors' => 1,
                ],
                [
                    'code' => '<a><button>Click</button></a>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for anchor inside button', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'invalid' => [
                [
                    'code' => '<button><a href="#">Link</a></button>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('does not cross the inert template-content boundary', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'valid' => [
                '<button><template><a href="/x">x</a></template></button>',
                '<button><template><span tabindex="-1">x</span></template></button>',
                '<a href="/outer"><template><a>Placeholder</a></template></a>',
            ],
            'invalid' => [
                [
                    'code' => '<template><button><a href="/x">x</a></button></template>',
                    'errors' => 1,
                ],
                [
                    'code' => '<template><button><span tabindex="-1">x</span></button></template>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('does not cross a non-output Blade capture boundary', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'valid' => [
                '<a href="/">@section("slot")<button type="button">B</button>@endsection</a>',
                '<button>@push("slot")<a href="/">A</a>@endpush</button>',
                '<button>@pushif($enabled, "slot")<a href="/">A</a>@endpushif</button>',
            ],
            'invalid' => [[
                'code' => '<a href="/">@section("slot")<button type="button">B</button>@show</a>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for input inside anchor', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'invalid' => [
                [
                    'code' => '<a href="#"><input type="text"></a>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('passes for hidden input inside anchor', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'valid' => [
                '<a href="#"><input type="hidden" name="token"></a>',
            ],
        ]);
    });

    it('fails for select inside button', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'invalid' => [
                [
                    'code' => '<button><select><option>A</option></select></button>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('detects deeply nested interactive elements', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'invalid' => [
                [
                    'code' => '<a href="#"><div><span><button>Deep</button></span></div></a>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for anchor inside anchor', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'invalid' => [
                [
                    'code' => '<a href="/outer"><a href="/inner">Nested</a></a>',
                    'errors' => 1,
                ],
                [
                    'code' => '<a href="/outer"><a>Placeholder</a></a>',
                    'errors' => 1,
                ],
                [
                    'code' => '<a><a href="/inner">Nested</a></a>',
                    'errors' => 1,
                ],
                [
                    'code' => '<a><a>Placeholder</a></a>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('passes for a label wrapping the one control it labels', function (string $code): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, ['valid' => [$code]]);
    })->with([
        'label wrapping input' => '<label>Name <input type="text" name="name"></label>',
        'label wrapping select' => '<label>Country <select name="c"><option>USA</option></select></label>',
        'label wrapping textarea' => '<label>Message <textarea name="m"></textarea></label>',
        'label with for wrapping its control' => '<label for="name">Name <input type="text" id="name"></label>',
    ]);

    it('does not count hidden inputs as labelable descendants', function (string $code): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, ['valid' => [$code]]);
    })->with([
        'hidden input after labeled control' => '<label>Name <input name="name"><input type="hidden" name="token"></label>',
        'hidden input before labeled control' => '<label><input type="hidden" name="token">Name <input name="name"></label>',
        'multiple hidden inputs' => '<label>Name <input name="name"><input type="hidden"><input type="HIDDEN"></label>',
        'all conditional paths hidden' => '<label>Name <input name="name"><input @if($a) type="hidden" @else type="hidden" @endif></label>',
    ]);

    it('passes for a label wrapping any labelable interactive control', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'valid' => [
                '<label>Save <button>Go</button></label>',
                '<label>Progress <progress tabindex="0" value="1" max="2"></progress></label>',
                '<label>Result <output tabindex="0">42</output></label>',
            ],
        ]);
    });

    it('only allows a nested control that matches a static for attribute', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'valid' => [
                '<label for="save">Save <button id="save">Go</button></label>',
            ],
            'invalid' => [[
                'code' => '<label for="external">Save <button id="save">Go</button></label>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for a label wrapping two controls', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'invalid' => [
                [
                    'code' => '<label>Range <input type="text" name="from"> <input type="text" name="to"></label>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('rejects labelable descendants other than the labeled control', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'invalid' => [
                [
                    'code' => '<label>Name <input><output>result</output></label>',
                    'errors' => 1,
                ],
                [
                    'code' => '<label for="outside"><meter value="1"></meter></label><input id="outside">',
                    'errors' => 1,
                ],
                [
                    'code' => '<label><progress></progress><output>result</output></label>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('reports a dynamic for label containing multiple controls once at the label', function (string $code): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [[

                    'column' => 1,
                ]],
            ]],
        ]);
    })->with([
        'Alpine binding' => '<label :for="target">A<input id="a"> B<input id="b"></label>',
        'Blade interpolation' => '<label for="{{ $target }}">A<input id="a"> B<button id="b">B</button></label>',
        'non-interactive labelable controls' => '<label :for="target"><output>A</output><meter value="1"></meter></label>',
    ]);

    it('stands down for a dynamic for label containing one possible control', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'valid' => [
                '<label :for="target">Name <input id="name"></label>',
                '<label for="{{ $target }}">Save <button id="save">Go</button></label>',
            ],
        ]);
    });

    it('does not treat an href-less anchor as an interactive descendant', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'valid' => [
                '<button><a>Placeholder</a></button>',
            ],
        ]);
    });

    it('rejects any explicitly specified tabindex beneath buttons and interactive anchors', function (string $code): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'negative beneath button' => [
            '<button><span tabindex="-1">x</span></button>',
        ],
        'negative beneath anchor' => [
            '<a href="/page"><span tabindex="-2">x</span></a>',
        ],
        'negative beneath href-less anchor' => [
            '<a><span tabindex="-2">x</span></a>',
        ],
        'empty attribute' => [
            '<button><span tabindex>x</span></button>',
        ],
        'empty attribute beneath anchor' => [
            '<a href="/page"><span tabindex>x</span></a>',
        ],
        'malformed value' => [
            '<button><span tabindex="not-a-number">x</span></button>',
        ],
        'malformed value beneath anchor' => [
            '<a href="/page"><span tabindex="not-a-number">x</span></a>',
            'Element <span> with a tabindex attribute must not be nested inside <a>.',
        ],
        'dynamic value beneath button' => [
            '<button><span tabindex="{{ $index }}">x</span></button>',
            'Element <span> with a tabindex attribute must not be nested inside <button>.',
        ],
        'dynamic value' => [
            '<a href="/page"><span tabindex="{{ $index }}">x</span></a>',
            'Element <span> with a tabindex attribute must not be nested inside <a>.',
        ],
        'conditional attribute' => [
            '<button><span @if($focusable) tabindex="-1" @endif>x</span></button>',
            'Element <span> with a tabindex attribute must not be nested inside <button>.',
        ],
        'conditional attribute beneath anchor' => [
            '<a href="/page"><span @if($focusable) tabindex="-1" @endif>x</span></a>',
            'Element <span> with a tabindex attribute must not be nested inside <a>.',
        ],
        'duplicate attribute whose first value is negative' => [
            '<button><span tabindex="-1" tabindex="0">x</span></button>',
            'Element <span> with a tabindex attribute must not be nested inside <button>.',
        ],
    ]);

    it('does not promote a negative tabindex element to a general interactive ancestor', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'valid' => ['<div tabindex="-1"><button>Go</button></div>'],
        ]);
    });

    it('still treats zero and positive tabindex as interactive', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'invalid' => [
                [
                    'code' => '<button><span tabindex="0">x</span></button>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('uses the browser integer prefix when checking tabindex', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'invalid' => [[
                'code' => '<button><span tabindex="1.5">Nested</span></button>',
                'errors' => 1,
            ]],
        ]);
    });

    it('reports each nested control only against its nearest interactive ancestor', function (): void {
        $this->getRuleTester()->run(new NoNestedInteractiveRule, [
            'invalid' => [[
                'code' => '<a href="#"><button><a href="/inner">Inner</a></button></a>',
                'errors' => 2,
            ]],
        ]);
    });
});
