<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Php;

use Forte\Ast\Components\ComponentNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\EchoNode;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Ast\PhpBlockNode;
use Forte\Ast\PhpTagNode;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoDebugRule extends AbstractRule
{
    protected array $options = [
        'directives' => ['dd', 'dump'],
        'functions' => [
            'dd',
            'dump',
            'ray',
            'var_dump',
            'print_r',
            'var_export',
            'debug_print_backtrace',
            'debug_backtrace',
        ],
    ];

    public function getId(): string
    {
        return 'blade-no-debug';
    }

    public function getDescription(): string
    {
        return 'Disallow debug statements in templates.';
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
        $debugDirectivesOption = $this->getOption('directives', []);
        $debugDirectives = array_values(array_map(
            strtolower(...),
            is_array($debugDirectivesOption) ? array_filter($debugDirectivesOption, is_string(...)) : [],
        ));
        $debugFunctionsOption = $this->getOption('functions', []);
        $debugFunctions = is_array($debugFunctionsOption)
            ? array_values(array_filter(array_map(
                static fn (string $name): string => ltrim($name, '\\'),
                array_filter($debugFunctionsOption, is_string(...)),
            ), static fn (string $name): bool => $name !== ''))
            : [];

        $source = $document->source();
        $directivePattern = $debugDirectives === []
            ? null
            : '/@(?:'.implode('|', array_map(
                static fn (string $name): string => preg_quote($name, '/'),
                $debugDirectives,
            )).')\b/i';
        $functionPattern = $debugFunctions === []
            ? null
            : '/(?<![A-Za-z0-9_])(?:\\\\)?(?:'.implode('|', array_map(
                static fn (string $name): string => preg_quote($name, '/'),
                $debugFunctions,
            )).')\s*\(/i';
        if (($directivePattern === null || preg_match($directivePattern, $source) !== 1)
            && ($functionPattern === null || preg_match($functionPattern, $source) !== 1)) {
            return;
        }

        foreach ($document->allOfType(Node::class, true) as $node) {
            if ($node instanceof DirectiveNode) {
                $this->checkDirective($node, $debugDirectives, $debugFunctions, $context);
            } elseif ($node instanceof EchoNode) {
                $this->reportDebugCall($node, $node->expression(), $debugFunctions, $context);
            } elseif ($node instanceof PhpBlockNode) {
                $this->reportDebugCall($node, $node->code(), $debugFunctions, $context);
            } elseif ($node instanceof PhpTagNode) {
                $this->reportDebugCall($node, $node->content(), $debugFunctions, $context);
            }

            if ($node instanceof ComponentNode) {
                $this->checkBoundAttributes($node, $debugFunctions, $context);
            }
        }
    }

    /** @param list<string> $debugDirectives
     * @param  list<string>  $debugFunctions
     */
    private function checkDirective(
        DirectiveNode $directive,
        array $debugDirectives,
        array $debugFunctions,
        RuleContext $context,
    ): void {
        $name = strtolower($directive->nameText());

        if (in_array($name, $debugDirectives, true)) {
            $context->report($directive, "Debug directive @{$name} found.");
        }

        $arguments = $directive->arguments();
        if ($arguments !== null) {
            $this->reportDebugCall($directive, $arguments, $debugFunctions, $context);
        }
    }

    /** @param list<string> $debugFunctions */
    private function checkBoundAttributes(
        ElementNode $element,
        array $debugFunctions,
        RuleContext $context,
    ): void {
        foreach ($element->attributes() as $attribute) {
            if (! $attribute->isBound()) {
                continue;
            }

            $value = $attribute->valueText();
            if ($value !== null) {
                $this->reportDebugAttribute($attribute, $value, $debugFunctions, $context);
            }
        }
    }

    /** @param array<string> $debugFunctions */
    private function reportDebugCall(Node $node, string $content, array $debugFunctions, RuleContext $context): void
    {
        $function = PhpSource::firstGlobalFunctionCall($content, $debugFunctions);
        if ($function === null) {
            return;
        }

        $context->report(
            $node,
            "Debug function {$function}() found."
        );
    }

    /** @param array<string> $debugFunctions */
    private function reportDebugAttribute(Attribute $attribute, string $content, array $debugFunctions, RuleContext $context): void
    {
        $function = PhpSource::firstGlobalFunctionCall($content, $debugFunctions);
        if ($function === null) {
            return;
        }

        $document = $context->getDocument();
        $context->reportAt(
            Position::fromOffset($document, $attribute->startOffset()),
            Position::fromOffset($document, $attribute->endOffset()),
            "Debug function {$function}() found."
        );
    }
}
