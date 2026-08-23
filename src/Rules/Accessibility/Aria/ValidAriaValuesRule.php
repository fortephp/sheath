<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Aria;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class ValidAriaValuesRule extends AbstractRule
{
    use ChecksAriaConformance;

    public function getId(): string
    {
        return 'a11y-valid-aria-values';
    }

    public function getDescription(): string
    {
        return 'Static ARIA attribute values must match their WAI-ARIA 1.2 grammar.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::ACCESSIBILITY;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $context->elements()->each(function (ElementNode $element) use ($context): void {
            foreach ($this->explicitAriaAttributes($element) as $item) {
                $definition = Aria12Data::property($item['name']);
                if ($definition === null
                    || $this->ariaAttributeValueIsDynamic($item['attribute'])
                    || $this->staticAriaValueIsValid($item['attribute'], $definition)) {
                    continue;
                }

                $value = $item['attribute']->decodedValueText() ?? '';
                $context->report(
                    $element,
                    "Invalid value \"{$value}\" for {$item['name']}; expected {$this->grammarDescription($definition)}."
                );
            }
        });
    }

    /** @param array{type: string, values?: list<string>, allowUndefined?: bool, min?: int, allowMinusOne?: bool} $definition */
    private function grammarDescription(array $definition): string
    {
        if (isset($definition['values'])) {
            return implode(', ', $definition['values']);
        }

        return match ($definition['type']) {
            'boolean' => ($definition['allowUndefined'] ?? false)
                ? 'true, false, or undefined'
                : 'true or false',
            'tristate' => 'true, false, mixed, or undefined',
            'id' => 'one ID reference',
            'idlist' => 'one or more ID references',
            'integer' => isset($definition['min'])
                ? (($definition['allowMinusOne'] ?? false) ? '-1 or ' : '')."an integer >= {$definition['min']}"
                : 'integer',
            default => $definition['type'],
        };
    }
}
