<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Configuration\Suppressions;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Accessibility\Content\ImgAltTextRule;
use Forte\Sheath\Rules\BestPractices\Attributes\ButtonTypeRule;
use Forte\Sheath\Rules\RuleRegistry;

/** @return array<string> */
function suppressionRun(string $code, bool $inlineSuppressions = true): array
{
    $registry = new RuleRegistry;
    $registry->registerMany([ImgAltTextRule::class, ButtonTypeRule::class]);

    $config = Config::make()
        ->setRules([
            'a11y-alt-text' => 'error',
            'best-practices-button-type' => 'warning',
        ])
        ->setInlineSuppressions($inlineSuppressions);

    $found = [];
    foreach ((new Linter($registry))->lint($code, 'test.blade.php', $config)->violations as $violation) {
        $found[] = $violation->ruleId.'@'.$violation->getLine();
    }

    return $found;
}

describe('inline suppressions', function (): void {
    it('reports everything without a suppression comment', function (): void {
        expect(suppressionRun("<img src=\"a.png\">\n<button>go</button>"))
            ->toBe(['a11y-alt-text@1', 'best-practices-button-type@2']);
    });

    it('leaves unrelated comments alone', function (): void {
        expect(suppressionRun("{{-- just a note --}}\n<img src=\"a.png\">"))
            ->toBe(['a11y-alt-text@2']);
    });

    it('does not recognize suppression names embedded in a larger token', function (string $prefix): void {
        expect(suppressionRun("{{-- {$prefix}sheath-disable --}}\n<img src=\"a.png\">"))
            ->toBe(['a11y-alt-text@2']);
    })->with([
        'word prefix' => 'no',
        'hyphenated prefix' => 'x-',
        'underscore prefix' => 'x_',
    ]);

    it('recognizes a directive after punctuation that cannot be part of its token', function (): void {
        expect(suppressionRun("{{-- (sheath-disable-next-line a11y-alt-text --}}\n<img src=\"a.png\">"))
            ->toBe([]);
    });

    describe('disable-next-line', function (): void {
        it('suppresses every rule on the following line', function (): void {
            expect(suppressionRun("{{-- sheath-disable-next-line --}}\n<img src=\"a.png\">\n<button>go</button>"))
                ->toBe(['best-practices-button-type@3']);
        });

        it('suppresses only the rule it names', function (): void {
            expect(suppressionRun("{{-- sheath-disable-next-line a11y-alt-text --}}\n<img src=\"a.png\"><button>go</button>"))
                ->toBe(['best-practices-button-type@2']);
        });

        it('suppresses several named rules', function (): void {
            expect(suppressionRun("{{-- sheath-disable-next-line a11y-alt-text, best-practices-button-type --}}\n<img src=\"a.png\"><button>go</button>"))
                ->toBe([]);
        });

        it('does nothing when it names a different rule', function (): void {
            expect(suppressionRun("{{-- sheath-disable-next-line best-practices-button-type --}}\n<img src=\"a.png\">"))
                ->toBe(['a11y-alt-text@2']);
        });

        it('does not reach backwards', function (): void {
            expect(suppressionRun("<img src=\"a.png\">\n{{-- sheath-disable-next-line a11y-alt-text --}}"))
                ->toBe(['a11y-alt-text@1']);
        });

        it('ignores an explanation after the rule list', function (): void {
            expect(suppressionRun("{{-- sheath-disable-next-line a11y-alt-text -- decorative, alt is set upstream --}}\n<img src=\"a.png\">"))
                ->toBe([]);
        });

        it('works in an HTML comment', function (): void {
            expect(suppressionRun("<!-- sheath-disable-next-line a11y-alt-text -->\n<img src=\"a.png\">"))
                ->toBe([]);
        });
    });

    describe('disable-line', function (): void {
        it('suppresses the line the comment sits on', function (): void {
            expect(suppressionRun("<img src=\"a.png\"> {{-- sheath-disable-line a11y-alt-text --}}\n<button>go</button>"))
                ->toBe(['best-practices-button-type@2']);
        });
    });

    describe('disable-file', function (): void {
        it('suppresses every rule in the file', function (): void {
            expect(suppressionRun("{{-- sheath-disable-file --}}\n<img src=\"a.png\">\n<button>go</button>"))
                ->toBe([]);
        });

        it('suppresses only the rule it names', function (): void {
            expect(suppressionRun("{{-- sheath-disable-file a11y-alt-text --}}\n<img src=\"a.png\">\n<button>go</button>"))
                ->toBe(['best-practices-button-type@3']);
        });
    });

    describe('malformed rule lists', function (): void {
        it('suppresses nothing when no named rule survives parsing', function (): void {
            expect(suppressionRun("{{-- sheath-disable-next-line @@nope@@ --}}\n<img src=\"a.png\">"))
                ->toBe(['a11y-alt-text@2']);
        });

        it('suppresses nothing file-wide when disable-file names only garbage', function (): void {
            expect(suppressionRun("{{-- sheath-disable-file !!x!! --}}\n<img src=\"a.png\">\n<button>go</button>"))
                ->toBe(['a11y-alt-text@2', 'best-practices-button-type@3']);
        });

        it('suppresses nothing when garbage precedes an explanation', function (): void {
            expect(suppressionRun("{{-- sheath-disable-next-line !!x!! -- vendor snippet --}}\n<img src=\"a.png\">"))
                ->toBe(['a11y-alt-text@2']);
        });

        it('still suppresses everything for a bare directive', function (): void {
            expect(suppressionRun("{{-- sheath-disable-next-line --}}\n<img src=\"a.png\">"))
                ->toBe([]);
        });

        it('still suppresses everything for a bare directive with an explanation', function (): void {
            expect(suppressionRun("{{-- sheath-disable-next-line -- awaiting cleanup --}}\n<img src=\"a.png\">"))
                ->toBe([]);
        });

        it('keeps the valid rules from a mixed list', function (): void {
            expect(suppressionRun("{{-- sheath-disable-next-line a11y-alt-text, @@nope@@ --}}\n<img src=\"a.png\"><button>go</button>"))
                ->toBe(['best-practices-button-type@2']);
        });

        it('re-enables everything when enable names only garbage', function (): void {
            $code = "{{-- sheath-disable --}}\n<img src=\"a.png\">\n{{-- sheath-enable @@oops@@ --}}\n<button>go</button>";

            expect(suppressionRun($code))->toBe(['best-practices-button-type@4']);
        });

        it('still scopes a well-formed enable to the rule it names', function (): void {
            $code = "{{-- sheath-disable --}}\n{{-- sheath-enable a11y-alt-text --}}\n<img src=\"a.png\">\n<button>go</button>";

            expect(suppressionRun($code))->toBe(['a11y-alt-text@3']);
        });
    });

    describe('disable and enable regions', function (): void {
        it('suppresses between the two comments only', function (): void {
            expect(suppressionRun("{{-- sheath-disable --}}\n<img src=\"a.png\">\n{{-- sheath-enable --}}\n<button>go</button>"))
                ->toBe(['best-practices-button-type@4']);
        });

        it('runs to the end of the file when never enabled', function (): void {
            expect(suppressionRun("{{-- sheath-disable --}}\n<img src=\"a.png\">\n<button>go</button>"))
                ->toBe([]);
        });

        it('scopes a region to the rule it names', function (): void {
            expect(suppressionRun("{{-- sheath-disable a11y-alt-text --}}\n<img src=\"a.png\">\n<button>go</button>\n{{-- sheath-enable a11y-alt-text --}}"))
                ->toBe(['best-practices-button-type@3']);
        });

        it('revives only the rule that enable names', function (): void {
            $code = "{{-- sheath-disable --}}\n{{-- sheath-enable a11y-alt-text --}}\n<img src=\"a.png\"><button>go</button>";

            expect(suppressionRun($code))->toBe(['a11y-alt-text@3']);
        });

        it('keeps other rules disabled after a narrow enable', function (): void {
            $code = "{{-- sheath-disable --}}\n{{-- sheath-enable a11y-alt-text --}}\n<button>go</button>";

            expect(suppressionRun($code))->toBe([]);
        });

        it('can disable again after enabling', function (): void {
            $code = "{{-- sheath-disable --}}\n{{-- sheath-enable --}}\n<img src=\"a.png\">\n{{-- sheath-disable --}}\n<button>go</button>";

            expect(suppressionRun($code))->toBe(['a11y-alt-text@3']);
        });

        it('uses directive order when broad and narrow changes share a line', function (): void {
            $code = "{{-- sheath-disable --}} {{-- sheath-enable a11y-alt-text --}}\n<img src=\"a.png\"><button>go</button>";

            expect(suppressionRun($code))->toBe(['a11y-alt-text@2']);
        });

        it('lets a later broad change supersede a narrow change on the same line', function (): void {
            $code = "{{-- sheath-disable a11y-alt-text --}} {{-- sheath-enable --}}\n<img src=\"a.png\"><button>go</button>";

            expect(suppressionRun($code))->toBe([
                'a11y-alt-text@2',
                'best-practices-button-type@2',
            ]);
        });

        it('does not let a trailing block disable reach backward on the same line', function (string $comment): void {
            expect(suppressionRun('<img src="a.png">'.$comment))
                ->toBe(['a11y-alt-text@1']);
        })->with([
            'rule-specific Blade comment' => '{{-- sheath-disable a11y-alt-text --}}',
            'wildcard Blade comment' => '{{-- sheath-disable --}}',
            'rule-specific HTML comment' => '<!-- sheath-disable a11y-alt-text -->',
            'wildcard HTML comment' => '<!-- sheath-disable -->',
        ]);

        it('applies a leading block disable to later findings on the same line', function (string $comment): void {
            expect(suppressionRun($comment.'<img src="a.png">'))
                ->toBe([]);
        })->with([
            'rule-specific Blade comment' => '{{-- sheath-disable a11y-alt-text --}}',
            'wildcard Blade comment' => '{{-- sheath-disable --}}',
            'rule-specific HTML comment' => '<!-- sheath-disable a11y-alt-text -->',
            'wildcard HTML comment' => '<!-- sheath-disable -->',
        ]);

        it('does not let a trailing block enable reach backward on the same line', function (string $disable, string $enable): void {
            $code = $disable."\n".'<img src="a.png">'.$enable;

            expect(suppressionRun($code))->toBe([]);
        })->with([
            'rule-specific Blade comments' => [
                '{{-- sheath-disable a11y-alt-text --}}',
                '{{-- sheath-enable a11y-alt-text --}}',
            ],
            'wildcard Blade comments' => [
                '{{-- sheath-disable --}}',
                '{{-- sheath-enable --}}',
            ],
            'rule-specific HTML comments' => [
                '<!-- sheath-disable a11y-alt-text -->',
                '<!-- sheath-enable a11y-alt-text -->',
            ],
            'wildcard HTML comments' => [
                '<!-- sheath-disable -->',
                '<!-- sheath-enable -->',
            ],
        ]);

        it('applies a leading block enable to later findings on the same line', function (string $disable, string $enable): void {
            $code = $disable."\n".$enable.'<img src="a.png">';

            expect(suppressionRun($code))->toBe(['a11y-alt-text@2']);
        })->with([
            'rule-specific Blade comments' => [
                '{{-- sheath-disable a11y-alt-text --}}',
                '{{-- sheath-enable a11y-alt-text --}}',
            ],
            'wildcard Blade comments' => [
                '{{-- sheath-disable --}}',
                '{{-- sheath-enable --}}',
            ],
            'rule-specific HTML comments' => [
                '<!-- sheath-disable a11y-alt-text -->',
                '<!-- sheath-enable a11y-alt-text -->',
            ],
            'wildcard HTML comments' => [
                '<!-- sheath-disable -->',
                '<!-- sheath-enable -->',
            ],
        ]);
    });

    describe('when inline suppressions are disabled', function (): void {
        it('reports findings a comment would otherwise hide', function (string $code): void {
            expect(suppressionRun($code, inlineSuppressions: false))->not->toBeEmpty();
        })->with([
            "{{-- sheath-disable-next-line --}}\n<img src=\"a.png\">",
            "{{-- sheath-disable-file --}}\n<img src=\"a.png\">",
            "{{-- sheath-disable --}}\n<img src=\"a.png\">",
        ]);

        it('is on by default', function (): void {
            expect(Config::make()->respectsInlineSuppressions())->toBeTrue();
        });

        it('round-trips through config data', function (): void {
            expect(Config::fromArray(['inlineSuppressions' => false])->respectsInlineSuppressions())->toBeFalse()
                ->and(Config::fromArray(['inlineSuppressions' => false])->has('inlineSuppressions'))->toBeTrue();
        });
    });

    it('withholds the fix along with the violation', function (): void {
        $registry = new RuleRegistry;
        $registry->register(ButtonTypeRule::class);

        $config = Config::make()->setRule('best-practices-button-type', 'warning');

        $result = (new Linter($registry))->lint(
            "{{-- sheath-disable-next-line best-practices-button-type --}}\n<button>go</button>",
            'test.blade.php',
            $config
        );

        expect($result->violations)->toBe([])
            ->and($result->getFixableCount())->toBe(0);
    });

    describe('the parsed directives', function (): void {
        it('records what it found', function (): void {
            $suppressions = Suppressions::fromDocument(
                Document::parse("{{-- sheath-disable-next-line a11y-alt-text --}}\n<img src=\"a.png\">")
            );

            expect($suppressions->isEmpty())->toBeFalse()
                ->and($suppressions->directives())->toBe([
                    ['line' => 1, 'scope' => 'next-line', 'rules' => ['a11y-alt-text']],
                ]);
        });

        it('is empty for a template with no suppression comments', function (): void {
            $suppressions = Suppressions::fromDocument(
                Document::parse('<img src="a.png">')
            );

            expect($suppressions->isEmpty())->toBeTrue()
                ->and($suppressions->suppresses('a11y-alt-text', 1))->toBeFalse();
        });
    });
});
