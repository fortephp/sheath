<?php

declare(strict_types=1);

use Forte\Sheath\Parsing\ExpressionScanner;

describe('topLevel', function (): void {
    $collect = function (string $expression): array {
        $steps = [];

        foreach (ExpressionScanner::topLevel($expression) as $step) {
            $steps[] = $step;
        }

        return $steps;
    };

    it('reports depth around brackets', function () use ($collect): void {
        expect($collect('a(b)c'))->toBe([
            ['a', 0, 0],
            ['(', 1, 0],
            ['b', 2, 1],
            [')', 3, 0],
            ['c', 4, 0],
        ]);
    });

    it('skips string literal bodies entirely', function () use ($collect): void {
        expect($collect("a'(,)'b"))->toBe([
            ['a', 0, 0],
            ['b', 6, 0],
        ]);
    });

    it('honours escaped quotes inside strings', function () use ($collect): void {
        expect($collect("'it\\'s',x"))->toBe([
            [',', 7, 0],
            ['x', 8, 0],
        ]);
    });

    it('treats an unterminated string as running to the end', function () use ($collect): void {
        expect($collect("a'bcd"))->toBe([
            ['a', 0, 0],
        ]);
    });

    it('skips PHP comments entirely', function () use ($collect): void {
        expect(array_column($collect("a/* (, ) */b// , (\nc# ) ,\nd"), 0))
            ->toBe(['a', 'b', 'c', 'd']);
    });

    it('counts brackets of all three kinds toward depth', function () use ($collect): void {
        $depths = [];

        foreach ($collect('([{x}])') as [$char, $index, $depth]) {
            $depths[$char] = $depth;
        }

        expect($depths['x'])->toBe(3);
    });
});

describe('isBalanced', function (): void {
    it('accepts balanced parentheses', function (string $expression): void {
        expect(ExpressionScanner::isBalanced($expression))->toBeTrue();
    })->with([
        'plain' => '($a && $b)',
        'nested' => 'f(g(h($x)))',
        'parens inside strings do not count' => "f(') (')",
        'parens inside comments do not count' => 'f($a /* ) */)',
        'no parens at all' => '$a',
    ]);

    it('rejects unbalanced parentheses', function (string $expression): void {
        expect(ExpressionScanner::isBalanced($expression))->toBeFalse();
    })->with([
        'unclosed' => 'f($a',
        'stray closer' => '$a)',
        'dips below zero' => ')(',
    ]);
});

describe('stripOuterParentheses', function (): void {
    it('peels wrappers that enclose the whole expression', function (): void {
        expect(ExpressionScanner::stripOuterParentheses('(!$a)'))->toBe('!$a')
            ->and(ExpressionScanner::stripOuterParentheses('((!$a))'))->toBe('!$a');
    });

    it('leaves parens that do not wrap the whole expression', function (): void {
        expect(ExpressionScanner::stripOuterParentheses('($a) && ($b)'))->toBe('($a) && ($b)');
    });

    it('leaves an expression whose inner text is unbalanced', function (): void {
        expect(ExpressionScanner::stripOuterParentheses('()()'))->toBe('()()');
    });

    it('is not fooled by a closing paren inside a string', function (): void {
        expect(ExpressionScanner::stripOuterParentheses("(f(')'))"))->toBe("f(')')");
    });
});

describe('hasCommaAtDepth', function (): void {
    it('finds a second argument in a parenthesized list', function (): void {
        expect(ExpressionScanner::hasCommaAtDepth("('title', 'Home')", 1))->toBeTrue();
    });

    it('ignores commas nested deeper than the asked depth', function (): void {
        expect(ExpressionScanner::hasCommaAtDepth("(['a', 'b'])", 1))->toBeFalse()
            ->and(ExpressionScanner::hasCommaAtDepth('(f(1, 2))', 1))->toBeFalse();
    });

    it('ignores commas inside string literals', function (): void {
        expect(ExpressionScanner::hasCommaAtDepth("('a, b')", 1))->toBeFalse();
    });

    it('ignores commas inside PHP comments', function (string $expression): void {
        expect(ExpressionScanner::hasCommaAtDepth($expression, 1))->toBeFalse();
    })->with([
        'block comment' => "('title' /* , 'not inline' */)",
        'slash comment' => "('title' // , 'not inline'\n)",
        'hash comment' => "('title' # , 'not inline'\n)",
    ]);

    it('is not fooled by an escaped quote before a real comma', function (): void {
        expect(ExpressionScanner::hasCommaAtDepth("('it\\'s', 2)", 1))->toBeTrue();
    });

    it('reads depth zero as a bare top-level list', function (): void {
        expect(ExpressionScanner::hasCommaAtDepth('$a, $b', 0))->toBeTrue()
            ->and(ExpressionScanner::hasCommaAtDepth('f($a, $b)', 0))->toBeFalse();
    });
});
