<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Components;

use Forte\Ast\Components\ComponentNode;
use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\EchoNode;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\StrayClosingTagNode;
use Forte\Sheath\Parsing\ComponentAttributeBagExpression;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsDirectiveAttributeCollisions;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\ReactiveAttributeSemantics;

/** @internal */
class ComponentTagIntegrityRule extends AbstractRule
{
    use DetectsDirectiveAttributeCollisions;

    /**
     * @var array<string>
     */
    private const ALLOWED_ATTRIBUTE_DIRECTIVES = ['class', 'style'];

    public function getId(): string
    {
        return 'blade-component-tag-integrity';
    }

    public function getDescription(): string
    {
        return 'Blade and Livewire component tags require valid attributes and matching closing tags.';
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
        $document->queryComponents()->each(function (ComponentNode $component) use ($document, $context): void {
            if (! $this->isCompiledComponentTag($component)) {
                return;
            }

            foreach ($component->attributes() as $attribute) {
                $this->checkBoundAttributeEcho($attribute, $document, $context);
                $this->checkBoundAttributeExpression($attribute, $document, $context);
                $this->checkAttributePositionConstruct($attribute, $component, $document, $context);
            }

            $this->checkUnclosedTag($component, $context);
        });

        $document->allOfType(StrayClosingTagNode::class)->each(function (StrayClosingTagNode $closer) use ($context): void {
            $this->checkStrayCloser($closer, $context);
        });
    }

    private function checkBoundAttributeExpression(
        Attribute $attribute,
        Document $document,
        RuleContext $context,
    ): void {
        if (! $attribute->isBound() || $attribute->firstRenderedEcho() !== null) {
            return;
        }

        $expression = trim($attribute->valueText() ?? '');
        if ($expression !== '' && PhpSource::parses($expression)) {
            return;
        }

        $name = $attribute->nameText();
        $detail = $expression === ''
            ? 'the expression is empty'
            : 'the expression is not valid PHP';

        $context->reportAt(
            Position::fromOffset($document, $attribute->startOffset()),
            Position::fromOffset($document, $attribute->endOffset()),
            "Invalid PHP in bound attribute :{$name}: {$detail}."
        );
    }

    private function checkBoundAttributeEcho(Attribute $attribute, Document $document, RuleContext $context): void
    {
        if (! $attribute->isBound()) {
            return;
        }

        $echo = $attribute->firstRenderedEcho();

        if ($echo === null) {
            return;
        }

        $name = $attribute->nameText();

        $context->report(
            $echo,
            "Bound attribute :{$name} cannot contain a Blade echo.",
            $this->createUnwrapEchoFix($attribute, $echo, $document)
        );
    }

    private function checkAttributePositionConstruct(
        Attribute $attribute,
        ComponentNode $component,
        Document $document,
        RuleContext $context,
    ): void {
        if (! $attribute->isBladeConstruct()) {
            return;
        }

        if ($this->isLivewireListenerAttribute($attribute, $component, $document)) {
            return;
        }

        $construct = $attribute->getBladeConstruct();
        $tag = $component->tagNameText();

        if ($construct instanceof DirectiveBlockNode) {
            $name = strtolower($construct->nameText());

            $context->report(
                $construct->startDirective() ?? $construct,
                "@{$name} is not supported in <{$tag}> attributes."
            );

            return;
        }

        if ($construct instanceof DirectiveNode) {
            $name = strtolower($construct->nameText());

            if (! $construct->hasArguments() || in_array($name, self::ALLOWED_ATTRIBUTE_DIRECTIVES, true)) {
                return;
            }

            $context->report(
                $construct,
                "@{$name}(...) is not supported in <{$tag}> attributes."
            );

            return;
        }

        if ($construct instanceof EchoNode) {
            if ($construct->echoType() === 'escaped' && $this->isSupportedAttributeBag($construct->content())) {
                return;
            }

            $context->report(
                $construct,
                "Only {{ \$attributes }} may appear in the attribute list of <{$tag}>."
            );
        }
    }

    private function isSupportedAttributeBag(string $expression): bool
    {
        return ComponentAttributeBagExpression::isBladeAttributeBagChain($expression);
    }

    private function checkUnclosedTag(ComponentNode $component, RuleContext $context): void
    {
        if ($component->isSelfClosing() || $component->isPaired()) {
            return;
        }

        $tag = $component->tagNameText();

        $context->report(
            $component,
            "Unclosed <{$tag}> component."
        );
    }

    private function checkStrayCloser(StrayClosingTagNode $closer, RuleContext $context): void
    {
        $tag = $closer->tagNameText();

        if (preg_match('/^x[-:]/', $tag) !== 1
            && (! ReactiveAttributeSemantics::livewireCompilerIsInstalled() || ! str_starts_with(strtolower($tag), 'livewire:'))) {
            return;
        }

        $context->report(
            $closer,
            "Closing tag </{$tag}> has no matching opener."
        );
    }

    private function isCompiledComponentTag(ComponentNode $component): bool
    {
        if (in_array($component->getPrefix(), ['x-', 'x:'], true)) {
            return true;
        }

        return strtolower($component->getPrefix()) === 'livewire:'
            && ReactiveAttributeSemantics::livewireCompilerIsInstalled();
    }

    private function isLivewireListenerAttribute(
        Attribute $attribute,
        ComponentNode $component,
        Document $document,
    ): bool {
        if (strtolower($component->getPrefix()) !== 'livewire:'
            || ! ReactiveAttributeSemantics::livewireCompilerIsInstalled()) {
            return false;
        }

        return $this->directiveAttributeListener($attribute, $document) !== null;
    }

    private function createUnwrapEchoFix(Attribute $attribute, EchoNode $echo, Document $document): ?Fix
    {
        $value = $attribute->valueText();

        if ($value === null || $echo->startOffset() < 0 || $echo->endOffset() <= $echo->startOffset()) {
            return null;
        }

        $echoSource = $document->getText($echo->startOffset(), $echo->endOffset());

        if (trim($value) !== $echoSource) {
            return null;
        }

        $expression = trim($echo->content());

        if ($expression === '') {
            return null;
        }

        return new Fix($echo->startOffset(), $echo->endOffset(), $expression);
    }
}
