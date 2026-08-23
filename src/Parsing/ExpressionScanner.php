<?php

declare(strict_types=1);

namespace Forte\Sheath\Parsing;

/**
 * @internal
 */
final class ExpressionScanner
{
    /**
     * @return iterable<array{string, int, int}> [character, index, depth]
     */
    public static function topLevel(string $expression): iterable
    {
        $depth = 0;
        $inString = null;
        $inLineComment = false;
        $inBlockComment = false;
        $length = strlen($expression);

        for ($i = 0; $i < $length; $i++) {
            $char = $expression[$i];

            if ($inLineComment) {
                if ($char === "\n" || $char === "\r") {
                    $inLineComment = false;
                }

                continue;
            }

            if ($inBlockComment) {
                if ($char === '*' && ($expression[$i + 1] ?? '') === '/') {
                    $inBlockComment = false;
                    $i++;
                }

                continue;
            }

            if ($inString !== null) {
                if ($char === '\\') {
                    $i++;

                    continue;
                }

                if ($char === $inString) {
                    $inString = null;
                }

                continue;
            }

            if (($char === '/' && ($expression[$i + 1] ?? '') === '/') || $char === '#') {
                $inLineComment = true;

                continue;
            }

            if ($char === '/' && ($expression[$i + 1] ?? '') === '*') {
                $inBlockComment = true;
                $i++;

                continue;
            }

            if ($char === "'" || $char === '"') {
                $inString = $char;

                continue;
            }

            if ($char === '(' || $char === '[' || $char === '{') {
                yield [$char, $i, $depth];
                $depth++;

                continue;
            }

            if ($char === ')' || $char === ']' || $char === '}') {
                $depth--;
                yield [$char, $i, $depth];

                continue;
            }

            yield [$char, $i, $depth];
        }
    }

    public static function isBalanced(string $expression): bool
    {
        $depth = 0;

        foreach (self::topLevel($expression) as [$char]) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
                if ($depth < 0) {
                    return false;
                }
            }
        }

        return $depth === 0;
    }

    public static function stripOuterParentheses(string $expression): string
    {
        while (str_starts_with($expression, '(') && str_ends_with($expression, ')')) {
            $inner = trim(substr($expression, 1, -1));

            if (! self::isBalanced($inner)) {
                break;
            }

            $expression = $inner;
        }

        return $expression;
    }

    public static function hasCommaAtDepth(string $expression, int $depth): bool
    {
        foreach (self::topLevel($expression) as [$char, $index, $charDepth]) {
            if ($char === ',' && $charDepth === $depth) {
                return true;
            }
        }

        return false;
    }
}
