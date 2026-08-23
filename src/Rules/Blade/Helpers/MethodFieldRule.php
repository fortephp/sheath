<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Helpers;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\EchoNode;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ChecksFormSubmission;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueHeadContent;
use Forte\Sheath\Rules\Concerns\ReportsWithFix;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Support\AttributeQuoting;

/** @internal */
class MethodFieldRule extends AbstractRule
{
    use ChecksFormSubmission;
    use DetectsOpaqueAttributes;
    use DetectsOpaqueHeadContent;
    use ReportsWithFix;

    private const SPOOFED_METHODS = ['PUT', 'PATCH', 'DELETE'];

    public function getId(): string
    {
        return 'blade-method-field';
    }

    public function getDescription(): string
    {
        return 'PUT, PATCH, and DELETE forms require matching method spoofing.';
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
        $newline = $this->sourceNewline($document->source());

        $document->queryElements('form')
            ->each(function (ElementNode $form) use ($context, $newline): void {
                $spoofedMethods = $this->spoofedMethods($form);
                if ($spoofedMethods === null || $spoofedMethods === []) {
                    return;
                }

                if (! $form->hasAttribute('method')) {
                    $methods = implode(', ', array_map(
                        static fn (string $method): string => "'{$method}'",
                        $spoofedMethods,
                    ));
                    $context->report(
                        $form,
                        "Form method spoofing is conditional: {$methods}."
                    );

                    return;
                }

                $method = strtoupper($form->staticAttributeValue('method') ?? 'GET');

                if (! in_array($method, self::SPOOFED_METHODS, true)) {
                    return;
                }

                if ($this->formContentIsOpaque($form)) {
                    return;
                }

                $methodDirectives = $this->findMethodDirectives($form);

                if ($methodDirectives !== []) {
                    if (! $this->formGuaranteesMethodDirective($form)) {
                        $context->report(
                            $form,
                            "@method('{$method}') is not present on every submission path."
                        );

                        return;
                    }

                    foreach ($methodDirectives as $methodDirective) {
                        if ($this->staticMethodArgument($methodDirective) === null) {
                            $context->report(
                                $form,
                                "@method does not statically match '{$method}'."
                            );

                            return;
                        }
                    }

                    $context->report(
                        $form,
                        "Form method '{$method}' requires method=\"POST\".",
                        $this->createPostMethodFix($form)
                    );

                    foreach ($methodDirectives as $methodDirective) {
                        $directiveMethod = $this->staticMethodArgument($methodDirective);

                        if ($directiveMethod !== null && $directiveMethod !== $method) {
                            $context->report(
                                $methodDirective,
                                "@method('{$directiveMethod}') does not match form method '{$method}'.",
                                Fix::dangerousFromNode($methodDirective, "@method('{$method}')")
                            );
                        }
                    }

                    return;
                }

                $context->report(
                    $form,
                    "Form method '{$method}' requires method=\"POST\" and @method('{$method}').",
                    $this->createSpoofedMethodFix($form, $method, $newline)
                );
            });
    }

    /** @return list<string>|null */
    private function spoofedMethods(ElementNode $form): ?array
    {
        if ($this->elementHasUnmodelledAttributes($form)) {
            return null;
        }

        $paths = $this->explicitAttributeRenderPaths($form, [
            'method',
            ...$this->reactiveSubmissionDirectiveNames($form),
        ]);
        if ($paths === null) {
            return null;
        }

        $methods = [];
        foreach ($paths as $path) {
            if ($this->formPathIsDurablyIntercepted($path, $form)) {
                continue;
            }

            $method = $this->firstPathAttribute($path, 'method');
            if ($method === null || $method->isDynamic()) {
                continue;
            }

            $value = strtoupper($method->decodedValueText() ?? '');
            if (in_array($value, self::SPOOFED_METHODS, true)) {
                $methods[$value] = $value;
            }
        }

        return array_values($methods);
    }

    /** @param list<Attribute> $path */
    private function firstPathAttribute(array $path, string $name): ?Attribute
    {
        foreach ($path as $attribute) {
            if ($attribute->isNamed($name)) {
                return $attribute;
            }
        }

        return null;
    }

    private function createPostMethodFix(ElementNode $form): ?Fix
    {
        $methodAttr = $form->attribute('method');
        if ($methodAttr === null) {
            return null;
        }

        $quote = AttributeQuoting::styleOf($methodAttr);

        return $this->createReplaceAttributeFix(
            $methodAttr,
            AttributeQuoting::render('method', 'POST', $quote),
            dangerous: true,
        );
    }

    private function createSpoofedMethodFix(ElementNode $form, string $method, string $newline): ?Fix
    {
        $methodAttr = $form->attribute('method');

        if ($methodAttr === null) {
            return null;
        }

        $attrStart = $methodAttr->startOffset();
        $attrEnd = $methodAttr->endOffset();
        $gtOffset = $this->openingTagEndOffset($form);

        if ($attrStart < 0 || $gtOffset === null || $attrEnd > $gtOffset) {
            return null;
        }

        $source = $form->getDocument()->source();
        $quote = AttributeQuoting::styleOf($methodAttr);

        $replacement = AttributeQuoting::render('method', 'POST', $quote)
            .substr($source, $attrEnd, $gtOffset + 1 - $attrEnd)
            ."{$newline}    @method('{$method}')";

        return Fix::dangerous($attrStart, $gtOffset + 1, $replacement);
    }

    private function formContentIsOpaque(ElementNode $form): bool
    {
        if ($this->elementContainsOpaqueContent($form)) {
            return true;
        }

        foreach ($form->descendants() as $descendant) {
            if ($descendant instanceof EchoNode && trim($descendant->expression()) === '$slot') {
                return true;
            }
        }

        return false;
    }

    /** @return list<DirectiveNode> */
    private function findMethodDirectives(ElementNode $form): array
    {
        $directives = [];

        foreach ($form->descendants() as $descendant) {
            if ($descendant instanceof DirectiveNode
                && strtolower($descendant->nameText()) === 'method'
                && $this->methodDirectiveCreatesGuaranteedControl($descendant)) {
                $directives[] = $descendant;
            }
        }

        return $directives;
    }

    private function formGuaranteesMethodDirective(ElementNode $form): bool
    {
        return $this->nodeSequenceGuaranteesMethodDirective($form->children());
    }

    /** @param iterable<mixed> $nodes */
    private function nodeSequenceGuaranteesMethodDirective(iterable $nodes): bool
    {
        foreach ($nodes as $node) {
            if ($node instanceof Node && $this->nodeGuaranteesMethodDirective($node)) {
                return true;
            }

            if ($node instanceof DirectiveNode
                && in_array(strtolower($node->nameText()), ['break', 'continue'], true)
                && ! $node->hasArguments()) {
                return false;
            }
        }

        return false;
    }

    private function nodeGuaranteesMethodDirective(Node $node): bool
    {
        if ($node instanceof DirectiveNode) {
            return strtolower($node->nameText()) === 'method'
                && $this->methodDirectiveCreatesGuaranteedControl($node);
        }

        if ($node instanceof ElementNode) {
            return $this->nodeSequenceGuaranteesMethodDirective($node->children());
        }

        if ($node instanceof DirectiveBlockNode) {
            return $this->directiveBlockGuaranteesMethodDirective($node);
        }

        return false;
    }

    private function directiveBlockGuaranteesMethodDirective(DirectiveBlockNode $block): bool
    {
        $name = strtolower($block->nameText());
        $start = $block->startDirective();

        if ($start === null) {
            return false;
        }

        if ($name === 'forelse') {
            $branches = [$start, ...iterator_to_array($block->intermediateDirectives())];
            $hasEmpty = false;

            foreach ($branches as $branch) {
                $hasEmpty = $hasEmpty || strtolower($branch->nameText()) === 'empty';

                if (! $this->nodeSequenceGuaranteesMethodDirective($branch->children())) {
                    return false;
                }
            }

            return $hasEmpty;
        }

        if ($name === 'switch') {
            $branches = [];
            $hasDefault = false;

            foreach ($start->children() as $child) {
                if (! $child instanceof DirectiveNode
                    || ! in_array(strtolower($child->nameText()), ['case', 'default'], true)) {
                    continue;
                }

                $branches[] = $child;
                $hasDefault = $hasDefault || strtolower($child->nameText()) === 'default';
            }

            if (! $hasDefault || $branches === []) {
                return false;
            }

            foreach ($branches as $branch) {
                if (! $this->nodeSequenceGuaranteesMethodDirective($branch->children())) {
                    return false;
                }
            }

            return true;
        }

        if (! in_array($name, [
            'auth',
            'empty',
            'env',
            'guest',
            'hassection',
            'if',
            'isset',
            'production',
            'sectionmissing',
            'unless',
        ], true)) {
            return false;
        }

        $branches = [$start, ...iterator_to_array($block->intermediateDirectives())];
        $hasElse = false;

        foreach ($branches as $branch) {
            $hasElse = $hasElse || strtolower($branch->nameText()) === 'else';

            if (! $this->nodeSequenceGuaranteesMethodDirective($branch->children())) {
                return false;
            }
        }

        return $hasElse;
    }

    private function staticMethodArgument(DirectiveNode $directive): ?string
    {
        $argument = PhpSource::innerArguments($directive->arguments());
        if ($argument === null || preg_match('/^([\'\"])([a-z]+)\\1$/i', trim($argument), $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[2]);
    }

    private function methodDirectiveCreatesGuaranteedControl(DirectiveNode $directive): bool
    {
        if (! $this->nodeCreatesSuccessfulFormControl($directive)) {
            return false;
        }

        foreach ($directive->ancestors() as $ancestor) {
            if (! $ancestor instanceof ElementNode
                || ! $ancestor->isTag('fieldset')
                || $this->isInsideFirstLegend($ancestor, $directive)) {
                continue;
            }

            if (! $this->elementIsGuaranteedEnabled($ancestor)) {
                return false;
            }
        }

        return true;
    }

    private function elementIsGuaranteedEnabled(ElementNode $element): bool
    {
        if ($this->elementHasUnmodelledAttributes($element)) {
            return false;
        }

        foreach ($element->attributes() as $attribute) {
            if (! $attribute->isBladeConstruct()) {
                continue;
            }

            $construct = $attribute->getBladeConstruct();
            if ($construct instanceof DirectiveNode
                && strtolower($construct->nameText()) === 'disabled') {
                return false;
            }
        }

        $paths = $this->explicitAttributeRenderPaths($element, 'disabled');
        if ($paths === null || $paths === []) {
            return false;
        }

        foreach ($paths as $path) {
            if ($this->firstPathAttribute($path, 'disabled') !== null) {
                return false;
            }
        }

        return true;
    }

    private function isInsideFirstLegend(ElementNode $fieldset, Node $node): bool
    {
        foreach ($fieldset->children() as $child) {
            if ($child instanceof ElementNode && $child->isTag('legend')) {
                return $child->contains($node);
            }
        }

        return false;
    }
}
