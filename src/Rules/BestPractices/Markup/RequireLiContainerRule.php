<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\BestPractices\Markup;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Accessibility\Structure\ListSemanticsRule;
use Forte\Sheath\Rules\Concerns\TraversesRenderedTree;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class RequireLiContainerRule extends AbstractRule
{
    use TraversesRenderedTree;

    private const LIST_SEMANTICS_RULE_ID = 'a11y-list-semantics';

    private const VALID_PARENTS = ['ul', 'ol', 'menu'];

    public function getId(): string
    {
        return 'best-practices-require-li-container';
    }

    public function getDescription(): string
    {
        return 'List items (<li>) must be inside <ul>, <ol>, or <menu> elements.';
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
        if ($this->listSemanticsIsReportedElsewhere($context)) {
            return;
        }

        $document->queryElements('li')
            ->each(function (ElementNode $li) use ($context): void {
                $parent = $this->renderedParentElement($li);

                if ($parent === null) {
                    return;
                }

                $parentTag = strtolower($parent->tagNameText());
                if (! in_array($parentTag, self::VALID_PARENTS, true)) {
                    $context->report(
                        $li,
                        "List item <li> has invalid parent <{$parentTag}>."
                    );
                }
            });
    }

    private function listSemanticsIsReportedElsewhere(RuleContext $context): bool
    {
        return $this->configuredRuleShouldReport(
            $context,
            self::LIST_SEMANTICS_RULE_ID,
            (new ListSemanticsRule)->getDefaultSeverity(),
        );
    }
}
