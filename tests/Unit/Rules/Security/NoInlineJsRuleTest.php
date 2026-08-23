<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Security\NoInlineJsRule;

describe('NoInlineJsRule', function (): void {
    it('passes for elements without inline JS handlers', function (): void {
        $this->getRuleTester()->run(new NoInlineJsRule, [
            'valid' => [
                '<button type="button">Click me</button>',
                '<div class="container">Content</div>',
                '<a href="/path">Link</a>',
                '<form action="/submit" method="POST"></form>',
                '<input type="text" name="email">',
            ],
        ]);
    });

    it('fails for onclick handler', function (): void {
        $this->getRuleTester()->run(new NoInlineJsRule, [
            'invalid' => [
                [
                    'code' => '<button onclick="doSomething()">Click</button>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for various JS event handlers', function (): void {
        $this->getRuleTester()->run(new NoInlineJsRule, [
            'invalid' => [
                [
                    'code' => '<div onmouseover="highlight()">Hover me</div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<form onsubmit="return validate()">',
                    'errors' => 1,
                ],
                [
                    'code' => '<input onchange="updateValue()">',
                    'errors' => 1,
                ],
                [
                    'code' => '<body onload="init()">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('covers current standard event handler attributes', function (string $handler): void {
        $this->getRuleTester()->run(new NoInlineJsRule, [
            'invalid' => [[
                'code' => "<div {$handler}=\"run()\"></div>",
                'errors' => 1,
            ]],
        ]);
    })->with([
        'before input' => 'onbeforeinput',
        'before toggle' => 'onbeforetoggle',
        'cancel' => 'oncancel',
        'close' => 'onclose',
        'command' => 'oncommand',
        'context lost' => 'oncontextlost',
        'load start' => 'onloadstart',
        'playing' => 'onplaying',
        'progress' => 'onprogress',
        'scroll end' => 'onscrollend',
        'window message error' => 'onmessageerror',
        'window unhandled rejection' => 'onunhandledrejection',
    ]);

    it('covers handlers defined across current web standards', function (string $handler): void {
        $this->getRuleTester()->run(new NoInlineJsRule, [
            'invalid' => [[
                'code' => "<div {$handler}=\"run()\"></div>",
                'errors' => 1,
            ]],
        ]);
    })->with([
        'webkit animation' => 'onwebkitanimationend',
        'raw pointer update' => 'onpointerrawupdate',
        'fullscreen' => 'onfullscreenchange',
        'scroll snap' => 'onscrollsnapchange',
        'before copy' => 'onbeforecopy',
        'before cut' => 'onbeforecut',
        'before paste' => 'onbeforepaste',
        'legacy mouse wheel' => 'onmousewheel',
        'webkit fullscreen change' => 'onwebkitfullscreenchange',
        'webkit fullscreen error' => 'onwebkitfullscreenerror',
        'content visibility auto state change' => 'oncontentvisibilityautostatechange',
        'WebXR before select' => 'onbeforexrselect',
        'SVG animation begin' => 'onbegin',
        'SVG animation end' => 'onend',
        'SVG animation repeat' => 'onrepeat',
    ]);

    it('detects multiple handlers on same element', function (): void {
        $this->getRuleTester()->run(new NoInlineJsRule, [
            'invalid' => [
                [
                    'code' => '<button onclick="click()" onmouseover="hover()">Button</button>',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('allows whitelisted handlers', function (): void {
        $rule = new NoInlineJsRule;
        $rule->setOptions(['allowed' => ['onclick']]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<button onclick="doSomething()">Click</button>',
            ],
            'invalid' => [
                [
                    'code' => '<div onmouseover="highlight()">Hover</div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('matches allowed handlers case-insensitively', function (): void {
        $rule = new NoInlineJsRule;
        $rule->setOptions(['allowed' => [' onClick ']]);

        $this->getRuleTester()->run($rule, [
            'valid' => ['<button ONCLICK="save()">Save</button>'],
        ]);
    });

    it('handles case-insensitive attribute names', function (): void {
        $this->getRuleTester()->run(new NoInlineJsRule, [
            'invalid' => [
                [
                    'code' => '<button ONCLICK="doSomething()">Click</button>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('provides dangerous fix for removal', function (): void {
        $this->getRuleTester()->run(new NoInlineJsRule, [
            'invalid' => [
                [
                    'code' => '<button onclick="doSomething()">Click</button>',
                    'errors' => [
                        [
                            'hasDangerousFix' => true,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('fixes single inline JS handler correctly', function (): void {
        $this->getRuleTester()->run(new NoInlineJsRule, [
            'invalid' => [
                [
                    'code' => '<button onclick="doSomething()">Click</button>',
                    'errors' => [
                        ['hasDangerousFix' => true],
                    ],
                    'output' => '<button>Click</button>',
                ],
            ],
        ]);
    });

    it('fixes multiple inline JS handlers on same element', function (): void {
        $this->getRuleTester()->run(new NoInlineJsRule, [
            'invalid' => [
                [
                    'code' => '<button onclick="click()" onmouseover="hover()">Button</button>',
                    'errors' => [
                        ['hasDangerousFix' => true],
                        ['hasDangerousFix' => true],
                    ],
                    'output' => '<button>Button</button>',
                ],
            ],
        ]);
    });

    it('fixes inline JS handlers on multiple elements', function (): void {
        $this->getRuleTester()->run(new NoInlineJsRule, [
            'invalid' => [
                [
                    'code' => '<button onclick="a()">A</button><button onclick="b()">B</button>',
                    'errors' => [
                        ['hasDangerousFix' => true],
                        ['hasDangerousFix' => true],
                    ],
                    'output' => '<button>A</button><button>B</button>',
                ],
            ],
        ]);
    });

    it('reports dynamic handler values without offering a fix', function (): void {
        $this->getRuleTester()->run(new NoInlineJsRule, [
            'invalid' => [
                [
                    'code' => '<button onclick="doThing({{ $id }})">Click</button>',
                    'errors' => [
                        [
                            'hasFix' => false,
                        ],
                    ],
                ],
                [
                    'code' => '<form onsubmit="track(\'{{ $slug }}\')">',
                    'errors' => [
                        [
                            'hasFix' => false,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('guards each handler separately when only one is dynamic', function (): void {
        $this->getRuleTester()->run(new NoInlineJsRule, [
            'invalid' => [
                [
                    'code' => '<button onclick="save({{ $id }})" onmouseover="hover()">Button</button>',
                    'errors' => [
                        [
                            'hasFix' => false,
                        ],
                        [
                            'hasDangerousFix' => true,
                        ],
                    ],
                    'output' => '<button onclick="save({{ $id }})">Button</button>',
                ],
            ],
        ]);
    });

    it('fixes three inline JS handlers on same element', function (): void {
        $this->getRuleTester()->run(new NoInlineJsRule, [
            'invalid' => [
                [
                    'code' => '<button onclick="a()" onmouseover="b()" onfocus="c()">X</button>',
                    'errors' => [
                        ['hasDangerousFix' => true],
                        ['hasDangerousFix' => true],
                        ['hasDangerousFix' => true],
                    ],
                    'output' => '<button>X</button>',
                ],
            ],
        ]);
    });
});
