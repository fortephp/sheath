<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Security\NoTargetBlankRule;

function legacyTargetBlankRule(): NoTargetBlankRule
{
    $rule = new NoTargetBlankRule;
    $rule->setOptions(['requireExplicitNoopener' => true]);

    return $rule;
}

describe('NoTargetBlankRule', function (): void {
    it('uses explicit target and rel attributes before a later opaque provider', function (): void {
        $this->getRuleTester()->run(new NoTargetBlankRule, [
            'valid' => ['<a {{ $attributes }} target="_blank" rel="opener">Link</a>'],
            'invalid' => [[
                'code' => '<a target="_blank" rel="opener" {{ $attributes }}>Link</a>',
                'errors' => 1,
            ]],
        ]);
    });

    it('uses an explicit base target before a later opaque provider', function (): void {
        $this->getRuleTester()->run(new NoTargetBlankRule, [
            'valid' => ['<base {{ $attributes }} target="_blank"><a rel="opener">Link</a>'],
            'invalid' => [[
                'code' => '<base target="_blank" {{ $attributes }}><a rel="opener">Link</a>',
                'errors' => 1,
            ]],
        ]);
    });

    it('accepts links that cannot retain an opener', function (): void {
        $this->getRuleTester()->run(new NoTargetBlankRule, [
            'valid' => [
                '<a href="/page">Link</a>',
                '<a href="/page" target="_self">Link</a>',
                '<a href="https://example.com" target="_blank">Implicit noopener</a>',
                '<a href="https://example.com" target="_blank" rel="nofollow">Implicit noopener with rel</a>',
                '<a href="https://example.com" target="_blank" rel="noopener">Explicit noopener</a>',
                '<a href="https://example.com" target="_blank" rel="noreferrer">Noreferrer</a>',
                '<a href="https://example.com" target="_blank" rel="opener noopener">Noopener wins</a>',
                '<map name="m"><area href="/safe" target="_blank"></map>',
                '<a target=" _blank " rel="opener">Named context</a>',
                "<a target=\"\x0B_blank\" rel=\"opener\">Named context</a>",
                "<a target=\"\r_blank\" rel=\"opener\">Named context</a>",
                "<a target=\"\f_blank\" rel=\"opener\">Named context</a>",
            ],
        ]);
    });

    it('reports explicit opener relationships', function (string $code): void {
        $this->getRuleTester()->run(new NoTargetBlankRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'anchor' => '<a href="https://example.com" target="_blank" rel="opener">Link</a>',
        'area' => '<map name="m"><area href="/unsafe" target="_blank" rel="opener"></map>',
        'mixed case' => '<A href="https://example.com" target="_BLANK" rel="OPENER">Link</A>',
        'additional rel values' => '<a href="https://example.com" target="_blank" rel="nofollow opener">Link</a>',
        'tab-coerced target' => "<a target=\"\t<\" rel=\"opener\">Link</a>",
        'line-feed-coerced target' => "<a target=\"\nname<\" rel=\"opener\">Link</a>",
    ]);

    it('fixes explicit opener without changing referrer behavior', function (): void {
        $code = '<a href="https://example.com" target="_blank" rel="opener">Link</a>';

        expect($this->getRuleTester()->fix(new NoTargetBlankRule, $code))
            ->toBe('<a href="https://example.com" target="_blank" rel="opener noopener">Link</a>')
            ->not->toContain('noreferrer');
    });

    it('does not guess about dynamic target or rel attributes', function (): void {
        $this->getRuleTester()->run(new NoTargetBlankRule, [
            'valid' => [
                '<a href="https://example.com" :target="target">Link</a>',
                '<a href="https://example.com" target="{{ $target }}">Link</a>',
                '<a href="https://example.com" target="_blank" :rel="$relExpr">Link</a>',
                '<a href="https://example.com" target="_blank" rel="{{ $rel }}">Link</a>',
            ],
        ]);
    });

    it('can require an explicit noopener token for legacy browsers', function (string $code): void {
        $this->getRuleTester()->run(legacyTargetBlankRule(), [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'missing rel' => '<a href="https://example.com" target="_blank">Link</a>',
        'unrelated rel' => '<a href="https://example.com" target="_blank" rel="nofollow">Link</a>',
        'empty rel' => '<a href="https://example.com" target="_blank" rel="">Link</a>',
        'dynamic rel' => '<a href="https://example.com" target="_blank" :rel="$relExpr">Link</a>',
    ]);

    it('fixes missing and existing rel values in legacy mode', function (string $code, string $output): void {
        expect($this->getRuleTester()->fix(legacyTargetBlankRule(), $code))->toBe($output);
    })->with([
        'missing rel' => [
            '<a href="https://x.test" target="_blank">x</a>',
            '<a href="https://x.test" target="_blank" rel="noopener">x</a>',
        ],
        'uppercase keyword' => [
            '<a href="https://x.test" target="_blank" rel="NOFOLLOW">x</a>',
            '<a href="https://x.test" target="_blank" rel="NOFOLLOW noopener">x</a>',
        ],
        'single quotes' => [
            '<a href="https://x.test" target="_blank" rel=\'nofollow\'>x</a>',
            '<a href="https://x.test" target="_blank" rel=\'nofollow noopener\'>x</a>',
        ],
        'unquoted value' => [
            '<a href="https://x.test" target="_blank" rel=nofollow>x</a>',
            '<a href="https://x.test" target="_blank" rel="nofollow noopener">x</a>',
        ],
        'empty value' => [
            '<a href="https://x.test" target="_blank" rel="">x</a>',
            '<a href="https://x.test" target="_blank" rel="noopener">x</a>',
        ],
    ]);

    it('does not duplicate an explicit safe token in legacy mode', function (): void {
        $this->getRuleTester()->run(legacyTargetBlankRule(), [
            'valid' => [
                '<a href="https://example.com" target="_blank" rel="noopener">Link</a>',
                '<a href="https://example.com" target="_blank" rel="noreferrer">Link</a>',
            ],
        ]);
    });

    it('correlates target and rel attributes on Blade render paths', function (): void {
        $this->getRuleTester()->run(new NoTargetBlankRule, [
            'valid' => [
                '<a @if($same) target="_blank" rel="noopener" @else target="_self" rel="opener" @endif>Link</a>',
                '<a @if($new) target="_blank" rel="noopener" @endif>Link</a>',
            ],
            'invalid' => [[
                'code' => '<a @if($new) target="_blank" rel="opener" @endif>Link</a>',
                'errors' => [[

                    'hasFixAvailable' => false,
                ]],
            ]],
        ]);

        $this->getRuleTester()->run(legacyTargetBlankRule(), [
            'valid' => [
                '<a @if($same) target="_blank" rel="noopener" @else target="_self" @endif>Link</a>',
            ],
            'invalid' => [[
                'code' => '<a @if($new) target="_blank" @endif>Link</a>',
                'errors' => [[

                    'hasFixAvailable' => true,
                ]],
                'output' => '<a @if($new) target="_blank" @endif rel="noopener">Link</a>',
            ]],
        ]);
    });

    it('applies the HTML target coercion only when an ASCII tab or newline occurs with a less-than sign', function (): void {
        $this->getRuleTester()->run(new NoTargetBlankRule, [
            'valid' => [
                '<a target="name<" rel="opener">x</a>',
                "<a target=\"name\t\" rel=\"opener\">x</a>",
            ],
            'invalid' => array_map(
                static fn (string $code): array => [
                    'code' => $code,
                    'errors' => 1,
                ],
                [
                    "<a target=\"name\t<\" rel=\"opener\">x</a>",
                    "<a target=\"name\n<\" rel=\"opener\">x</a>",
                    "<a target=\"name\r<\" rel=\"opener\">x</a>",
                    '<a target="name&#13;&lt;" rel="opener">x</a>',
                ],
            ),
        ]);
    });

    it('uses the first base target when a link has no explicit target', function (): void {
        $this->getRuleTester()->run(new NoTargetBlankRule, [
            'valid' => [
                '<head><base target="_self"><base target="_blank"></head><a href="x" rel="opener">x</a>',
                '<head><base target="_blank"></head><a href="x" target="_self" rel="opener">x</a>',
                '@if($enabled)<base target="_blank">@else<a href="x" rel="opener">x</a>@endif',
                '@push("head")<base target="_blank">@endpush<a href="x" rel="opener">x</a>',
                '<template><base target="_blank"></template><a href="x" rel="opener">x</a>',
            ],
            'invalid' => [
                [
                    'code' => '<head><base target="_blank"></head><a href="x" rel="opener">x</a>',
                    'errors' => 1,
                ],
                [
                    'code' => '<!doctype html><html><head><template><base target="_self"></template><base target="_blank"></head><body><a href="x" rel="opener">x</a></body></html>',
                    'errors' => 1,
                ],
                [
                    'code' => '@if($self)<base target="_self">@endif<base target="_blank"><a href="x" rel="opener">x</a>',
                    'errors' => 1,
                ],
                [
                    'code' => '<base @if($self) target="_self" @endif><base target="_blank"><a href="x" rel="opener">x</a>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('preserves non-HTML whitespace when appending noopener', function (): void {
        $code = "<a target=\"_blank\" rel=\"\x0Bopener\">x</a>";

        expect($this->getRuleTester()->fix(legacyTargetBlankRule(), $code))
            ->toBe("<a target=\"_blank\" rel=\"\x0Bopener noopener\">x</a>");
    });

    it('does not lose relevant attributes when unrelated conditionals exceed the path bound', function (): void {
        $unrelated = implode('', array_map(
            static fn (int $index): string => "@if(\$flag{$index}) data-{$index}=\"x\" @endif ",
            range(1, 8),
        ));

        $this->getRuleTester()->run(new NoTargetBlankRule, [
            'invalid' => [[
                'code' => '<a '.$unrelated.'target="_blank" rel="opener">Link</a>',
                'errors' => 1,
            ]],
        ]);
    });
});
