<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Components;

use Forte\Ast\Components\ComponentNode;
use Forte\Ast\Document\Document;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ReportsWithFix;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class ComponentSelfClosingRule extends AbstractRule
{
    use ReportsWithFix;

    public function getId(): string
    {
        return 'blade-component-self-closing';
    }

    public function getDescription(): string
    {
        return 'Empty Blade components must be self-closing.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::INFO;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $document
            ->queryComponents()
            ->filter(fn (ComponentNode $el) => $el->isPaired())
            ->filter(fn (ComponentNode $el) => $this->isEmpty($el))
            ->each(function (ComponentNode $component) use ($context): void {
                $tagName = $component->tagNameText();

                $context->report(
                    $component,
                    "Empty component <{$tagName}> is not self-closing.",
                    $this->createCollapseToSelfClosingFix($component)
                );
            });
    }

    private function isEmpty(ComponentNode $element): bool
    {
        foreach ($element->children() as $child) {
            return false;
        }

        return true;
    }
}
