<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Performance\NoRenderBlockingResourcesRule;

describe('NoRenderBlockingResourcesRule', function (): void {
    it('passes for scripts with async', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'valid' => [
                '<head><script src="app.js" async></script></head>',
                '<head><script async src="analytics.js"></script></head>',
            ],
        ]);
    });

    it('passes for scripts with defer', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'valid' => [
                '<head><script src="app.js" defer></script></head>',
                '<head><script defer src="main.js"></script></head>',
            ],
        ]);
    });

    it('passes for type="module" scripts (deferred by default)', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'valid' => [
                '<head><script type="module" src="app.js"></script></head>',
                '<head><script type="MODULE" src="app.js"></script></head>',
                "<head><script type=\"\t module \n\" src=\"app.js\"></script></head>",
            ],
        ]);
    });

    it('reports scripts that explicitly opt back into render blocking', function (string $code): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [[

                    'hasFixAvailable' => false,
                ]],
            ]],
        ]);
    })->with([
        'module' => '<head><script type="module" blocking="render" src="app.js"></script></head>',
        'defer' => '<head><script defer blocking="render" src="app.js"></script></head>',
        'async' => '<head><script async blocking="render" src="app.js"></script></head>',
        'ASCII case-insensitive token' => '<head><script async blocking="other RENDER" src="app.js"></script></head>',
        'conditional render token' => '<head><script async @if($critical) blocking="render" @endif src="app.js"></script></head>',
    ]);

    it('ignores ineffective render-blocking tokens after the body starts', function (string $code): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, ['valid' => [$code]]);
    })->with([
        'defer' => '<html><body><script src="app.js" defer blocking="render"></script><p>after</p></body></html>',
        'async' => '<html><body><script src="app.js" async blocking="render"></script><p>after</p></body></html>',
        'module' => '<html><body><script src="app.js" type="module" blocking="render"></script><p>after</p></body></html>',
    ]);

    it('does not guess when the blocking token is dynamic', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'valid' => ['<head><script async blocking="{{ $blocking }}" src="app.js"></script></head>'],
        ]);
    });

    it('passes for inline scripts (no src)', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'valid' => [
                '<head><script>console.log("inline");</script></head>',
            ],
        ]);
    });

    it('treats present whitespace-only src attributes as external scripts', function (string $src): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'invalid' => [[
                'code' => "<head><script src=\"{$src}\"></script></head>",
                'errors' => [['hasDangerousFix' => true]],
            ]],
        ]);
    })->with([
        'space' => ' ',
        'vertical tab' => "\x0B",
    ]);

    it('passes for non-JavaScript script types', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'valid' => [
                '<head><script type="application/ld+json" src="data.json"></script></head>',
                '<head><script type="text/template" src="template.html"></script></head>',
                '<head><script type="text/javascript; charset=utf-8" src="data.txt"></script></head>',
                '<head><script type="application/ld+json" blocking="render" src="data.json"></script></head>',
            ],
        ]);
    });

    it('passes for scripts at the end of body', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'valid' => [
                '<body><script src="app.js"></script></body>',
                "<body><main>Content</main><script src=\"a.js\"></script>\n<!-- scripts stay last --><script src=\"b.js\"></script></body>",
            ],
        ]);
    });

    it('ignores non-output PHP and inert templates after trailing body scripts', function (string $code): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, ['valid' => [$code]]);
    })->with([
        'php expression directive' => '<body><main>Content</main><script src="app.js"></script>@php($loaded = true)</body>',
        'php block directive' => '<body><main>Content</main><script src="app.js"></script>@php $loaded = true; @endphp</body>',
        'php tag' => '<body><main>Content</main><script src="app.js"></script><?php $loaded = true; ?></body>',
        'template' => '<body><main>Content</main><script src="app.js"></script><template><p>Not rendered</p></template></body>',
    ]);

    it('still treats output-producing PHP after a script as rendered content', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'invalid' => [[
                'code' => '<body><script src="app.js"></script><?php echo $content; ?></body>',
                'errors' => 1,
            ]],
        ]);
    });

    it('stands down when script attributes or template rendering are opaque', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'valid' => [
                '<head><script src="app.js" {{ $attributes }}></script></head>',
                '<template><script src="app.js"></script></template>',
            ],
        ]);
    });

    it('ignores non-rendering Blade control syntax after trailing body scripts', function (string $code): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, ['valid' => [$code]]);
    })->with([
        'if' => '<body><main>Content</main>@if($load)<script src="app.js"></script>@endif</body>',
        'foreach' => '<body><main>Content</main>@foreach($scripts as $script)<script src="app.js"></script>@endforeach</body>',
        'switch' => '<body><main>Content</main>@switch($asset)@case(1)<script src="a.js"></script>@break @default<script src="b.js"></script>@endswitch</body>',
        'nested controls' => '<body><main>Content</main>@if($load)@foreach($scripts as $script)<script src="app.js"></script>@endforeach @endif</body>',
    ]);

    it('still reports a conditional script with rendered content after the control block', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'invalid' => [[
                'code' => '<body><main>Content</main>@if($load)<script src="app.js"></script>@endif<footer>Footer</footer></body>',
                'errors' => 1,
            ]],
        ]);
    });

    it('treats all scripts in a trailing script-only subtree as non-blocking', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'valid' => [
                '<body><main>Content</main><div><script src="a.js"></script><script src="b.js"></script></div></body>',
            ],
        ]);
    });

    it('fails for body scripts that block renderable content', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'invalid' => [
                [
                    'code' => '<body><script src="app.js"></script><main>Content</main></body>',
                    'errors' => 1,
                ],
                [
                    'code' => '<body><div>Content</div><script src="a.js"></script><footer>Footer</footer><script src="b.js"></script></body>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for external scripts in head without async/defer', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'invalid' => [
                [
                    'code' => '<head><script src="app.js"></script></head>',
                    'errors' => 1,
                ],
                [
                    'code' => "<head><script type=\"\t text/javascript \n\" src=\"app.js\"></script></head>",
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('provides a dangerous auto-fix to add defer', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'invalid' => [
                [
                    'code' => '<head><script src="main.js"></script></head>',
                    'errors' => [
                        [
                            'hasFixAvailable' => true,
                            'hasDangerousFix' => true,
                        ],
                    ],
                    'output' => '<head><script src="main.js" defer></script></head>',
                ],
            ],
        ]);
    });

    it('respects excludePatterns option', function (): void {
        $rule = new NoRenderBlockingResourcesRule;
        $rule->setOptions(['excludePatterns' => ['critical', 'polyfill']]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<head><script src="critical.js"></script></head>',
                '<head><script src="polyfill-loader.js"></script></head>',
            ],
        ]);
    });

    it('handles standard JavaScript MIME types case-insensitively', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'invalid' => [
                [
                    'code' => '<head><script type="text/javascript" src="app.js"></script></head>',
                    'errors' => 1,
                ],
                [
                    'code' => '<head><script type="Text/JavaScript" src="app.js"></script></head>',
                    'errors' => 1,
                ],
                [
                    'code' => '<head><script type="application/ecmascript" src="app.js"></script></head>',
                    'errors' => 1,
                ],
                [
                    'code' => '<head><script type="text/jscript" src="app.js"></script></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('finds scripts nested deeply in head', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'invalid' => [
                [
                    'code' => '<head><meta charset="UTF-8"><title>Test</title><script src="blocking.js"></script></head>',
                    'errors' => 1,
                ],
            ],
            'valid' => [
                '<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width"><title>Test</title><link rel="stylesheet" href="style.css"><script src="app.js" defer></script></head>',
            ],
        ]);
    });

    it('correctly identifies scripts in head vs body with sibling elements', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'valid' => [
                '<html><head><title>T</title></head><body><header><nav>Nav</nav></header><main><article>Content</article></main><script src="app.js"></script></body></html>',
            ],
            'invalid' => [
                [
                    'code' => '<head><meta charset="UTF-8"><meta name="description" content="Test"><link rel="icon" href="favicon.ico"><script src="blocking.js"></script><link rel="stylesheet" href="style.css"></head>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('handles multiple scripts at various depths in head', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'invalid' => [
                [
                    'code' => '<head><script src="first.js"></script><script src="second.js"></script></head>',
                    'errors' => 2,
                ],
            ],
            'valid' => [
                '<head><script src="analytics.js" async></script><script src="app.js" defer></script></head>',
            ],
        ]);
    });

    it('stands down for scripts inside non-output capture directives', function (string $code): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, ['valid' => [$code]]);
    })->with([
        'push' => "@push('scripts')<script src=\"/js/chart.js\"></script>@endpush",
        'prepend' => "@prepend('scripts')<script src=\"/js/first.js\"></script>@endprepend",
        'pushOnce' => "@pushOnce('scripts')<script src=\"/js/once.js\"></script>@endPushOnce",
        'prependOnce' => "@prependOnce('scripts')<script src=\"/js/once.js\"></script>@endPrependOnce",
        'conditional push' => "@pushif(\$enabled, 'scripts')<script src=\"/js/chart.js\"></script>@endpushif",
        'section' => "@section('scripts')<script src=\"/js/app.js\"></script>@endsection",
        'push inside a full page' => "<html><head></head><body>@push('scripts')<script src=\"/js/app.js\"></script>@endpush</body></html>",
    ]);

    it('still reports a section that renders at its definition site', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'invalid' => [[
                'code' => "<head>@section('scripts')<script src=\"/js/app.js\"></script>@show</head>",
                'errors' => [['hasDangerousFix' => true]],
            ]],
        ]);
    });

    it('still reports blocking scripts outside stacks in the same file', function (): void {
        $this->getRuleTester()->run(new NoRenderBlockingResourcesRule, [
            'invalid' => [
                [
                    'code' => "<head><script src=\"/js/blocking.js\"></script></head>@push('scripts')<script src=\"/js/pushed.js\"></script>@endpush",
                    'errors' => 1,
                ],
            ],
        ]);
    });
});
