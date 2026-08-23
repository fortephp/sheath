<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\BestPractices\Attributes;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\AttributeSerializer;
use Forte\Support\AttributeQuoting;
use Forte\Support\HtmlCharacterReferences;
use Forte\Support\HtmlWhitespace;

/** @internal */
class NoDuplicateClassRule extends AbstractRule
{
    use DetectsOpaqueAttributes;

    public function getId(): string
    {
        return 'best-practices-no-duplicate-class';
    }

    public function getDescription(): string
    {
        return 'Disallow duplicate class names in the class attribute.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BEST_PRACTICES;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $context->elements()
            ->filter(fn (ElementNode $el) => $this->attributesInRenderStructure($el, 'class') !== [])
            ->each(function (ElementNode $element) use ($context): void {
                $classAttributes = $this->firstAttributesOnRenderPaths($element, 'class');
                if ($classAttributes === null) {
                    return;
                }

                foreach ($classAttributes as $classAttr) {
                    if ($classAttr === null) {
                        continue;
                    }

                    $classes = $this->completeClassNames($classAttr);

                    if ($classes === []) {
                        continue;
                    }

                    $seen = [];
                    $duplicates = [];

                    foreach ($classes as $class) {
                        if (isset($seen[$class])) {
                            $duplicates[$class] = true;
                        } else {
                            $seen[$class] = true;
                        }
                    }

                    if ($duplicates === []) {
                        continue;
                    }

                    $duplicateList = implode(', ', array_keys($duplicates));
                    $context->report(
                        $element,
                        "Duplicate class name(s) found: {$duplicateList}",
                        $this->createDeduplicateFix($classAttr, $classes)
                    );

                    return;
                }
            });
    }

    /**
     * @return array<string>
     */
    private function completeClassNames(Attribute $attribute): array
    {
        $value = $attribute->value();

        if ($value === null) {
            return [];
        }

        /** @var array<int, Node|string> $parts */
        $parts = $value->getParts();
        $lastIndex = count($parts) - 1;
        $names = [];

        foreach ($parts as $index => $part) {
            if (! is_string($part)) {
                continue;
            }

            $part = HtmlCharacterReferences::decodeAttribute($part);
            $tokens = HtmlWhitespace::split($part);

            if ($tokens === []) {
                continue;
            }

            $fusedLeft = $index > 0 && ! HtmlWhitespace::startsWith($part);
            $fusedRight = $index < $lastIndex && ! HtmlWhitespace::endsWith($part);

            if ($fusedLeft) {
                array_shift($tokens);
            }

            if ($fusedRight && $tokens !== []) {
                array_pop($tokens);
            }

            foreach ($tokens as $token) {
                $names[] = $token;
            }
        }

        return $names;
    }

    /**
     * @param  array<string>  $classes
     */
    private function createDeduplicateFix(Attribute $classAttr, array $classes): ?Fix
    {
        if ($classAttr->hasComplexValue()) {
            return null;
        }

        $startOffset = $classAttr->startOffset();
        $endOffset = $classAttr->endOffset();

        if ($startOffset < 0 || $endOffset <= $startOffset) {
            return null;
        }

        $seen = [];
        $unique = [];
        foreach ($classes as $class) {
            if (! isset($seen[$class])) {
                $seen[$class] = true;
                $unique[] = $class;
            }
        }

        $quote = AttributeQuoting::styleOf($classAttr);
        $replacement = AttributeSerializer::render($classAttr->nameText(), implode(' ', $unique), $quote);

        return new Fix($startOffset, $endOffset, $replacement);
    }
}
