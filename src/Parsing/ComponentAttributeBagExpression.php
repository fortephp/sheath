<?php

declare(strict_types=1);

namespace Forte\Sheath\Parsing;

use Illuminate\View\ComponentAttributeBag;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

/** @internal */
final class ComponentAttributeBagExpression
{
    private const LARAVEL_ATTRIBUTE_BAG = ComponentAttributeBag::class;

    /**
     * Framework methods whose result remains a ComponentAttributeBag and is
     * therefore safe to stringify in a raw attribute-list echo.
     *
     * @var list<string>
     */
    private const BAG_RETURNING_METHODS = [
        'class',
        'except',
        'exceptprops',
        'filter',
        'merge',
        'only',
        'onlyprops',
        'style',
        'thatstartwith',
        'wheredoesntstartwith',
        'wherestartswith',
    ];

    /**
     * Recognize the method-chain shape Laravel accepts after `$attributes` in
     * component-tag attribute spreading. Method names are intentionally not
     * enumerated here: compiler integrity depends on the grammar, while return
     * type and escaping are a separate concern.
     */
    public static function isBladeAttributeBagChain(string $expression): bool
    {
        return self::parseMethodChain($expression, null);
    }

    /**
     * Recognize chains that are guaranteed by the framework to keep returning
     * an escaped ComponentAttributeBag.
     */
    public static function isEscapedAttributeBagChain(string $expression): bool
    {
        return self::parseMethodChain($expression, self::escapedBagReturningMethods());
    }

    /** @return list<string> */
    private static function escapedBagReturningMethods(): array
    {
        /** @var list<string>|null $methods */
        static $methods = null;

        if ($methods !== null) {
            return $methods;
        }

        $known = array_fill_keys(self::BAG_RETURNING_METHODS, true);

        foreach (self::reflectedBagReturningMethods(self::LARAVEL_ATTRIBUTE_BAG) as $method) {
            $known[$method] = true;
        }

        return $methods = array_keys($known);
    }

    /**
     * Discover only methods whose declarations guarantee that the result is
     * still an attribute bag. Reflection is an optional augmentation: missing
     * framework classes, incomplete types, and reflection failures all add
     * nothing to the conservative built-in list.
     *
     * @return list<string>
     */
    private static function reflectedBagReturningMethods(string $bagClass): array
    {
        try {
            if (! class_exists($bagClass)) {
                return [];
            }

            $reflection = new ReflectionClass($bagClass);
            $methods = [];

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor() || $method->isDestructor()) {
                    continue;
                }

                $returnType = $method->getReturnType();
                $returnsBag = $returnType !== null
                    ? self::typeGuaranteesBag($returnType, $reflection, $method)
                    : self::docblockGuaranteesBag($method, $reflection);

                if ($returnsBag) {
                    $methods[] = strtolower($method->getName());
                }
            }

            return array_values(array_unique($methods));
        } catch (\Throwable) {
            return [];
        }
    }

    /** @param ReflectionClass<object> $bag */
    private static function typeGuaranteesBag(
        ReflectionType $type,
        ReflectionClass $bag,
        ReflectionMethod $method,
    ): bool {
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                if (! self::typeGuaranteesBag($member, $bag, $method)) {
                    return false;
                }
            }

            return true;
        }

        if ($type instanceof ReflectionIntersectionType) {
            foreach ($type->getTypes() as $member) {
                if (self::typeGuaranteesBag($member, $bag, $method)) {
                    return true;
                }
            }

            return false;
        }

        if (! $type instanceof ReflectionNamedType || $type->allowsNull() || $type->isBuiltin()) {
            return false;
        }

        $name = strtolower($type->getName());
        if (in_array($name, ['self', 'static'], true)) {
            return self::classIsBag($method->getDeclaringClass(), $bag);
        }

        if ($name === 'parent') {
            $parent = $method->getDeclaringClass()->getParentClass();

            return $parent !== false && self::classIsBag($parent, $bag);
        }

        if (strcasecmp(ltrim($type->getName(), '\\'), $bag->getName()) === 0) {
            return true;
        }

        // Avoid autoloading arbitrary return types merely to classify a lint
        // expression. Already-loaded subtypes can still be proven safely.
        return class_exists($type->getName(), false)
            && is_a($type->getName(), $bag->getName(), true);
    }

    /** @param ReflectionClass<object> $bag */
    private static function docblockGuaranteesBag(ReflectionMethod $method, ReflectionClass $bag): bool
    {
        $docblock = $method->getDocComment();
        if ($docblock === false
            || preg_match('/@return\s+([^\s*]+)/', $docblock, $matches) !== 1) {
            return false;
        }

        $return = ltrim($matches[1], '\\');
        if (in_array(strtolower($return), ['self', 'static', '$this'], true)) {
            return self::classIsBag($method->getDeclaringClass(), $bag);
        }

        return strcasecmp($return, $bag->getName()) === 0
            || (strcasecmp($return, $bag->getShortName()) === 0
                && self::classIsBag($method->getDeclaringClass(), $bag));
    }

    /**
     * @param  ReflectionClass<object>  $candidate
     * @param  ReflectionClass<object>  $bag
     */
    private static function classIsBag(ReflectionClass $candidate, ReflectionClass $bag): bool
    {
        return $candidate->getName() === $bag->getName()
            || $candidate->isSubclassOf($bag->getName());
    }

    /**
     * @param  list<string>|null  $allowedMethods
     */
    private static function parseMethodChain(string $expression, ?array $allowedMethods): bool
    {
        $expression = trim($expression);
        if (! PhpSource::parses("return {$expression}")) {
            return false;
        }

        $tokens = PhpSource::tokenize($expression);
        if ($tokens === null) {
            return false;
        }

        $tokens = array_values(array_filter(
            $tokens,
            static fn (array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        if (! isset($tokens[0]) || ! is_array($tokens[0])
            || $tokens[0][0] !== T_VARIABLE || $tokens[0][1] !== '$attributes') {
            return false;
        }

        $index = 1;
        $count = count($tokens);

        while ($index < $count) {
            $method = self::methodNameAt($tokens, $index);
            if ($method === null) {
                return false;
            }

            if ($allowedMethods !== null && ! in_array(strtolower($method), $allowedMethods, true)) {
                return false;
            }

            $index += 2;
            $depth = 0;

            for (; $index < $count; $index++) {
                $token = $tokens[$index];
                $depth += PhpSource::nestingDelta($token);

                if ($depth === 0) {
                    $index++;
                    break;
                }
            }

            if ($depth !== 0) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private static function methodNameAt(array $tokens, int $index): ?string
    {
        $operator = $tokens[$index] ?? null;
        $method = $tokens[$index + 1] ?? null;

        if (! is_array($operator) || $operator[0] !== T_OBJECT_OPERATOR) {
            return null;
        }

        if (! is_array($method) || $method[0] !== T_STRING) {
            return null;
        }

        return ($tokens[$index + 2] ?? null) === '(' ? $method[1] : null;
    }
}
