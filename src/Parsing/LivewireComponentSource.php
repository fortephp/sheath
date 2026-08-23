<?php

declare(strict_types=1);

namespace Forte\Sheath\Parsing;

use Forte\Ast\PhpTagNode;

/** @internal */
final class LivewireComponentSource
{
    private const COMPONENT_CLASS = 'livewire\\component';

    private const VOLT_COMPONENT_CLASS = 'livewire\\volt\\component';

    public static function isLeadingClassPreamble(PhpTagNode $tag): bool
    {
        if (! $tag->isPhpTag() || ! $tag->hasClose()) {
            return false;
        }

        $prefix = substr($tag->getDocument()->source(), 0, $tag->startOffset());
        if (str_starts_with($prefix, "\xEF\xBB\xBF")) {
            $prefix = substr($prefix, 3);
        }

        if (trim($prefix) !== '') {
            return false;
        }

        return self::isClassDefinition($tag, self::COMPONENT_CLASS);
    }

    /** Whether a standard PHP tag defines a class-based Volt component. */
    public static function isVoltClassDefinition(PhpTagNode $tag): bool
    {
        if (! $tag->isPhpTag()) {
            return false;
        }

        return self::isClassDefinition($tag, self::VOLT_COMPONENT_CLASS);
    }

    private static function isClassDefinition(PhpTagNode $tag, string $componentClass): bool
    {
        $tokens = PhpSource::tokenize($tag->content());
        if ($tokens === null) {
            return false;
        }

        $imports = self::classImports($tokens);

        return $imports !== null && self::hasTopLevelComponentClass($tokens, $imports, $componentClass);
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     * @return array<string, string>|null Null when a namespace makes unqualified resolution ambiguous.
     */
    private static function classImports(array $tokens): ?array
    {
        $imports = [];
        $depth = 0;

        foreach ($tokens as $index => $token) {
            if ($depth === 0 && is_array($token) && $token[0] === T_NAMESPACE) {
                return null;
            }

            if ($depth === 0 && is_array($token) && $token[0] === T_USE) {
                $body = self::useStatementBody($tokens, $index);
                if ($body !== null) {
                    self::collectImports($body, $imports);
                }
            }

            $depth += PhpSource::nestingDelta($token);
        }

        return $imports;
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private static function useStatementBody(array $tokens, int $useIndex): ?string
    {
        $first = self::significantToken($tokens, $useIndex + 1);
        if ($first === '(' || (is_array($first) && in_array($first[0], [T_FUNCTION, T_CONST], true))) {
            return null;
        }

        $body = '';
        $depth = 0;

        for ($index = $useIndex + 1, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token === ';' && $depth === 0) {
                return trim($body);
            }

            if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $body .= is_array($token) ? $token[1] : $token;
            }

            $depth += PhpSource::nestingDelta($token);
        }

        return null;
    }

    /** @param array<string, string> $imports */
    private static function collectImports(string $body, array &$imports): void
    {
        if (preg_match('/^(?:function|const)\b/i', ltrim($body)) === 1) {
            return;
        }

        foreach (PhpSource::splitTopLevel($body) ?? [] as $item) {
            $item = trim($item);
            $groupStart = strpos($item, '\\{');

            if ($groupStart !== false && str_ends_with($item, '}')) {
                $prefix = substr($item, 0, $groupStart);
                $members = substr($item, $groupStart + 2, -1);

                foreach (PhpSource::splitTopLevel($members) ?? [] as $member) {
                    self::collectImport($member, $imports, $prefix);
                }

                continue;
            }

            self::collectImport($item, $imports);
        }
    }

    /** @param array<string, string> $imports */
    private static function collectImport(string $specification, array &$imports, string $prefix = ''): void
    {
        $parts = preg_split('/\s+as\s+/i', trim($specification), 2);
        if ($parts === false || $parts === [] || trim($parts[0]) === '') {
            return;
        }

        $name = ltrim(trim($parts[0]), '\\');
        if ($prefix !== '') {
            $name = trim($prefix, "\\ \t\n\r\0\x0B").'\\'.$name;
        }

        $separator = strrpos($name, '\\');
        $alias = isset($parts[1]) ? trim($parts[1]) : substr($name, $separator === false ? 0 : $separator + 1);

        if ($alias !== '') {
            $imports[strtolower($alias)] = $name;
        }
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     * @param  array<string, string>  $imports
     */
    private static function hasTopLevelComponentClass(array $tokens, array $imports, string $componentClass): bool
    {
        $depth = 0;
        $pendingNew = false;
        $previousTopLevel = null;
        $foundNew = false;

        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_NEW && ! $foundNew) {
                $foundNew = true;
                if (! self::canStartTopLevelAnonymousClass($depth, $previousTopLevel)) {
                    return false;
                }

                $pendingNew = true;
                $previousTopLevel = $token;

                continue;
            }

            if ($depth === 0 && ! self::isTrivia($token)) {
                if (! $foundNew) {
                    $previousTopLevel = $token;
                } elseif ($pendingNew && is_array($token) && in_array($token[0], [T_ATTRIBUTE, T_READONLY], true)) {
                    // Attributes and readonly may appear between new and class.
                } elseif ($pendingNew && is_array($token) && $token[0] === T_CLASS) {
                    $parent = self::parentName($tokens, $index);

                    return $parent !== null
                        && self::resolveClassName($parent, $imports) === $componentClass;
                } else {
                    return false;
                }

                $previousTopLevel = $token;
            }

            $depth += PhpSource::nestingDelta($token);
        }

        return false;
    }

    /** @param array{int, string, int}|string|null $previous */
    private static function canStartTopLevelAnonymousClass(int $depth, array|string|null $previous): bool
    {
        if ($depth !== 0) {
            return false;
        }

        return $previous === null
            || $previous === ';'
            || (is_array($previous) && $previous[0] === T_RETURN);
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private static function parentName(array $tokens, int $classIndex): ?string
    {
        $depth = 0;

        for ($index = $classIndex + 1, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if ($depth === 0 && is_array($token) && $token[0] === T_EXTENDS) {
                return self::className($tokens, $index + 1);
            }

            if ($depth === 0 && $token === '{') {
                return null;
            }

            $depth += PhpSource::nestingDelta($token);
        }

        return null;
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     */
    private static function className(array $tokens, int $start): ?string
    {
        $name = '';

        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if (self::isTrivia($token)) {
                if ($name === '') {
                    continue;
                }

                break;
            }

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR], true)) {
                $name .= $token[1];

                continue;
            }

            if ($token === '\\') {
                $name .= $token;

                continue;
            }

            break;
        }

        return $name === '' ? null : $name;
    }

    /** @param array<string, string> $imports */
    private static function resolveClassName(string $name, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return strtolower(ltrim($name, '\\'));
        }

        $normalized = strtolower($name);
        if (str_starts_with($normalized, 'namespace\\')) {
            return '';
        }

        $segments = explode('\\', $name, 2);
        $first = $segments[0];
        $remainder = $segments[1] ?? null;
        $import = $imports[strtolower($first)] ?? null;

        return strtolower($import === null || $remainder === null
            ? ($import ?? $name)
            : $import.'\\'.$remainder);
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     * @return array{int, string, int}|string|null
     */
    private static function significantToken(array $tokens, int $start): array|string|null
    {
        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            if (! self::isTrivia($tokens[$index])) {
                return $tokens[$index];
            }
        }

        return null;
    }

    /** @param array{int, string, int}|string $token */
    private static function isTrivia(array|string $token): bool
    {
        return is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);
    }
}
