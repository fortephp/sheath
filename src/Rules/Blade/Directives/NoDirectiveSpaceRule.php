<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Directives;

use Forte\Ast\Components\ComponentNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoDirectiveSpaceRule extends AbstractRule
{
    public function getId(): string
    {
        return 'blade-no-directive-space';
    }

    public function getDescription(): string
    {
        return 'No space between directive name and arguments in component tags.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $document->queryComponents()->each(function (ComponentNode $component) use ($context): void {
            foreach ($component->attributes()->directives() as $attr) {
                /** @var Attribute $attr */
                $directive = $attr->getBladeConstruct();

                if (! $directive instanceof DirectiveNode) {
                    continue;
                }

                if (! $directive->hasArguments()) {
                    continue;
                }

                $whitespace = $directive->whitespaceBetweenNameAndArgs();

                if ($whitespace === null) {
                    continue;
                }

                $name = $directive->nameText();

                $context->report(
                    $directive,
                    "Unexpected space between '@{$name}' and its arguments.",
                    $this->createFix($directive)
                );
            }
        });
    }

    private function createFix(DirectiveNode $directive): Fix
    {
        $flat = $directive->getFlatNode();
        $tokenStart = $flat['tokenStart'];
        $document = $directive->getDocument();
        $wsToken = $document->getToken($tokenStart + 1);

        return new Fix($wsToken['start'], $wsToken['end'], '');
    }
}
