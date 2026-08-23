<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Headings;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Accessibility\Aria\NoInvalidRoleRule;
use Forte\Sheath\Rules\Concerns\TraversesRenderedTree;
use Forte\Sheath\Rules\Concerns\ValidatesHeadings;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoHeadingInsideButtonRule extends AbstractRule
{
    use TraversesRenderedTree;
    use ValidatesHeadings;

    public function getId(): string
    {
        return 'a11y-no-heading-inside-button';
    }

    public function getDescription(): string
    {
        return 'Heading elements should not be nested inside buttons.';
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
        $context->elements()->each(function (ElementNode $heading) use ($context): void {
            if (! $this->isHeading($heading)
                || $this->isInsideTemplate($heading)
                || ! $this->hasButtonAncestor($heading)) {
                return;
            }

            $tagName = strtolower($heading->tagNameText());
            $context->report(
                $heading,
                "Heading <{$tagName}> is nested inside a button."
            );
        });
    }

    private function hasButtonAncestor(ElementNode $heading): bool
    {
        $ancestor = $this->renderedParentElement($heading);

        while ($ancestor !== null) {
            if ($ancestor->isTag('template')) {
                return false;
            }

            if ($ancestor->isTag('button')
                || $this->mayHaveButtonRole($ancestor)) {
                return true;
            }

            $ancestor = $this->renderedParentElement($ancestor);
        }

        return false;
    }

    private function mayHaveButtonRole(ElementNode $element): bool
    {
        $roles = $this->firstAttributesOnRenderPaths($element, 'role');
        if ($roles === null) {
            return false;
        }

        foreach ($roles as $roleAttribute) {
            if ($roleAttribute === null) {
                continue;
            }
            if ($roleAttribute->isDynamic()) {
                continue;
            }

            foreach ($roleAttribute->tokensLower() as $role) {
                if (NoInvalidRoleRule::isValidRole($role)) {
                    if ($role === 'button') {
                        return true;
                    }

                    break;
                }
            }
        }

        return false;
    }
}
