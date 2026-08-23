<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Aria;

use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Sheath\Rules\Concerns\ResolvesAccessibilityTree;
use Forte\Sheath\Support\ReactiveAttributeSemantics;
use Forte\Support\HtmlWhitespace;

/** @internal */
trait ChecksAriaConformance
{
    use ResolvesAccessibilityTree;

    protected function ariaRoleApplicabilityIsAmbiguous(ElementNode $element): bool
    {
        $candidate = $element;

        while ($candidate !== null) {
            if ($candidate instanceof ElementNode) {
                $paths = $this->explicitAttributeRenderPaths(
                    $candidate,
                    ['hidden', 'inert', 'aria-hidden'],
                );
                if ($paths === null) {
                    return true;
                }

                $hasExcluded = false;
                $hasExposed = false;
                foreach ($paths as $path) {
                    foreach ($path as $attribute) {
                        if ($attribute->isNamed(['hidden', 'inert', 'aria-hidden'])
                            && ($attribute->isDynamic() || $attribute->isBound())) {
                            return true;
                        }
                    }

                    if ($this->accessibilityAttributePathIsExcluded($path)) {
                        $hasExcluded = true;
                    } else {
                        $hasExposed = true;
                    }
                }

                if ($hasExcluded && $hasExposed) {
                    return true;
                }
            }

            $parent = $candidate->getParent();
            $candidate = $parent instanceof Node ? $parent : null;
        }

        return false;
    }

    /** @return list<array{attribute: Attribute, name: string}> */
    protected function explicitAriaAttributes(ElementNode $element): array
    {
        $attributes = [];

        foreach ($this->attributesInRenderStructure($element) as $attribute) {
            $name = $this->ariaAttributeName($attribute);
            if ($name !== null) {
                $attributes[] = ['attribute' => $attribute, 'name' => $name];
            }
        }

        return $attributes;
    }

    protected function ariaAttributeName(Attribute $attribute): ?string
    {
        $name = strtolower($attribute->name()->rawName());
        $boundName = ReactiveAttributeSemantics::boundAttributeName($attribute);
        if ($boundName !== null) {
            $name = $boundName;
        } elseif (str_starts_with($name, ':')) {
            $name = substr($name, 1);
        }

        return str_starts_with($name, 'aria-') ? $name : null;
    }

    protected function ariaAttributeValueIsDynamic(Attribute $attribute): bool
    {
        $name = strtolower($attribute->name()->rawName());

        return $attribute->isDynamic()
            || $attribute->isBound()
            || ReactiveAttributeSemantics::boundAttributeName($attribute) !== null
            || str_starts_with($name, ':');
    }

    /** @param list<Attribute> $path */
    protected function firstAriaAttributeOnPath(array $path, string $name): ?Attribute
    {
        foreach ($path as $attribute) {
            if ($this->ariaAttributeName($attribute) === $name) {
                return $attribute;
            }
        }

        return null;
    }

    /** @param array{type: string, values?: list<string>, allowUndefined?: bool, min?: int, allowMinusOne?: bool} $definition */
    protected function staticAriaValueIsValid(Attribute $attribute, array $definition): bool
    {
        if ($this->ariaAttributeValueIsDynamic($attribute)) {
            return true;
        }

        $value = HtmlWhitespace::trim($attribute->decodedValueText() ?? '');
        // ARIA 1.2 treats a zero-length supported property like an absent one.
        if ($value === '') {
            return true;
        }

        $lower = strtolower($value);

        return match ($definition['type']) {
            'boolean' => in_array($lower, [
                'true', 'false', ...(($definition['allowUndefined'] ?? false) ? ['undefined'] : []),
            ], true),
            'tristate' => in_array($lower, ['true', 'false', 'mixed', 'undefined'], true),
            'token' => in_array($lower, $definition['values'] ?? [], true),
            'tokenlist' => $this->tokensAreAllowed($attribute, $definition['values'] ?? []),
            'integer' => $this->integerValueIsValid($value, $definition),
            'number' => preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', $value) === 1,
            'id' => count($attribute->tokens()) === 1,
            'idlist' => $attribute->tokens() !== [],
            'string' => true,
            default => false,
        };
    }

    /** @param list<string> $allowed */
    private function tokensAreAllowed(Attribute $attribute, array $allowed): bool
    {
        $tokens = $attribute->tokensLower();
        if ($tokens === []) {
            return false;
        }

        foreach ($tokens as $token) {
            if (! in_array($token, $allowed, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array{type: string, min?: int, allowMinusOne?: bool} $definition */
    private function integerValueIsValid(string $value, array $definition): bool
    {
        if (preg_match('/^[+-]?\d+$/D', $value) !== 1) {
            return false;
        }

        $negative = str_starts_with($value, '-');
        $digits = ltrim($value, '+-');
        $digits = ltrim($digits, '0');
        $isZero = $digits === '';

        if (($definition['allowMinusOne'] ?? false)
            && $negative
            && $digits === '1') {
            return true;
        }

        if (! isset($definition['min'])) {
            return true;
        }

        if ($negative && ! $isZero) {
            return false;
        }

        return $definition['min'] === 0 || ! $isZero;
    }
}
