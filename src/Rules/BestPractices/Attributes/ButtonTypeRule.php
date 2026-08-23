<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\BestPractices\Attributes;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\Concerns\ReportsWithFix;
use Forte\Sheath\Rules\Concerns\TraversesRenderedTree;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class ButtonTypeRule extends AbstractRule
{
    use DetectsOpaqueAttributes;
    use ReportsWithFix;
    use TraversesRenderedTree;

    private const VALID_TYPES = ['submit', 'button', 'reset'];

    public function getId(): string
    {
        return 'best-practices-button-type';
    }

    public function getDescription(): string
    {
        return 'Buttons should have an explicit type attribute to prevent accidental form submission.';
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
        $document->queryElements('button')
            ->each(function (ElementNode $button) use ($context): void {
                if (! $this->everyAttributeRenderPathIsSatisfied($button, 'type')) {
                    $type = $this->isFormAssociated($button) ? 'submit' : 'button';

                    $dangerous = $type === 'button' && ! $this->isCompletePage($button);
                    $allTypeAttributes = $this->attributesInRenderStructure($button, 'type');

                    $context->report(
                        $button,
                        'Button is missing an explicit type attribute.',
                        $allTypeAttributes === []
                            ? $this->createAddAttributeFix($button, 'type', $type, $dangerous)
                            : null
                    );

                    return;
                }

                $typeAttributes = $this->firstAttributesOnRenderPaths($button, 'type');
                if ($typeAttributes === null) {
                    return;
                }

                $seen = [];
                foreach ($typeAttributes as $typeAttribute) {
                    if ($typeAttribute === null || isset($seen[spl_object_id($typeAttribute)])) {
                        continue;
                    }
                    $seen[spl_object_id($typeAttribute)] = true;

                    if ($typeAttribute->isDynamic()) {
                        continue;
                    }

                    $typeValue = strtolower($typeAttribute->decodedValueText() ?? '');

                    if (! in_array($typeValue, self::VALID_TYPES, true)) {
                        $context->report(
                            $button,
                            "Invalid button type '{$typeValue}'."
                        );

                        return;
                    }
                }
            });
    }

    private function isFormAssociated(ElementNode $button): bool
    {
        if ($this->attributesInRenderStructure($button, 'form') !== []) {
            return true;
        }

        $parent = $this->renderedParentElement($button);
        while ($parent !== null) {
            if ($parent->isTag('form')) {
                return true;
            }

            $parent = $this->renderedParentElement($parent);
        }

        return false;
    }

    private function isCompletePage(ElementNode $button): bool
    {
        return $button->hasAncestorElement(['body', 'html']);
    }
}
