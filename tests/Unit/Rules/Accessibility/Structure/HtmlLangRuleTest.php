<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Accessibility\Structure\HtmlLangRule;
use Forte\Sheath\Rules\RuleRegistry;

describe('HtmlLangRule', function (): void {
    it('passes for html elements with lang attribute', function (): void {
        $this->getRuleTester()->run(new HtmlLangRule, [
            'valid' => [
                '<html lang="en"></html>',
                '<html lang="en-US"></html>',
                '<html lang="fr"></html>',
                '<!DOCTYPE html><html lang="en"><head></head><body></body></html>',
            ],
        ]);
    });

    it('uses the first duplicate lang attribute on each render path', function (): void {
        $this->getRuleTester()->run(new HtmlLangRule, [
            'valid' => ['<html lang="en" @if($x) lang="bogus_tag" @endif></html>'],
        ]);
    });

    it('fails for html elements without lang attribute', function (): void {
        $this->getRuleTester()->run(new HtmlLangRule, [
            'invalid' => [
                [
                    'code' => '<html></html>',
                    'errors' => 1,
                ],
                [
                    'code' => '<html class="no-js"></html>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('limits missing-lang diagnostics to the opening html tag', function (string $source, string $openingTag): void {
        $registry = new RuleRegistry;
        $registry->register(HtmlLangRule::class);

        $result = (new Linter($registry))->lint(
            $source,
            'test.blade.php',
            Config::make(['rules' => ['a11y-html-lang' => 'error']]),
        );

        expect($result->violations)->toHaveCount(1);

        $violation = $result->violations[0];

        expect($violation->start->offset)->toBe(0)
            ->and($violation->end->offset)->toBe(strlen($openingTag))
            ->and(substr(
                $source,
                $violation->start->offset,
                $violation->end->offset - $violation->start->offset,
            ))->toBe($openingTag);
    })->with([
        'nested children' => [
            '<html><body><main><p>Content</p></main></body></html>',
            '<html>',
        ],
        'angle bracket in an attribute value' => [
            '<html data-breakpoints="sm > md"><body>Content</body></html>',
            '<html data-breakpoints="sm > md">',
        ],
    ]);

    it('provides a dangerous auto-fix when the default language is implicit', function (): void {
        $this->getRuleTester()->run(new HtmlLangRule, [
            'invalid' => [
                [
                    'code' => '<html></html>',
                    'errors' => [
                        [
                            'hasFixAvailable' => true,
                            'hasDangerousFix' => true,
                        ],
                    ],
                    'output' => '<html lang="en"></html>',
                ],
            ],
        ]);
    });

    it('uses custom default lang from options', function (): void {
        $rule = new HtmlLangRule;
        $rule->setOptions(['default' => 'fr']);

        $this->getRuleTester()->run($rule, [
            'invalid' => [
                [
                    'code' => '<html></html>',
                    'errors' => [
                        [
                            'hasDangerousFix' => false,
                        ],
                    ],
                    'output' => '<html lang="fr"></html>',
                ],
            ],
        ]);
    });

    it('does not treat an unusable configured default as safe project knowledge', function (): void {
        $rule = new HtmlLangRule;
        $rule->setOptions(['default' => '']);

        $this->getRuleTester()->run($rule, [
            'invalid' => [[
                'code' => '<html></html>',
                'errors' => [[
                    'hasFixAvailable' => true,
                    'hasDangerousFix' => true,
                ]],
                'output' => '<html lang="en"></html>',
            ]],
        ]);
    });

    it('handles html with existing attributes', function (): void {
        $this->getRuleTester()->run(new HtmlLangRule, [
            'invalid' => [
                [
                    'code' => '<html class="theme-dark"></html>',
                    'errors' => 1,
                    'output' => '<html class="theme-dark" lang="en"></html>',
                ],
            ],
        ]);
    });

    it('inserts lang correctly when an attribute value contains a closing angle bracket', function (): void {
        $this->getRuleTester()->run(new HtmlLangRule, [
            'invalid' => [
                [
                    'code' => '<html data-breakpoints="sm > md"><head></head></html>',
                    'errors' => 1,
                    'output' => '<html data-breakpoints="sm > md" lang="en"><head></head></html>',
                ],
                [
                    'code' => '<html data-icon="a->b" class="x"></html>',
                    'errors' => 1,
                    'output' => '<html data-icon="a->b" class="x" lang="en"></html>',
                ],
            ],
        ]);
    });

    it('fails for an empty lang attribute', function (): void {
        $this->getRuleTester()->run(new HtmlLangRule, [
            'invalid' => [
                [
                    'code' => '<html lang=""></html>',
                    'errors' => [
                        [
                            'hasFixAvailable' => true,
                        ],
                    ],
                    'output' => '<html lang="en"></html>',
                ],
            ],
        ]);
    });

    it('fails for bare, whitespace-only, and malformed static lang attributes', function (string $code): void {
        $this->getRuleTester()->run(new HtmlLangRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [['hasFixAvailable' => true]],
                'output' => '<html lang="en"></html>',
            ]],
        ]);
    })->with([
        'bare' => ['<html lang></html>'],
        'whitespace' => ['<html lang="  "></html>'],
        'underscore' => ['<html lang="en_US"></html>'],
        'punctuation' => ['<html lang="!!!"></html>'],
    ]);

    it('does not treat a malformed configured default as safe project knowledge', function (): void {
        $rule = new HtmlLangRule;
        $rule->setOptions(['default' => 'en_US']);

        $this->getRuleTester()->run($rule, [
            'invalid' => [[
                'code' => '<html></html>',
                'errors' => [['hasFixAvailable' => true, 'hasDangerousFix' => true]],
                'output' => '<html lang="en"></html>',
            ]],
        ]);
    });

    it('passes for dynamic lang attributes', function (): void {
        $this->getRuleTester()->run(new HtmlLangRule, [
            'valid' => [
                '<html lang="{{ str_replace(\'_\', \'-\', app()->getLocale()) }}"></html>',
                '<html :lang="$locale"></html>',
            ],
        ]);
    });
});
