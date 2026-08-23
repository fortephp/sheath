<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Directives\NoCompilerDirectivesInCommentsRule;

describe('NoCompilerDirectivesInCommentsRule', function (): void {
    it('passes for comments without compiler directives', function (): void {
        $this->getRuleTester()->run(new NoCompilerDirectivesInCommentsRule, [
            'valid' => [
                '{{-- plain note about templates --}}',
                '{{-- TODO: extract this partial --}}',
                "{{-- multi\nline\ncomment --}}",
                '{{-- mentions php without the at sign --}}',
                '{{-- @if and @foreach are fine to mention --}}',
                '{{-- @phpstan and @phpdoc are different words --}}',
            ],
        ]);
    });

    it('passes for escaped mentions inside comments', function (): void {
        $this->getRuleTester()->run(new NoCompilerDirectivesInCommentsRule, [
            'valid' => [
                '{{-- use @@php for inline PHP --}}',
                '{{-- close with @@endverbatim --}}',
            ],
        ]);
    });

    it('passes for the directives outside comments', function (): void {
        $this->getRuleTester()->run(new NoCompilerDirectivesInCommentsRule, [
            'valid' => [
                "@php\n\$x = 1;\n@endphp",
                "@verbatim\n{{ raw }}\n@endverbatim",
            ],
        ]);
    });

    it('passes for html comments', function (): void {
        $this->getRuleTester()->run(new NoCompilerDirectivesInCommentsRule, [
            'valid' => [
                '<!-- @php $x = 1; @endphp -->',
            ],
        ]);
    });

    it('fails for @php mentioned in a comment', function (): void {
        $this->getRuleTester()->run(new NoCompilerDirectivesInCommentsRule, [
            'invalid' => [
                [
                    'code' => "{{-- TODO: maybe use @php here --}}\n<div>middle content</div>\n@php \$x = 1; @endphp",
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });

    it('fails even when no live block exists yet', function (): void {
        $this->getRuleTester()->run(new NoCompilerDirectivesInCommentsRule, [
            'invalid' => [
                [
                    'code' => '{{-- TODO use @php --}}',
                    'errors' => [
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });

    it('fails for each raw-block name', function (): void {
        $this->getRuleTester()->run(new NoCompilerDirectivesInCommentsRule, [
            'invalid' => [
                [
                    'code' => '{{-- close it with @endphp --}}',
                    'errors' => [['line' => 1]],
                ],
                [
                    'code' => '{{-- docs mention @verbatim --}}',
                    'errors' => [['line' => 1]],
                ],
                [
                    'code' => '{{-- and @endverbatim too --}}',
                    'errors' => [['line' => 1]],
                ],
                [
                    'code' => '{{-- the @php( inline form as well --}}',
                    'errors' => [['line' => 1]],
                ],
            ],
        ]);
    });

    it('reports each distinct name once per comment', function (): void {
        $this->getRuleTester()->run(new NoCompilerDirectivesInCommentsRule, [
            'invalid' => [
                [
                    'code' => '{{-- wrap @php code with @endphp, twice: @php --}}',
                    'errors' => [
                        ['line' => 1],
                        ['line' => 1],
                    ],
                ],
            ],
        ]);
    });

    it('offers no fix', function (): void {
        $this->getRuleTester()->run(new NoCompilerDirectivesInCommentsRule, [
            'invalid' => [
                [
                    'code' => '{{-- TODO use @php --}}',
                    'errors' => [
                        ['hasFixAvailable' => false],
                    ],
                ],
            ],
        ]);
    });
});
