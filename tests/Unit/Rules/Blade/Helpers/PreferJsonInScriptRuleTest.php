<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Helpers\PreferJsonInScriptRule;

describe('PreferJsonInScriptRule', function (): void {
    it('fails for echoes inside JS string and template literals', function (string $code): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'single-quoted string' => "<script>var name = '{{ \$name }}';</script>",
        'double-quoted string' => '<script>var name = "{{ $name }}";</script>',
        'template literal' => '<script>var name = `{{ $name }}`;</script>',
    ]);

    it('fails for echoes inside JS comments', function (string $code): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'line comment' => "<script>// prints {{ \$name }}\nvar x = 1;</script>",
        'block comment' => "<script>/* uses {{ \$name }} */\nvar x = 1;</script>",
    ]);

    it('passes for Js::from and friends', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'valid' => [
                '<script>var data = {{ Js::from($data) }};</script>',
                '<script>var data = {{ \Illuminate\Support\Js::from($data) }};</script>',
                '<script>var data = {{ js::from($data) }};</script>',
                '<script>var data = {{ Illuminate\\Support\\Js :: from($data) }};</script>',
                '<script>var data = {{ \\ILLUMINATE\\SUPPORT\\JS/* legal */::from($data) }};</script>',
            ],
        ]);
    });

    it('rejects escaped Js::encode because Blade entity-escapes its string result', function (string $code): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'executable JavaScript' => '<script>var data = {{ Js::encode($data) }};</script>',
        'JSON text' => '<script type="application/json">{{ Js::encode($data) }}</script>',
    ]);

    it('does not accept lookalike classes or instance calls', function (string $expression): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'invalid' => [[
                'code' => "<script>var data = {{ {$expression} }};</script>",
                'errors' => 1,
            ]],
        ]);
    })->with([
        'lookalike namespace' => 'App\\Js::from($data)',
        'lookalike class' => 'SafeJs::from($data)',
        'instance call' => '$js->from($data)',
    ]);

    it('requires the safe serializer call to be the complete echo expression', function (string $expression): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'invalid' => [[
                'code' => "<script>const value = {{ {$expression} }};</script>",
                'errors' => 1,
            ]],
        ]);
    })->with([
        'concatenated suffix' => 'Js::from($data) . "tail"',
        'conditional suffix' => 'Js::from($data) ?: "fallback"',
        'unrecognized static method' => 'Js::escape($data)',
    ]);

    it('passes for @json and raw echoes', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'valid' => [
                '<script>var data = @json($data);</script>',
                '<script>var data = {!! $data !!};</script>',
            ],
        ]);
    });

    it('passes for echoes outside script elements', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'valid' => [
                '<div>{{ $data }}</div>',
                '<script src="{{ asset(\'app.js\') }}"></script>',
            ],
        ]);
    });

    it('passes for non-JS script templates', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'valid' => [
                '<script type="text/x-template"><p>{{ $name }}</p></script>',
                '<script type="text/html"><p>{{ $name }}</p></script>',
            ],
        ]);
    });

    it('classifies scripts by the first type attribute emitted on each render path', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'valid' => [
                '<script type="text/plain" @if($legacy) type="text/javascript" @endif>const x = {{ $data }};</script>',
            ],
        ]);
    });

    it('strips HTML ASCII whitespace before classifying the script type', function (string $type): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'invalid' => [[
                'code' => "<script type=\"{$type}\">var data = {{ \$data }};</script>",
                'errors' => 1,
            ]],
        ]);
    })->with([
        'classic script' => "\t text/javascript \n",
        'module script' => "\r module \f",
        'empty type' => "\t \n",
    ]);

    it('fails for an echo in a JS value position', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'invalid' => [
                [
                    'code' => '<script>var data = {{ $data }};</script>',
                    'errors' => [
                        [
                            'line' => 1,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('fails for an echo after a closed string on the same line', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'invalid' => [
                [
                    'code' => "<script>var s = 'x'; var t = {{ \$a }};</script>",
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });

    it('fails inside JSON script blocks', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'invalid' => [
                [
                    'code' => '<script type="application/json">{"k": {{ $v }}}</script>',
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });

    it('requires JSON text rather than Js::from expressions in JSON-only script types', function (string $type): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'valid' => [
                "<script type=\"{$type}\">@json(\$data)</script>",
            ],
            'invalid' => [[
                'code' => "<script type=\"{$type}\">{{ Js::from(\$data) }}</script>",
                'errors' => 1,
            ]],
        ]);
    })->with([
        'application JSON' => 'application/json',
        'JSON-LD' => 'application/ld+json',
        'import map' => 'importmap',
        'speculation rules' => 'speculationrules',
    ]);

    it('allows Js::from when a static render path is not JSON-only', function (string $alternateType): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'valid' => [
                "<script @if(\$json) type=\"application/ld+json\" @else type=\"{$alternateType}\" @endif>@if(\$json) @json(\$data) @else {{ Js::from(\$data) }} @endif</script>",
            ],
        ]);
    })->with([
        'executable JavaScript path' => 'text/javascript',
        'inert script path' => 'text/plain',
    ]);

    it('stands down when an alternate script type is dynamic', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'valid' => [
                '<script @if($json) type="application/json" @else type="{{ $type }}" @endif>@if($json) @json($data) @else {{ Js::from($data) }} @endif</script>',
            ],
        ]);
    });

    it('stands down for ordinary echoes when a JSON path alternates with an inert path', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'valid' => [
                '<script @if($json) type="application/json" @else type="text/plain" @endif>@if($json) @json($data) @else {{ $plainText }} @endif</script>',
            ],
        ]);
    });

    it('checks a common echo when an inert conditional type leaves an executable path', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'invalid' => [[
                'code' => '<script @if($template) type="text/x-template" @endif>{{ $payload }}</script>',
                'errors' => [['line' => 1]],
            ]],
        ]);
    });

    it('checks ordinary echoes when JSON alternates with executable JavaScript', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'invalid' => [[
                'code' => '<script @if($json) type="application/json" @else type="text/javascript" @endif>{{ $data }}</script>',
                'errors' => [['line' => 1]],
            ]],
        ]);
    });

    it('rejects an unconditional Js::from expression that reaches a JSON path', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'invalid' => [[
                'code' => '<script @if($json) type="application/json" @else type="text/javascript" @endif>{{ Js::from($data) }}</script>',
                'errors' => [['line' => 1]],
            ]],
        ]);
    });

    it('rejects conditional unsafe echoes when their predicate is unrelated to the type predicate', function (string $code): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [['line' => 1]],
            ]],
        ]);
    })->with([
        'Js::from reaches JSON' => '<script @if($json) type="application/json" @else type="text/javascript" @endif>@if($show) {{ Js::from($data) }} @endif</script>',
        'escaped echo reaches executable JavaScript' => '<script @if($template) type="text/x-template" @endif>@if($show){{ $payload }}@endif</script>',
    ]);

    it('uses full predicate chains and branch polarity to classify conditional echoes', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'valid' => [
                '<script @if($json) type="application/json" @else type="text/javascript" @endif>@if($json)@json($data)@else @if($show){{ Js::from($data) }}@endif @endif</script>',
            ],
            'invalid' => [[
                'code' => '<script @if($json) type="application/json" @else type="text/javascript" @endif>@if($json){{ Js::from($data) }}@else{{ Js::encode($data) }}@endif</script>',
                'errors' => [['line' => 1], ['line' => 1]],
            ], [
                'code' => '<script @if($template) type="text/x-template" @endif>@unless($template){{ $payload }}@endunless</script>',
                'errors' => [['line' => 1]],
            ]],
        ]);
    });

    it('checks legacy JavaScript MIME essence strings', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'invalid' => [[
                'code' => '<script type="text/javascript1.3">const data = {{ $data }};</script>',
                'errors' => [['line' => 1]],
            ]],
        ]);
    });

    it('fails on later lines of a multi-line script', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'invalid' => [
                [
                    'code' => "<script>\nconst config = {\n    items: {{ \$items }},\n};\n</script>",
                    'errors' => [
                        ['line' => 3],
                    ],
                ],
            ],
        ]);
    });

    it('finds value-position echoes nested inside Blade directives', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'valid' => [
                '<script>@if($ok) const value = {{ Js::from($value) }}; @endif</script>',
            ],
            'invalid' => [
                [
                    'code' => '<script>@if($ok) const value = {{ $value }}; @endif</script>',
                    'errors' => [['line' => 1]],
                ],
                [
                    'code' => '<script>@foreach($items as $item) values.push({{ $item }}); @endforeach</script>',
                    'errors' => [['line' => 1]],
                ],
            ],
        ]);
    });

    it('reports each value-position echo', function (): void {
        $this->getRuleTester()->run(new PreferJsonInScriptRule, [
            'invalid' => [
                [
                    'code' => "<script>var a = {{ \$a }};\nvar b = '{{ \$ok }}';\nvar c = {{ \$c }};</script>",
                    'errors' => [['line' => 1], ['line' => 2], ['line' => 3]],
                ],
            ],
        ]);
    });
});
