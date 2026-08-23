<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Security\PreferHttpsRule;

describe('PreferHttpsRule', function (): void {
    it('treats backslashes as authority terminators in special HTTP URLs', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'valid' => ['<a href="http://localhost\\@evil.example/path">Local path</a>'],
            'invalid' => [[
                'code' => '<a href="http://evil.example\\@localhost/path">Remote HTTP</a>',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for HTTPS URLs', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'valid' => [
                '<a href="https://example.com">Link</a>',
                '<img src="https://example.com/image.jpg" alt="Image">',
                '<script src="https://example.com/script.js"></script>',
                '<link href="https://example.com/style.css" rel="stylesheet">',
                '<form action="https://example.com/submit"></form>',
            ],
        ]);
    });

    it('passes for relative URLs', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'valid' => [
                '<a href="/page">Link</a>',
                '<img src="/images/photo.jpg" alt="Photo">',
                '<a href="../other-page">Other</a>',
                '<img src="image.png" alt="Image">',
            ],
        ]);
    });

    it('passes for protocol-relative URLs', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'valid' => [
                '<a href="//example.com">Link</a>',
                '<img src="//example.com/image.jpg" alt="Image">',
            ],
        ]);
    });

    it('passes for special URLs', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'valid' => [
                '<a href="mailto:test@example.com">Email</a>',
                '<a href="tel:+1234567890">Call</a>',
                '<a href="javascript:void(0)">Click</a>',
                '<a href="#section">Anchor</a>',
            ],
        ]);
    });

    it('fails for HTTP href', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'invalid' => [
                [
                    'code' => '<a href="http://example.com">Link</a>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('handles URLs surrounded by ASCII whitespace', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'valid' => [
                '<a href="  http://localhost/path  ">Local</a>',
            ],
            'invalid' => [[
                'code' => "<a href=\"\t http://example.com/path  \">Link</a>",
                'errors' => 1,
                'hasFix' => true,
            ]],
        ]);

        $code = "<a href=\"\t http://example.com/path  \">Link</a>";

        expect($this->getRuleTester()->fix(new PreferHttpsRule, $code, dangerous: true))
            ->toBe("<a href=\"\t https://example.com/path  \">Link</a>");
    });

    it('uses URL-parser C0 control stripping when finding the scheme', function (string $prefix): void {
        $code = "<a href=\"{$prefix}http://evil.test/path\">Link</a>";

        $this->getRuleTester()->run(new PreferHttpsRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [['hasDangerousFix' => true]],
                'output' => "<a href=\"{$prefix}https://evil.test/path\">Link</a>",
            ]],
        ]);
    })->with([
        'vertical tab' => "\x0B",
        'unit separator' => "\x1F",
    ]);

    it('reports WHATWG-normalized HTTP schemes without an unsafe fix', function (string $url): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'invalid' => [[
                'code' => "<a href=\"{$url}\">Link</a>",
                'errors' => [['hasFixAvailable' => false]],
            ]],
        ]);
    })->with([
        'tab inside scheme' => "h\tttp://evil.test",
        'line feed after colon' => "http:\n//evil.test",
        'backslash authority' => 'http:\\evil.test',
        'mixed authority separators' => 'http:/\\evil.test',
        'scheme-relative path' => 'http:evil.test',
        'single slash' => 'http:/evil.test',
    ]);

    it('fails for HTTP src', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'invalid' => [
                [
                    'code' => '<img src="http://example.com/image.jpg" alt="Image">',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for HTTP in form action', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'invalid' => [
                [
                    'code' => '<form action="http://example.com/submit"></form>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('checks every URL in ping attributes', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'valid' => [
                '<a href="/" ping="https://analytics.test/a http://localhost/a">Link</a>',
                '<area href="/map" ping="{{ $endpoints }}">',
            ],
            'invalid' => [
                [
                    'code' => '<a href="/" ping="https://analytics.test/a http://evil.test/audit">Link</a>',
                    'errors' => 1,
                ],
                [
                    'code' => "<area href=\"/map\" ping=\"http://one.test/a\thttp&#58;//two.test/b\">",
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for HTTP in srcset', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'invalid' => [
                [
                    'code' => '<img srcset="http://example.com/small.jpg 1x, http://example.com/large.jpg 2x" alt="Image">',
                    'errors' => 1,
                ],
                [
                    'code' => "<img srcset=\"\x0Bhttp://example.com/small.jpg 1x, https://example.com/large.jpg 2x\">",
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('does not treat a comma inside a data URL as a candidate separator', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'valid' => [
                '<img srcset="data:image/svg+xml,http://www.w3.org/2000/svg 1x" alt="Icon">',
            ],
        ]);
    });

    it('checks HTTP candidates in imagesrcset', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'invalid' => [[
                'code' => '<link rel="preload" as="image" imagesrcset="http://cdn.example.com/small.png 1x, https://cdn.example.com/large.png 2x">',
                'errors' => 1,
            ]],
        ]);
    });

    it('reports multiple HTTP URLs', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'invalid' => [
                [
                    'code' => '<a href="http://a.com">A</a><a href="http://b.com">B</a>',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('is case insensitive for HTTP protocol', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'invalid' => [
                [
                    'code' => '<a href="HTTP://example.com">Link</a>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('passes for Blade interpolated URLs', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'valid' => [
                '<a href="{{ $link }}">Link</a>',
                '<img src="{{ $imageUrl }}" alt="Image">',
                '<form action="{{ route(\'submit\') }}"></form>',
                '<a href="{{ url(\'/page\') }}">Page</a>',

                '<a href="{{ $baseUrl }}/path">Link</a>',
                '<img src="{{ config(\'app.url\') }}/image.jpg" alt="Image">',

                '<a href="https://{{ $domain }}/path">Link</a>',
            ],
        ]);
    });

    it('passes for Blade component attribute syntax', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'valid' => [
                '<x-link :href="$url">Link</x-link>',
                '<x-image :src="$imageUrl" />',
            ],
        ]);
    });

    it('passes for empty or whitespace-only URLs', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'valid' => [
                '<a href="">Empty</a>',
                '<a href="   ">Whitespace</a>',
                '<img src="" alt="No source">',
            ],
        ]);
    });

    it('fails for HTTP with Blade interpolation in path only', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'invalid' => [
                [
                    'code' => '<a href="http://example.com/{{ $path }}">Link</a>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('decodes the static URL prefix before Blade interpolation', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'invalid' => [[
                'code' => '<a href="http&#58;//evil.test/{{ $path }}">Link</a>',
                'errors' => [[

                    'hasFixAvailable' => false,
                ]],
            ]],
        ]);
    });

    it('checks only the first URL attribute emitted on each render path', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'valid' => [
                '<a href="https://example.test" @if($legacy) href="http://evil.test" @endif>Link</a>',
            ],
        ]);
    });

    it('provides fix for HTTP to HTTPS', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'invalid' => [
                [
                    'code' => '<a href="http://example.com">Link</a>',
                    'errors' => [
                        [
                            'hasFixAvailable' => true,
                            'hasDangerousFix' => true,
                        ],
                    ],
                    'output' => '<a href="https://example.com">Link</a>',
                ],
            ],
        ]);
    });

    it('does not rewrite an endpoint unless dangerous fixes are enabled', function (): void {
        $rule = new PreferHttpsRule;
        $code = '<a href="http://legacy.example">Link</a>';

        expect($this->getRuleTester()->fix($rule, $code))->toBe($code)
            ->and($this->getRuleTester()->fix($rule, $code, dangerous: true))
            ->toBe('<a href="https://legacy.example">Link</a>');
    });

    it('preserves quote style in fix', function (): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'invalid' => [
                [
                    'code' => "<a href='http://example.com'>Link</a>",
                    'errors' => [
                        ['hasFixAvailable' => true],
                    ],
                    'output' => "<a href='https://example.com'>Link</a>",
                ],
            ],
        ]);
    });

    it('passes for loopback development hosts by default', function (string $code): void {
        $this->getRuleTester()->run(new PreferHttpsRule, ['valid' => [$code]]);
    })->with([
        'localhost' => '<a href="http://localhost/dashboard">Local</a>',
        'localhost with port' => '<script src="http://localhost:5173/@vite/client"></script>',
        'ipv4 loopback' => '<img src="http://127.0.0.1:8000/img/logo.png" alt="Logo">',
        'short ipv4 loopback' => '<a href="http://127.1/x">Local</a>',
        'integer ipv4 loopback' => '<a href="http://2130706433/x">Local</a>',
        'hex ipv4 loopback' => '<a href="http://0x7f000001/x">Local</a>',
        'ipv6 loopback' => '<a href="http://[::1]:8000/">Local</a>',
        'localhost in srcset' => '<img srcset="http://localhost:8000/small.jpg 1x, http://localhost:8000/large.jpg 2x" src="/f.jpg" alt="x">',
        'case-insensitive host' => '<a href="http://LOCALHOST/">Local</a>',
    ]);

    it('still flags lookalike hosts that are not loopback', function (string $code): void {
        $this->getRuleTester()->run(new PreferHttpsRule, [
            'invalid' => [
                ['code' => $code, 'errors' => 1],
            ],
        ]);
    })->with([
        'localhost subdomain of a real domain' => '<a href="http://localhost.example.com/">x</a>',
        'public address' => '<a href="http://example.com/">x</a>',
    ]);

    it('honors the allowedHosts option', function (): void {
        $rule = new PreferHttpsRule;
        $rule->setOptions(['allowedHosts' => ['myapp.test', '192.168.1.50']]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<a href="http://myapp.test/login">Local</a>',
                '<img src="http://192.168.1.50:8000/cam.jpg" alt="Camera">',
            ],
            'invalid' => [
                [
                    'code' => '<a href="http://othersite.test/">x</a>',
                    'errors' => 1,
                ],
                [
                    'code' => '<a href="http://localhost/">x</a>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('can disable all plain HTTP host exceptions', function (): void {
        $rule = new PreferHttpsRule;
        $rule->setOptions(['allowedHosts' => []]);

        $this->getRuleTester()->run($rule, [
            'invalid' => [[
                'code' => '<a href="http://localhost/">x</a>',
                'errors' => 1,
            ]],
        ]);
    });
});
