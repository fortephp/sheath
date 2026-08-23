<?php

declare(strict_types=1);

use Forte\Sheath\Rules\BestPractices\Documents\RequireDoctypeRule;

describe('RequireDoctypeRule', function (): void {
    it('passes for documents with DOCTYPE', function (): void {
        $this->getRuleTester()->run(new RequireDoctypeRule, [
            'valid' => [
                '<!DOCTYPE html><html><head></head><body></body></html>',
                '<!DOCTYPE HTML><html lang="en"></html>',
                "<!DOCTYPE html>\n<html></html>",
                "\xEF\xBB\xBF<!DOCTYPE html><html></html>",
                '<!DOCTYPE html SYSTEM "about:legacy-compat"><html></html>',
                "<!DOCTYPE HTML system 'about:legacy-compat'><html></html>",
            ],
        ]);
    });

    it('passes when Blade that renders to nothing precedes the DOCTYPE', function (): void {
        $this->getRuleTester()->run(new RequireDoctypeRule, [
            'valid' => [
                "{{-- Copyright Acme. Do not edit. --}}\n<!DOCTYPE html>\n<html lang=\"en\"></html>",
                "@php\n\$theme = 'dark';\n@endphp\n<!DOCTYPE html>\n<html lang=\"en\"></html>",
                "@props(['title'])\n<!DOCTYPE html>\n<html lang=\"en\"></html>",
                "@if (\$rtl)\n@endif\n<!DOCTYPE html>\n<html lang=\"en\"></html>",
                "@section('banner')hello\n@endsection<!DOCTYPE html><html></html>",
                "@push('scripts')hello\n@endpush<!DOCTYPE html><html></html>",
                "\n\n<!DOCTYPE html>\n<html lang=\"en\"></html>",
            ],
        ]);
    });

    it('requires a valid DOCTYPE on every render path', function (): void {
        $this->getRuleTester()->run(new RequireDoctypeRule, [
            'valid' => [
                '@if($modern)<!DOCTYPE html>@else<!DOCTYPE HTML>@endif<html lang="en"></html>',
            ],
            'invalid' => [[
                'code' => '@if($modern)<!DOCTYPE html>@endif<html lang="en"></html>',
                'errors' => [[

                    'hasFixAvailable' => false,
                ]],
            ]],
        ]);
    });

    it('does not count a stray DOCTYPE that follows the html element', function (): void {
        $this->getRuleTester()->run(new RequireDoctypeRule, [
            'invalid' => [
                [
                    'code' => '<html lang="en"><body><!DOCTYPE html></body></html>',
                    'errors' => [
                        [
                            'hasFixAvailable' => false,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('withholds fixes that cannot produce a valid document', function (string $code): void {
        $this->getRuleTester()->run(new RequireDoctypeRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [['hasFixAvailable' => false]],
            ]],
        ]);
    })->with([
        'rendered preamble before html' => 'hello<html lang="en"></html>',
        'rendered preamble before outdated doctype' => 'hello<!DOCTYPE HTML PUBLIC "legacy"><html></html>',
        'doctype inside html' => '<html><body><!DOCTYPE html></body></html>',
        'multiple doctypes' => '<!DOCTYPE HTML PUBLIC "legacy"><html><!DOCTYPE html></html>',
    ]);

    it('rejects a doctype reached only after rendered document content', function (string $code): void {
        $this->getRuleTester()->run(new RequireDoctypeRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'plain text' => 'hello<!DOCTYPE html><html></html>',
        'element' => '<div></div><!DOCTYPE html><html></html>',
        'echo' => '{{ $prefix }}<!DOCTYPE html><html></html>',
        'one conditional path' => '@if($early)<!DOCTYPE html>@else hello @endif<!DOCTYPE html><html></html>',
        'vertical-tab preamble' => "\x0B<!DOCTYPE html><html></html>",
        'BOM after whitespace' => " \xEF\xBB\xBF<!DOCTYPE html><html></html>",
        'repeated BOM' => "\xEF\xBB\xBF\xEF\xBB\xBF<!DOCTYPE html><html></html>",
        'section shown in place' => "@section('banner')hello\n@show<!DOCTYPE html><html></html>",
    ]);

    it('anchors the added DOCTYPE to the html element, not the start of the file', function (): void {
        $this->getRuleTester()->run(new RequireDoctypeRule, [
            'invalid' => [
                [
                    'code' => "{{-- Copyright Acme. --}}\n<html lang=\"en\"></html>",
                    'errors' => 1,
                    'output' => "{{-- Copyright Acme. --}}\n<!DOCTYPE html>\n<html lang=\"en\"></html>",
                ],
                [
                    'code' => "@props(['title'])\n<html lang=\"en\"></html>",
                    'errors' => 1,
                    'output' => "@props(['title'])\n<!DOCTYPE html>\n<html lang=\"en\"></html>",
                ],
            ],
        ]);
    });

    it('replaces an outdated DOCTYPE instead of adding a second one', function (): void {
        $this->getRuleTester()->run(new RequireDoctypeRule, [
            'invalid' => [
                [
                    'code' => "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.01//EN\">\n<html lang=\"en\"></html>",
                    'errors' => 1,
                    'output' => "<!DOCTYPE html>\n<html lang=\"en\"></html>",
                ],
            ],
        ]);
    });

    it('rejects non-conforming legacy-compat spellings', function (string $doctype): void {
        $this->getRuleTester()->run(new RequireDoctypeRule, [
            'invalid' => [[
                'code' => "{$doctype}<html></html>",
                'errors' => 1,
            ]],
        ]);
    })->with([
        'case-sensitive identifier' => '<!DOCTYPE html SYSTEM "ABOUT:LEGACY-COMPAT">',
        'vertical-tab separator' => "<!DOCTYPE\vhtml>",
    ]);

    it('passes for partial templates without html element', function (): void {
        $this->getRuleTester()->run(new RequireDoctypeRule, [
            'valid' => [
                '<div>Partial template</div>',
                '<x-component>Content</x-component>',
                '@extends("layout")<div>Content</div>',
            ],
        ]);
    });

    it('allows non-output state directives before the doctype', function (string $directive): void {
        $this->getRuleTester()->run(new RequireDoctypeRule, [
            'valid' => ["{$directive}\n<!doctype html><html></html>"],
        ]);
    })->with([
        'aware' => "@aware(['theme'])",
        'unset' => '@unset($temporary)',
    ]);

    it('fails for full HTML documents without DOCTYPE', function (): void {
        $this->getRuleTester()->run(new RequireDoctypeRule, [
            'invalid' => [
                [
                    'code' => '<html><head></head><body></body></html>',
                    'errors' => 1,
                ],
                [
                    'code' => '<html lang="en"><head><title>Test</title></head></html>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('provides auto-fix to add DOCTYPE', function (): void {
        $this->getRuleTester()->run(new RequireDoctypeRule, [
            'invalid' => [
                [
                    'code' => '<html></html>',
                    'errors' => [
                        [
                            'hasFixAvailable' => true,
                            'hasDangerousFix' => true,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('withholds rendering-mode changes unless dangerous fixes are enabled', function (string $code, string $output): void {
        $tester = $this->getRuleTester();

        expect($tester->fix(new RequireDoctypeRule, $code))->toBe($code)
            ->and($tester->fix(new RequireDoctypeRule, $code, dangerous: true))->toBe($output);
    })->with([
        'missing doctype' => [
            '<html></html>',
            "<!DOCTYPE html>\n<html></html>",
        ],
        'outdated doctype' => [
            "<!DOCTYPE HTML PUBLIC \"-//W3C//DTD HTML 4.01//EN\">\n<html></html>",
            "<!DOCTYPE html>\n<html></html>",
        ],
    ]);

    it('preserves CRLF line endings when adding a DOCTYPE', function (): void {
        $this->getRuleTester()->run(new RequireDoctypeRule, [
            'invalid' => [[
                'code' => "<html>\r\n<head></head>\r\n<body></body>\r\n</html>",
                'errors' => [['hasFixAvailable' => true]],
                'output' => "<!DOCTYPE html>\r\n<html>\r\n<head></head>\r\n<body></body>\r\n</html>",
            ]],
        ]);
    });
});
