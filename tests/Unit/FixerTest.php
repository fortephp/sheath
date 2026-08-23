<?php

declare(strict_types=1);

use Forte\Sheath\Fixer;
use Forte\Sheath\Results\Fix;

describe('Fixer', function (): void {
    describe('single fix application', function (): void {
        it('rejects invalid fix ranges when they are created', function (int $start, int $end): void {
            expect(fn (): Fix => new Fix($start, $end, 'replacement'))
                ->toThrow(InvalidArgumentException::class);
        })->with([
            'negative start' => [-1, 2],
            'end before start' => [3, 2],
        ]);

        it('rejects a fix that extends past the source content', function (): void {
            $fix = new Fix(0, 6, 'replacement');

            expect(fn (): string => $fix->apply('short'))
                ->toThrow(InvalidArgumentException::class);
        });

        it('rejects an out-of-range fix through the fixer', function (): void {
            expect(fn () => (new Fixer)->applyFixes('short', [new Fix(0, 6, 'replacement')]))
                ->toThrow(InvalidArgumentException::class);
        });

        it('applies a single fix correctly', function (): void {
            $fixer = new Fixer;
            $content = '<button onclick="click()">Button</button>';
            $fix = new Fix(7, 25, '');

            $result = $fixer->applyFixes($content, [$fix], includeDangerous: true);

            expect($result->content)->toBe('<button>Button</button>')
                ->and($result->appliedCount)->toBe(1)
                ->and($result->skippedCount)->toBe(0)
                ->and($result->hasOverlaps)->toBeFalse();
        });

        it('applies a replacement fix correctly', function (): void {
            $fixer = new Fixer;
            $content = '<div class="foo bar foo"></div>';

            $fix = new Fix(5, 24, 'class="foo bar"');

            $result = $fixer->applyFixes($content, [$fix], includeDangerous: true);

            expect($result->content)
                ->toBe('<div class="foo bar"></div>');
        });

        it('does not count a replacement that leaves the source unchanged', function (): void {
            $content = '<div>unchanged</div>';
            $result = (new Fixer)->applyFixes(
                $content,
                [new Fix(0, strlen($content), $content)],
                includeDangerous: true,
            );

            expect($result->content)->toBe($content)
                ->and($result->appliedCount)->toBe(0)
                ->and($result->skippedCount)->toBe(1);
        });
    });

    describe('multiple fixes on same element', function (): void {
        it('applies multiple fixes on same element correctly', function (): void {
            $fixer = new Fixer;
            $content = '<button onclick="a()" onmouseover="b()">Button</button>';

            $fix1 = new Fix(7, 21, '');
            $fix2 = new Fix(21, 39, '');

            $result = $fixer->applyFixes($content, [$fix1, $fix2], includeDangerous: true);

            expect($result->content)->toBe('<button>Button</button>')
                ->and($result->appliedCount)->toBe(2);
        });

        it('handles fixes provided in any order', function (): void {
            $fixer = new Fixer;
            $content = '<button onclick="a()" onmouseover="b()">Button</button>';

            $fix1 = new Fix(7, 21, '');
            $fix2 = new Fix(21, 39, '');

            $result = $fixer->applyFixes($content, [$fix2, $fix1], includeDangerous: true);

            expect($result->content)->toBe('<button>Button</button>')
                ->and($result->appliedCount)->toBe(2);
        });
    });

    describe('multiple fixes across different elements', function (): void {
        it('applies fixes to multiple elements', function (): void {
            $fixer = new Fixer;
            $content = '<a onclick="x()">A</a><b onclick="y()">B</b>';

            $fix1 = new Fix(2, 16, '');
            $fix2 = new Fix(24, 38, '');

            $result = $fixer->applyFixes($content, [$fix1, $fix2], includeDangerous: true);

            expect($result->content)->toBe('<a>A</a><b>B</b>')
                ->and($result->appliedCount)->toBe(2);
        });

        it('matches reverse application across varied non-overlapping fixes', function (): void {
            $content = str_repeat('0123456789', 30);

            for ($seed = 0; $seed < 50; $seed++) {
                $fixes = [];

                for ($index = 0; $index < 30; $index++) {
                    $start = $index * 8;
                    $length = ($index + $seed) % 4;
                    $fixes[] = new Fix($start, $start + $length, "[{$seed}:{$index}]");
                }

                $rotation = $seed % count($fixes);
                $fixes = array_merge(array_slice($fixes, $rotation), array_slice($fixes, 0, $rotation));
                $reference = $content;
                $reverse = $fixes;
                usort($reverse, fn (Fix $left, Fix $right): int => $right->startOffset <=> $left->startOffset);

                foreach ($reverse as $fix) {
                    $reference = $fix->apply($reference);
                }

                $result = (new Fixer)->applyFixes($content, $fixes, includeDangerous: true);

                expect($result->content)->toBe($reference)
                    ->and($result->appliedCount)->toBe(count($fixes));
            }
        });
    });

    describe('overlapping fixes', function (): void {
        it('detects overlapping fixes', function (): void {
            $fixer = new Fixer;
            $content = '<div class="foo bar baz"></div>';

            $fix1 = new Fix(5, 20, 'class="foo"');
            $fix2 = new Fix(15, 24, 'bar"');

            $result = $fixer->applyFixes($content, [$fix1, $fix2], includeDangerous: true);

            expect($result->hasOverlaps)->toBeTrue()
                ->and($result->overlappingFixes)->toHaveCount(1);
        });

        it('skips the second overlapping fix', function (): void {
            $fixer = new Fixer;
            $content = 'abcdefghij';

            $fix1 = new Fix(2, 6, 'X');
            $fix2 = new Fix(4, 8, 'Y');

            $result = $fixer->applyFixes($content, [$fix1, $fix2], includeDangerous: true);

            expect($result->content)->toBe('abXghij')
                ->and($result->appliedCount)->toBe(1)
                ->and($result->skippedCount)->toBe(1);
        });

        it('handles adjacent but non-overlapping fixes', function (): void {
            $fixer = new Fixer;
            $content = 'abcdef';

            $fix1 = new Fix(1, 3, 'X');
            $fix2 = new Fix(3, 5, 'Y');

            $result = $fixer->applyFixes($content, [$fix1, $fix2], includeDangerous: true);

            expect($result->hasOverlaps)->toBeFalse()
                ->and($result->content)->toBe('aXYf')
                ->and($result->appliedCount)->toBe(2);
        });

        it('counts fixes dropped, not conflicting pairs', function (): void {
            $fixer = new Fixer;
            $content = str_repeat('x', 30);

            $result = $fixer->applyFixes($content, [
                new Fix(0, 10, 'A'),
                new Fix(5, 15, 'B'),
                new Fix(8, 20, 'C'),
            ], includeDangerous: true);

            expect($result->appliedCount)->toBe(1)
                ->and($result->skippedCount)->toBe(2);
        });

        it('keeps a fix that only conflicts with one already dropped', function (): void {
            $fixer = new Fixer;
            $content = 'abcdefghij';

            $result = $fixer->applyFixes($content, [
                new Fix(0, 3, 'A'),
                new Fix(2, 6, 'B'),
                new Fix(5, 8, 'C'),
            ], includeDangerous: true);

            expect($result->appliedCount)->toBe(2)
                ->and($result->skippedCount)->toBe(1)
                ->and($result->content)->toBe('AdeCij');
        });

        it('applies several insertions that share an offset', function (): void {
            $fixer = new Fixer;

            $result = $fixer->applyFixes('<img>', [
                new Fix(4, 4, ' alt=""'),
                new Fix(4, 4, ' loading="lazy"'),
            ], includeDangerous: true);

            expect($result->hasOverlaps)->toBeFalse()
                ->and($result->appliedCount)->toBe(2)
                ->and($result->skippedCount)->toBe(0)
                ->and($result->content)->toBe('<img alt="" loading="lazy">');
        });

        it('places trailing syntax after other insertions at the same offset', function (): void {
            $fixes = [
                new Fix(12, 12, ' loading="lazy"', dangerous: true),
                new Fix(12, 12, ' /', priority: Fix::PRIORITY_TRAILING_SYNTAX),
            ];

            $result = (new Fixer)->applyFixes('<img src="x">', $fixes, includeDangerous: true);

            expect($result->content)->toBe('<img src="x" loading="lazy" />')
                ->and($result->appliedCount)->toBe(2);
        });

        it('treats an insertion and replacement at the same start as conflicting', function (): void {
            $result = (new Fixer)->applyFixes('abc', [
                new Fix(1, 1, 'X'),
                new Fix(1, 2, 'Y'),
            ], includeDangerous: true);

            expect($result->content)->toBe('aXbc')
                ->and($result->appliedCount)->toBe(1)
                ->and($result->skippedCount)->toBe(1)
                ->and($result->hasOverlaps)->toBeTrue();
        });

        it('reports dangerous and overlapping skips together', function (): void {
            $fixer = new Fixer;

            $result = $fixer->applyFixes('abcdefghij', [
                new Fix(0, 4, 'A'),
                new Fix(2, 6, 'B'),
                Fix::dangerous(8, 9, 'D'),
            ], includeDangerous: false);

            expect($result->appliedCount)->toBe(1)
                ->and($result->skippedCount)->toBe(2);
        });

        it('keeps the first fix when the same fix instance is reported multiple times', function (): void {
            $fixer = new Fixer;
            $content = '<meta name="viewport" content="width=device-width, user-scalable=no, maximum-scale=1">';
            $fix = new Fix(22, 85, 'content="width=device-width"');

            $result = $fixer->applyFixes($content, [$fix, $fix], includeDangerous: true);

            expect($result->content)->toBe('<meta name="viewport" content="width=device-width">')
                ->and($result->appliedCount)->toBe(1)
                ->and($result->skippedCount)->toBe(1)
                ->and($result->hasOverlaps)->toBeTrue();
        });

        it('matches the exhaustive overlap algorithm across mixed ranges', function (): void {
            $content = str_repeat('x', 200);

            for ($seed = 0; $seed < 50; $seed++) {
                $fixes = [];

                for ($index = 0; $index < 30; $index++) {
                    $start = ($index * 13 + $seed * 7) % 170;
                    $length = 1 + (($index * 5 + $seed) % 15);
                    $fixes[] = new Fix($start, $start + $length, "[{$seed}:{$index}]");
                }

                $sorted = $fixes;
                usort($sorted, fn (Fix $left, Fix $right): int => [
                    $left->startOffset,
                    $left->endOffset,
                ] <=> [
                    $right->startOffset,
                    $right->endOffset,
                ]);

                $accepted = [];
                $overlaps = [];

                foreach ($sorted as $fix) {
                    $conflict = null;

                    foreach ($accepted as $existing) {
                        if ($existing->startOffset < $fix->endOffset
                            && $fix->startOffset < $existing->endOffset) {
                            $conflict = $existing;
                            break;
                        }
                    }

                    if ($conflict !== null) {
                        $overlaps[] = [$conflict, $fix];
                    } else {
                        $accepted[] = $fix;
                    }
                }

                $reverse = $accepted;
                usort($reverse, fn (Fix $left, Fix $right): int => $right->startOffset <=> $left->startOffset);
                $reference = $content;

                foreach ($reverse as $fix) {
                    $reference = $fix->apply($reference);
                }

                $result = (new Fixer)->applyFixes($content, $fixes, includeDangerous: true);

                expect($result->content)->toBe($reference)
                    ->and($result->appliedCount)->toBe(count($accepted))
                    ->and($result->overlappingFixes)->toBe($overlaps);
            }
        });
    });

    describe('dangerous fixes', function (): void {
        it('skips dangerous fixes when includeDangerous is false', function (): void {
            $fixer = new Fixer;
            $content = '<button onclick="click()">Button</button>';
            $fix = Fix::dangerous(7, 25, '');

            $result = $fixer->applyFixes($content, [$fix], includeDangerous: false);

            expect($result->content)->toBe($content)
                ->and($result->appliedCount)->toBe(0)
                ->and($result->skippedCount)->toBe(1);
        });

        it('applies dangerous fixes when includeDangerous is true', function (): void {
            $fixer = new Fixer;
            $content = '<button onclick="click()">Button</button>';
            $fix = Fix::dangerous(7, 25, '');

            $result = $fixer->applyFixes($content, [$fix], includeDangerous: true);

            expect($result->content)->toBe('<button>Button</button>')
                ->and($result->appliedCount)->toBe(1);
        });

        it('applies mix of safe and dangerous fixes appropriately', function (): void {
            $fixer = new Fixer;
            $content = '<div id="x" onclick="y()"></div>';

            $safeFix = new Fix(4, 11, '');
            $dangerousFix = Fix::dangerous(11, 25, '');

            $result = $fixer->applyFixes($content, [$safeFix, $dangerousFix], includeDangerous: false);
            expect($result->content)->toBe('<div onclick="y()"></div>')
                ->and($result->appliedCount)->toBe(1)
                ->and($result->skippedCount)->toBe(1);

            $result = $fixer->applyFixes($content, [$safeFix, $dangerousFix], includeDangerous: true);
            expect($result->content)->toBe('<div></div>')
                ->and($result->appliedCount)->toBe(2);
        });

        it('handles empty fixes array', function (): void {
            $fixer = new Fixer;
            $content = '<div>Hello</div>';

            $result = $fixer->applyFixes($content, []);

            expect($result->content)->toBe($content)
                ->and($result->appliedCount)->toBe(0)
                ->and($result->skippedCount)->toBe(0);
        });

        it('handles fix at start of string', function (): void {
            $fixer = new Fixer;
            $content = 'onclick="x()" class="foo"';
            $fix = new Fix(0, 13, '');

            $result = $fixer->applyFixes($content, [$fix], includeDangerous: true);

            expect($result->content)
                ->toBe(' class="foo"');
        });

        it('handles fix at end of string', function (): void {
            $fixer = new Fixer;
            $content = 'class="foo" onclick="x()"';
            $fix = new Fix(12, 25, '');

            $result = $fixer->applyFixes($content, [$fix], includeDangerous: true);

            expect($result->content)->toBe('class="foo" ');
        });

        it('handles insertion (same start and end offset)', function (): void {
            $fixer = new Fixer;
            $content = '<a href="url">Link</a>';
            $fix = new Fix(13, 13, ' target="_blank"');

            $result = $fixer->applyFixes($content, [$fix], includeDangerous: true);

            expect($result->content)
                ->toBe('<a href="url" target="_blank">Link</a>');
        });

        it('handles three or more fixes', function (): void {
            $fixer = new Fixer;
            $content = '<x a="1" b="2" c="3"></x>';

            $fix1 = new Fix(2, 8, '');
            $fix2 = new Fix(8, 14, '');
            $fix3 = new Fix(14, 20, '');

            $result = $fixer->applyFixes($content, [$fix1, $fix2, $fix3], includeDangerous: true);

            expect($result->content)
                ->toBe('<x></x>')
                ->and($result->appliedCount)->toBe(3);
        });

        it('handles empty content', function (): void {
            $fixer = new Fixer;
            $content = '';

            $result = $fixer->applyFixes($content, []);

            expect($result->content)
                ->toBe('')
                ->and($result->appliedCount)->toBe(0);
        });

        it('handles fix that replaces entire content', function (): void {
            $fixer = new Fixer;
            $content = 'hello';
            $fix = new Fix(0, 5, 'world');

            $result = $fixer->applyFixes($content, [$fix], includeDangerous: true);

            expect($result->content)
                ->toBe('world');
        });
    });

    describe('FixResult', function (): void {
        it('allApplied returns true when no fixes skipped', function (): void {
            $fixer = new Fixer;
            $content = '<div id="x"></div>';
            $fix = new Fix(5, 11, '');

            $result = $fixer->applyFixes($content, [$fix], includeDangerous: true);

            expect($result->allApplied())
                ->toBeTrue();
        });

        it('allApplied returns false when fixes were skipped', function (): void {
            $fixer = new Fixer;
            $content = '<div onclick="x()"></div>';
            $fix = Fix::dangerous(5, 18, '');

            $result = $fixer->applyFixes($content, [$fix], includeDangerous: false);

            expect($result->allApplied())
                ->toBeFalse();
        });
    });
});
