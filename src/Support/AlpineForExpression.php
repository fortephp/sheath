<?php

declare(strict_types=1);

namespace Forte\Sheath\Support;

/** @internal */
final class AlpineForExpression
{
    public static function isStructurallyValid(string $expression): bool
    {
        return self::collectionExpression($expression) !== null;
    }

    public static function collectionExpression(string $expression): ?string
    {
        // This intentionally mirrors Alpine's forAliasRE. Empty aliases and
        // iterator slots are accepted by Alpine; an empty collection is
        // parsed too, but evaluates to an empty render and is handled by the
        // template rule as a distinct no-op contract.
        if (preg_match('/([\s\S]*?)\s+(?:in|of)\s+([\s\S]*)/', $expression, $match) !== 1) {
            return null;
        }

        return trim($match[2]);
    }

    public static function collectionCanContainAtMostOneItem(string $expression): bool
    {
        $collection = self::collectionExpression($expression);
        if ($collection === null) {
            return false;
        }

        $lower = strtolower($collection);
        if ($collection === '' || in_array($lower, ['null', 'undefined'], true)) {
            return true;
        }

        if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i', $collection) === 1) {
            // Array.from applies JavaScript's integer length conversion; any
            // positive numeric value below two produces at most one item.
            return (float) $collection < 2;
        }

        if ($collection[0] !== '[' || ! str_ends_with($collection, ']')) {
            return false;
        }

        $body = trim(substr($collection, 1, -1));
        if ($body === '') {
            return true;
        }

        $depth = 0;
        $quote = null;
        $escaped = false;
        $segmentStart = 0;
        $items = 0;
        for ($index = 0, $length = strlen($body); $index < $length; $index++) {
            $character = $body[$index];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($character === '\\') {
                    $escaped = true;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if (in_array($character, ["'", '"', '`'], true)) {
                $quote = $character;

                continue;
            }

            if (in_array($character, ['[', '{', '('], true)) {
                $depth++;

                continue;
            }

            if (in_array($character, [']', '}', ')'], true)) {
                $depth--;

                continue;
            }

            if ($depth === 0 && $character === ',') {
                $segment = trim(substr($body, $segmentStart, $index - $segmentStart));
                if (str_starts_with($segment, '...')) {
                    return false;
                }
                if ($segment !== '') {
                    $items++;
                }
                if ($items > 1) {
                    return false;
                }

                $segmentStart = $index + 1;
            }
        }

        $segment = trim(substr($body, $segmentStart));
        if (str_starts_with($segment, '...')) {
            return false;
        }
        if ($segment !== '') {
            $items++;
        }

        return $items <= 1;
    }

    public static function collectionIsDefinitelyEmpty(string $expression): bool
    {
        $collection = self::collectionExpression($expression);
        if ($collection === null || $collection === '') {
            return false;
        }

        if (in_array(strtolower($collection), ['false', 'nan', 'null', 'undefined'], true)) {
            return true;
        }

        if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i', $collection) === 1) {
            $number = (float) $collection;

            return is_finite($number) && $number < 1;
        }

        if (preg_match('/^\[\s*(?:,\s*)*\]$/s', $collection) === 1
            || preg_match('/^\{\s*\}$/s', $collection) === 1) {
            return true;
        }

        if (strlen($collection) >= 2
            && in_array($collection[0], ["'", '"', '`'], true)
            && $collection[strlen($collection) - 1] === $collection[0]) {
            $literal = substr($collection, 1, -1);

            if (! str_contains($literal, '\\')
                && preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i', trim($literal)) === 1) {
                $number = (float) trim($literal);

                return is_finite($number) && $number < 1;
            }

            return ! str_contains($literal, '\\') && trim($literal) === '';
        }

        return false;
    }

    public static function collectionIsNonFiniteNumeric(string $expression): bool
    {
        $collection = self::collectionExpression($expression);
        if ($collection === null) {
            return false;
        }

        if (in_array(strtolower($collection), ['infinity', '+infinity', '-infinity'], true)) {
            return true;
        }

        if (strlen($collection) >= 2
            && in_array($collection[0], ["'", '"', '`'], true)
            && $collection[strlen($collection) - 1] === $collection[0]
            && ! str_contains($collection, '\\')) {
            $collection = trim(substr($collection, 1, -1));
        }

        if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i', $collection) !== 1) {
            return in_array(strtolower($collection), ['infinity', '+infinity', '-infinity'], true);
        }

        return ! is_finite((float) $collection);
    }
}
