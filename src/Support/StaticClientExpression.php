<?php

declare(strict_types=1);

namespace Forte\Sheath\Support;

/** @internal */
final class StaticClientExpression
{
    public static function isConstant(string $expression): bool
    {
        $expression = trim($expression);
        if ($expression === '') {
            return true;
        }

        if (in_array(strtolower($expression), ['true', 'false', 'null', 'undefined', 'nan', 'infinity'], true)) {
            return true;
        }

        if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?$/i', $expression) === 1) {
            return true;
        }

        $quote = $expression[0];
        if (! in_array($quote, ["'", '"', '`'], true)
            || strlen($expression) < 2
            || $expression[strlen($expression) - 1] !== $quote) {
            return false;
        }

        return $quote !== '`' || ! str_contains($expression, '${');
    }

    public static function isObjectValue(string $expression): bool
    {
        $expression = self::withoutOuterParentheses(trim($expression));

        if (strtolower($expression) === 'null') {
            return true;
        }

        if (strlen($expression) < 2) {
            return false;
        }

        return ($expression[0] === '[' && str_ends_with($expression, ']'))
            || ($expression[0] === '{' && str_ends_with($expression, '}'));
    }

    /**
     * Alpine's x-id evaluates its expression and immediately calls forEach.
     * This proves only object literals whose authored forEach member cannot be
     * callable; references and function-valued members remain unknown.
     */
    public static function isDefinitelyNonIterableObject(string $expression): bool
    {
        $expression = self::withoutOuterParentheses(trim($expression));
        if (strlen($expression) < 2 || $expression[0] !== '{' || ! str_ends_with($expression, '}')) {
            return false;
        }

        // A spread or computed member can supply `forEach` at runtime. Keep
        // those object shapes conservative instead of guessing the result.
        if (str_contains($expression, '...')
            || preg_match('/(?:^|[,;{])\s*\[/s', $expression) === 1) {
            return false;
        }

        if (preg_match('/(?:^|[,;{])\s*(?:async\s+)?\*?\s*(?:[\'\"]forEach[\'\"]|forEach)\s*\(/s', $expression) === 1
            || preg_match('/(?:^|[,;{])\s*get\s+(?:[\'\"]forEach[\'\"]|forEach)\s*\(/s', $expression) === 1) {
            return false;
        }

        if (preg_match('/(?:^|[,;{])\s*(?:[\'\"]forEach[\'\"]|forEach)\s*\(/s', $expression) === 1) {
            return false;
        }

        if (preg_match('/(?:^|[,;{])\s*forEach\s*(?:[,}])/s', $expression) === 1) {
            return false;
        }

        if (preg_match(
            '/(?:^|[,;{])\s*(?:[\'\"]forEach[\'\"]|forEach)\s*:\s*(?:function\b|(?:async\s*)?(?:\([^)]*\)|[A-Za-z_$][\w$]*)\s*=>|(?!(?:true|false|null|undefined|NaN|Infinity)\b)[A-Za-z_$][\w$]*(?:\.[A-Za-z_$][\w$]*)*)/s',
            $expression,
        ) === 1) {
            return false;
        }

        return true;
    }

    private static function withoutOuterParentheses(string $expression): string
    {
        while (strlen($expression) >= 2
            && $expression[0] === '('
            && str_ends_with($expression, ')')) {
            $expression = trim(substr($expression, 1, -1));
        }

        return $expression;
    }
}
