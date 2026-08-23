<?php

declare(strict_types=1);

namespace Forte\Sheath\Parsing;

use ParseError;

/**
 * @internal
 */
final class PhpSource
{
    /**
     * @return list<array{int, string, int}|string>|null
     */
    public static function tokenize(string $expression): ?array
    {
        try {
            $tokens = @token_get_all('<?php '.$expression);
        } catch (\Throwable) {
            return null;
        }

        if ($tokens === []) {
            return null;
        }

        $first = array_shift($tokens);

        if (! is_array($first) || $first[0] !== T_OPEN_TAG) {
            return null;
        }

        $reconstructed = '';

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_OPEN_TAG, T_OPEN_TAG_WITH_ECHO, T_CLOSE_TAG, T_INLINE_HTML], true)) {
                return null;
            }

            $reconstructed .= is_array($token) ? $token[1] : $token;
        }

        if ($reconstructed !== $expression) {
            return null;
        }

        return $tokens;
    }

    /**
     * Find the first unqualified or explicitly global function call whose name
     * appears in the supplied list. Method, static, constructor, declaration,
     * and namespaced-function syntax is ignored.
     *
     * @param  array<string>  $functionNames
     */
    public static function firstGlobalFunctionCall(string $code, array $functionNames): ?string
    {
        if ($functionNames === []) {
            return null;
        }

        $tokens = self::tokenize($code);
        if ($tokens === null) {
            return null;
        }

        $functionNames = array_fill_keys(array_map(
            static fn (string $name): string => strtolower(ltrim($name, '\\')),
            $functionNames,
        ), true);

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) {
                continue;
            }

            $function = self::globalFunctionName($token);
            if ($function === null || ! isset($functionNames[$function])) {
                continue;
            }

            if (self::significantToken($tokens, $index + 1, 1) !== '(') {
                continue;
            }

            if ($token[0] === T_STRING && self::isNonGlobalFunctionContext($tokens, $index)) {
                continue;
            }

            return $function;
        }

        return null;
    }

    public static function parses(string $code): bool
    {
        try {
            $parsed = @token_get_all('<?php '.$code.';', TOKEN_PARSE);
        } catch (\Throwable) {
            return false;
        }

        return $parsed !== [];
    }

    public static function mayProduceOutput(string $code): bool
    {
        $tokens = self::tokenize($code);
        if ($tokens === null) {
            return true;
        }

        $outputTokens = [T_ECHO, T_PRINT, T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL, T_EXIT];
        $previous = null;

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            if (is_array($token) && in_array($token[0], $outputTokens, true)) {
                return true;
            }

            if ($token === '(' && (
                (is_array($previous) && in_array($previous[0], [T_STRING, T_VARIABLE, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true))
                || $previous === ')'
            )) {
                return true;
            }

            $previous = $token;
        }

        return false;
    }

    /**
     * @param  string  $code  A complete snippet, `<?php` open tag included.
     */
    public static function parseError(string $code): ?string
    {
        return self::parseErrorDetails($code)['message'] ?? null;
    }

    /**
     * @return array{message: string, line: int}|null
     */
    public static function parseErrorDetails(string $code): ?array
    {
        try {
            $tokens = token_get_all($code, TOKEN_PARSE);

            return $tokens === [] ? ['message' => 'no PHP tokens found', 'line' => 1] : null;
        } catch (ParseError $e) {
            return ['message' => $e->getMessage(), 'line' => $e->getLine()];
        }
    }

    /**
     * @param  array{int, string, int}|string  $token
     */
    public static function nestingDelta(array|string $token): int
    {
        if (is_array($token)) {
            $openers = [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE];

            return in_array($token[0], $openers, true) ? 1 : 0;
        }

        return match ($token) {
            '(', '[', '{' => 1,
            ')', ']', '}' => -1,
            default => 0,
        };
    }

    public static function innerArguments(?string $arguments): ?string
    {
        if ($arguments === null) {
            return null;
        }

        $trimmed = trim($arguments);

        if (! str_starts_with($trimmed, '(') || ! str_ends_with($trimmed, ')')) {
            return $trimmed === '' ? null : $trimmed;
        }

        $inner = trim(substr($trimmed, 1, -1));

        return $inner === '' ? null : $inner;
    }

    /**
     * @return array<int, string>|null Null when the expression will not tokenize
     */
    public static function splitTopLevel(string $expression): ?array
    {
        $tokens = self::tokenize($expression);

        if ($tokens === null) {
            return null;
        }

        $parts = [];
        $current = '';
        $depth = 0;

        foreach ($tokens as $token) {
            $delta = self::nestingDelta($token);

            if ($delta === 0 && $token === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';

                continue;
            }

            $depth += $delta;

            if ($depth < 0) {
                return null;
            }

            $current .= is_array($token) ? $token[1] : $token;
        }

        if ($depth !== 0) {
            return null;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    public static function literalString(string $expression): ?string
    {
        $tokens = self::tokenize($expression);

        if ($tokens === null) {
            return null;
        }

        $tokens = array_values(array_filter(
            $tokens,
            fn (array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));

        if (count($tokens) !== 1) {
            return null;
        }

        $token = $tokens[0];

        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }

        $raw = (string) $token[1];
        $body = substr($raw, 1, -1);

        if ($raw[0] === "'") {
            return (string) preg_replace('/\\\\([\\\\\'])/', '$1', $body);
        }

        return preg_match('/[$\\\\]/', $body) === 1 ? null : $body;
    }

    public static function stripLiterals(string $code): string
    {
        $sanitized = '';

        foreach (token_get_all('<?php '.$code) as $token) {
            if (! is_array($token)) {
                $sanitized .= $token;

                continue;
            }

            [$id, $text] = $token;

            if ($id === T_OPEN_TAG) {
                continue;
            }

            if ($id === T_CONSTANT_ENCAPSED_STRING) {
                $sanitized .= "''".str_repeat("\n", substr_count($text, "\n"));

                continue;
            }

            // Keep token boundaries intact when literal content is removed.
            if (in_array($id, [T_ENCAPSED_AND_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_START_HEREDOC, T_END_HEREDOC], true)) {
                $sanitized .= ' '.str_repeat("\n", substr_count($text, "\n"));

                continue;
            }

            $sanitized .= $text;
        }

        return $sanitized;
    }

    /**
     * @param  array{int, string, int}  $token
     */
    private static function globalFunctionName(array $token): ?string
    {
        if ($token[0] === T_STRING) {
            return strtolower($token[1]);
        }

        if ($token[0] === T_NAME_FULLY_QUALIFIED && substr_count($token[1], '\\') === 1) {
            return strtolower(ltrim($token[1], '\\'));
        }

        return null;
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private static function isNonGlobalFunctionContext(array $tokens, int $index): bool
    {
        $previous = self::significantToken($tokens, $index - 1, -1);

        return (is_array($previous) && in_array($previous[0], [
            T_OBJECT_OPERATOR,
            T_NULLSAFE_OBJECT_OPERATOR,
            T_DOUBLE_COLON,
            T_NEW,
            T_FUNCTION,
        ], true)) || $previous === '\\';
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     * @return array{int, string, int}|string|null
     */
    private static function significantToken(array $tokens, int $index, int $direction): array|string|null
    {
        for ($count = count($tokens); $index >= 0 && $index < $count; $index += $direction) {
            $token = $tokens[$index];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $token;
        }

        return null;
    }
}
