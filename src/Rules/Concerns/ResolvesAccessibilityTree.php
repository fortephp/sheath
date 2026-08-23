<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Concerns;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;

/** @internal */
trait ResolvesAccessibilityTree
{
    use DetectsOpaqueAttributes;

    /** @param list<Attribute> $path */
    protected function firstAccessibilityAttributeOnPath(array $path, string $name): ?Attribute
    {
        return $this->firstAttributeOnRenderPath($path, $name);
    }

    /** @param list<Attribute> $path */
    protected function accessibilityAttributePathIsExcluded(array $path): bool
    {
        foreach (['hidden', 'inert'] as $name) {
            $attribute = $this->firstAttributeOnRenderPath($path, $name);
            if ($attribute !== null && ! $attribute->isBound()) {
                return true;
            }
        }

        $ariaHidden = $this->firstAttributeOnRenderPath($path, 'aria-hidden');

        return $ariaHidden !== null
            && ! $ariaHidden->isDynamic()
            && strtolower($ariaHidden->decodedValueText() ?? '') === 'true';
    }

    protected function isUnconditionallyExcludedFromAccessibilityTree(ElementNode $element): bool
    {
        $candidate = $element;

        while ($candidate !== null) {
            if ($candidate instanceof ElementNode) {
                $paths = $this->explicitAttributeRenderPaths($candidate, ['hidden', 'inert', 'aria-hidden']);
                if ($paths !== null && $paths !== []) {
                    $allExcluded = true;
                    foreach ($paths as $path) {
                        if (! $this->accessibilityAttributePathIsExcluded($path)) {
                            $allExcluded = false;
                            break;
                        }
                    }
                    if ($allExcluded) {
                        return true;
                    }
                }
            } elseif ($candidate instanceof DirectiveBlockNode
                && $this->accessibilityCaptureStopsAncestry($candidate)) {
                return false;
            }

            $candidate = $candidate->getParent();
        }

        return false;
    }

    private function accessibilityCaptureStopsAncestry(DirectiveBlockNode $block): bool
    {
        $name = strtolower($block->nameText());
        if (in_array($name, ['push', 'pushif', 'pushonce', 'prepend', 'prependonce'], true)) {
            return true;
        }
        if ($name !== 'section') {
            return false;
        }

        if ($block->endDirective()?->isDirectiveNamed('show') ?? false) {
            return false;
        }

        foreach ($block->intermediateDirectives() as $directive) {
            if (strtolower($directive->nameText()) === 'show') {
                return false;
            }
        }

        return true;
    }
}
