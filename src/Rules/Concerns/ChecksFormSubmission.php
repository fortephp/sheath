<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Concerns;

use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Sheath\Support\ReactiveAttributeSemantics;

/** @internal */
trait ChecksFormSubmission
{
    /** @return list<string> */
    protected function reactiveSubmissionDirectiveNames(ElementNode $form): array
    {
        $names = [];

        foreach ($this->attributesInRenderStructure($form) as $attribute) {
            if (ReactiveAttributeSemantics::isFormSubmissionDirective($attribute)) {
                $names[strtolower($attribute->name()->rawName())] = true;
            }
        }

        return array_keys($names);
    }

    /** @param list<Attribute> $path */
    protected function formPathIsDurablyIntercepted(array $path, ElementNode $form): bool
    {
        foreach ($path as $attribute) {
            if (ReactiveAttributeSemantics::preventsNativeFormSubmission($attribute, $form)) {
                return true;
            }
        }

        return false;
    }

    protected function nodeCreatesSuccessfulFormControl(Node $node): bool
    {
        if ($node instanceof ElementNode && $node->hasAttribute('disabled')) {
            return false;
        }

        foreach ($node->ancestors() as $ancestor) {
            if (! $ancestor instanceof ElementNode) {
                continue;
            }

            $tagName = strtolower($ancestor->tagNameText());
            if ($tagName === 'template'
                && ! ReactiveAttributeSemantics::rendersTemplateContent($ancestor)) {
                return false;
            }

            if ($tagName === 'datalist') {
                return false;
            }

            if ($tagName !== 'fieldset' || ! $ancestor->hasAttribute('disabled')) {
                continue;
            }

            $firstLegend = null;
            foreach ($ancestor->children() as $child) {
                if ($child instanceof ElementNode && $child->isTag('legend')) {
                    $firstLegend = $child;

                    break;
                }
            }

            if ($firstLegend === null || ! $firstLegend->contains($node)) {
                return false;
            }
        }

        return true;
    }
}
