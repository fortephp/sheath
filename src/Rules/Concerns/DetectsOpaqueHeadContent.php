<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Concerns;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\EchoNode;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;

/** @internal */
trait DetectsOpaqueHeadContent
{
    private const OPAQUE_HEAD_DIRECTIVES = [
        'include',
        'includeif',
        'includewhen',
        'includeunless',
        'includefirst',
        'each',
        'yield',
        'stack',
    ];

    protected function headContainsOpaqueContent(ElementNode $head): bool
    {
        return $this->elementContainsOpaqueContent($head);
    }

    protected function elementContainsOpaqueContent(ElementNode $container): bool
    {
        foreach ($container->descendants() as $node) {
            if ($this->isOpaqueHeadContentNode($node)) {
                return true;
            }
        }

        return false;
    }

    protected function isOpaqueHeadContentNode(Node $node): bool
    {
        if ($node instanceof ElementNode && $this->isOpaqueTag($node)) {
            return true;
        }

        if (($node instanceof DirectiveNode || $node instanceof DirectiveBlockNode)
            && $node->isAnyDirectiveNamed(self::OPAQUE_HEAD_DIRECTIVES)) {
            return true;
        }

        return $node instanceof EchoNode && $node->isRaw();
    }

    private function isOpaqueTag(ElementNode $element): bool
    {
        $tagName = strtolower($element->tagNameText());

        return str_starts_with($tagName, 'x-') || str_contains($tagName, ':');
    }
}
