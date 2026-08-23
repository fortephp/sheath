<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\BestPractices\Attributes;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoDuplicateAttrsRule extends AbstractRule
{
    use DetectsOpaqueAttributes;

    public function getId(): string
    {
        return 'best-practices-no-duplicate-attrs';
    }

    public function getDescription(): string
    {
        return 'Disallow duplicate attributes on the same element.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BEST_PRACTICES;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $source = $document->source();

        $context->elements()
            ->each(function (ElementNode $element) use ($context, $source): void {
                $this->checkElement($element, $context, $source);
            });

        $document
            ->queryComponents()
            ->each(function (ElementNode $component) use ($context, $source): void {
                $this->checkElement($component, $context, $source, isComponent: true);
            });
    }

    private function checkElement(ElementNode $element, RuleContext $context, string $source, bool $isComponent = false): void
    {
        $paths = $this->explicitAttributeRenderPaths($element);
        if ($paths === null) {
            return;
        }

        /** @var array<string, array{Attribute, Attribute}> $duplicates */
        $duplicates = [];

        foreach ($paths as $attributes) {
            /** @var array<string, Attribute> $firstOccurrences */
            $firstOccurrences = [];

            foreach ($attributes as $attribute) {
                $attrName = $this->attributeKey($attribute, $isComponent);

                if (! isset($firstOccurrences[$attrName])) {
                    $firstOccurrences[$attrName] = $attribute;

                    continue;
                }

                $duplicates[$attrName] ??= [$firstOccurrences[$attrName], $attribute];
            }
        }

        foreach ($this->knownAttributeDirectiveEffects($element) as $effect) {
            $attrName = $isComponent ? $effect['name'] : strtolower($effect['name']);
            if (isset($duplicates[$attrName]) || $this->directiveIsPresenceControlled($effect['directive'])) {
                continue;
            }

            foreach ($this->attributesInRenderStructure($element, $effect['name']) as $attribute) {
                if ($this->conditionalBranchProvidingAttribute($element, $attribute) !== null) {
                    continue;
                }

                $duplicates[$attrName] = [$attribute, $attribute];
                break;
            }
        }

        foreach ($duplicates as $attrName => [$firstOccurrence, $firstRepeat]) {
            $removable = $isComponent ? $firstOccurrence : $firstRepeat;
            $retained = $isComponent ? $firstRepeat : $firstOccurrence;
            $fix = $retained->startOffset() !== $removable->startOffset()
                && $this->attributeRendersWhenever($retained, $removable, $element)
                    ? $this->createRemoveDuplicateFix($removable, $source)
                    : null;

            $context->report(
                $element,
                "Duplicate attribute '{$attrName}' found on <{$element->tagNameText()}> element",
                $fix,
            );
        }
    }

    private function directiveIsPresenceControlled(DirectiveNode $directive): bool
    {
        foreach ($directive->ancestors() as $ancestor) {
            if ($ancestor instanceof DirectiveBlockNode
                && ! in_array(strtolower($ancestor->nameText()), ['php', 'verbatim'], true)) {
                return true;
            }
        }

        return false;
    }

    private function attributeRendersWhenever(Attribute $required, Attribute $subject, ElementNode $element): bool
    {
        $requiredBranch = $this->conditionalBranchProvidingAttribute($element, $required);
        $subjectBranch = $this->conditionalBranchProvidingAttribute($element, $subject);

        if ($requiredBranch === null) {
            return true;
        }

        return $subjectBranch !== null
            && $requiredBranch->index() === $subjectBranch->index();
    }

    private function attributeKey(Attribute $attribute, bool $isComponent): string
    {
        if (! $isComponent) {
            return strtolower($attribute->name()->rawName());
        }

        if ($attribute->isBound() || $attribute->isVariableShorthand()) {
            return $attribute->nameText();
        }

        if ($attribute->isEscaped()) {
            return substr($attribute->name()->rawName(), 1);
        }

        return $attribute->name()->rawName();
    }

    private function createRemoveDuplicateFix(Attribute $attr, string $source): ?Fix
    {
        if ($attr->isBladeConstruct() || $attr->hasComplexValue()) {
            return null;
        }

        $startOffset = $attr->startOffset();
        $endOffset = $attr->endOffset();

        if ($startOffset < 0) {
            return null;
        }

        return new Fix(
            $this->findWhitespaceStart($source, $startOffset),
            $endOffset, ''
        );
    }
}
