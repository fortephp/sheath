<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Directives;

use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsDirectiveAttributeCollisions;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Throwable;

/** @internal */
class ValidDirectiveArgumentsRule extends AbstractRule
{
    use DetectsDirectiveAttributeCollisions;

    /**
     * @var array<string>
     */
    private const REQUIRES_ARGUMENTS = [
        'if', 'elseif', 'unless', 'isset',
        'foreach', 'forelse', 'for', 'while',
        'switch', 'case',
        'include', 'includeif', 'includewhen', 'includeunless', 'includefirst',
        'includeisolated', 'extends', 'extendsfirst', 'yield', 'section', 'each', 'inject',
        'push', 'prepend', 'pushonce', 'prependonce', 'stack',
        'pushif', 'elsepushif', 'elsepush',
        'component', 'componentfirst', 'slot',
        'use', 'unset', 'json', 'js', 'method', 'choice', 'bool', 'vite',
        'dd', 'dump',
        'can', 'cannot', 'canany', 'elsecan', 'elsecannot', 'elsecanany',
        'error', 'env', 'hassection', 'hasstack', 'sectionmissing',
        'context', 'fragment', 'session',
        'checked', 'selected', 'disabled', 'readonly', 'required',
        'class', 'style', 'props', 'aware',
    ];

    private const LOOP_ARGUMENTS_PATTERN = '/\( *(.+) +as +(.+)\)$/is';

    private const SMART_QUOTES = ["\u{2018}", "\u{2019}", "\u{201C}", "\u{201D}"];

    /** @var array<string> */
    private const ALLOWS_EMPTY_ARGUMENT_LIST = ['dd', 'dump'];

    /** @var array<string, int> */
    private const MINIMUM_ARGUMENT_COUNTS = [
        'each' => 3,
        'choice' => 2,
        'includewhen' => 2,
        'includeunless' => 2,
        'pushif' => 2,
        'elsepushif' => 2,
    ];

    public function getId(): string
    {
        return 'blade-valid-directive-arguments';
    }

    public function getDescription(): string
    {
        return 'Blade directives must use valid arguments.';
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
        $compiler = new BladeCompiler(new Filesystem, sys_get_temp_dir());
        $attributeCollisionIndexes = $this->directiveAttributeCollisionIndexes($document);

        $document->allOfType(DirectiveNode::class, true)->each(function (DirectiveNode $directive) use ($compiler, $document, $context, $attributeCollisionIndexes): void {
            if (isset($attributeCollisionIndexes[$directive->index()])) {
                return;
            }

            $this->checkDirective($directive, $document, $context, $compiler);
        });
    }

    private function checkDirective(
        DirectiveNode $directive,
        Document $document,
        RuleContext $context,
        BladeCompiler $compiler,
    ): void {
        $name = strtolower($directive->nameText());

        if (! $document->getDirectivesRegistry()->hasExplicitDirective($name)) {
            return;
        }

        if ($name === 'empty' && ! $directive->hasArguments() && ! $directive->isIntermediate()) {
            $context->report(
                $directive,
                'Bare @empty is only valid inside @forelse.'
            );

            return;
        }

        $requiresArguments = in_array($name, self::REQUIRES_ARGUMENTS, true)
            || ($name === 'empty' && ! $directive->isIntermediate())
            || ($name === 'lang' && $directive->hasArguments())
            || ($name === 'php' && $directive->hasArguments());

        $whitespace = $directive->whitespaceBetweenNameAndArgs() ?? '';
        $argsOnNextLine = $directive->hasArguments() && strpbrk($whitespace, "\r\n") !== false;

        if ($requiresArguments && $argsOnNextLine) {
            $context->report(
                $directive,
                "@{$name} arguments begin on a later line."
            );

            return;
        }

        if ($requiresArguments && ! $directive->hasArguments()) {
            $message = $name === 'vite'
                ? '@vite has no entrypoint.'
                : "@{$name} has no arguments.";

            $context->report(
                $directive,
                $message
            );

            return;
        }

        if (! $directive->hasArguments() || $argsOnNextLine) {
            return;
        }

        $arguments = $directive->arguments() ?? '';

        if ($requiresArguments
            && ! in_array($name, self::ALLOWS_EMPTY_ARGUMENT_LIST, true)
            && ! $this->hasArgumentExpression($arguments)) {
            $context->report(
                $directive,
                "@{$name} requires a non-empty expression."
            );

            return;
        }

        if ($this->containsUnquotedSmartQuote($arguments)) {
            $context->report(
                $directive,
                "@{$name}{$arguments} contains unsupported smart quotes."
            );

            return;
        }

        if (($name === 'foreach' || $name === 'forelse') && preg_match(self::LOOP_ARGUMENTS_PATTERN, $arguments) !== 1) {
            $context->report(
                $directive,
                "@{$name} requires an 'as' clause."
            );

            return;
        }

        if ($name === 'inject' || isset(self::MINIMUM_ARGUMENT_COUNTS[$name])) {
            $inner = PhpSource::innerArguments($arguments);
            $parts = $inner !== null ? PhpSource::splitTopLevel($inner) : null;

            if ($name === 'inject' && ($parts === null || count($parts) !== 2)) {
                $context->report(
                    $directive,
                    '@inject requires exactly two arguments.'
                );

                return;
            }

            $minimum = self::MINIMUM_ARGUMENT_COUNTS[$name] ?? null;
            if ($minimum !== null && ($parts === null || count($parts) < $minimum)) {
                $context->report(
                    $directive,
                    "@{$name} requires at least {$minimum} arguments."
                );

                return;
            }
        }

        $this->validateArgumentsParse($directive, $name, $arguments, $document, $context, $compiler);
    }

    private function containsUnquotedSmartQuote(string $arguments): bool
    {
        $tokens = PhpSource::tokenize($arguments);

        if ($tokens === null) {
            foreach (self::SMART_QUOTES as $quote) {
                if (str_contains($arguments, $quote)) {
                    return true;
                }
            }

            return false;
        }

        $ignoredTokenKinds = [
            T_CONSTANT_ENCAPSED_STRING,
            T_ENCAPSED_AND_WHITESPACE,
            T_COMMENT,
            T_DOC_COMMENT,
        ];

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], $ignoredTokenKinds, true)) {
                continue;
            }

            $text = is_array($token) ? $token[1] : $token;

            foreach (self::SMART_QUOTES as $quote) {
                if (str_contains($text, $quote)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasArgumentExpression(string $arguments): bool
    {
        $inner = PhpSource::innerArguments($arguments);
        if ($inner === null) {
            return false;
        }

        $tokens = PhpSource::tokenize($inner);
        if ($tokens === null) {
            return true;
        }

        foreach ($tokens as $token) {
            if (! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return true;
            }
        }

        return false;
    }

    private function validateArgumentsParse(
        DirectiveNode $directive,
        string $name,
        string $arguments,
        Document $document,
        RuleContext $context,
        BladeCompiler $compiler,
    ): void {
        $template = $this->validationTemplate($name, $arguments, $document);

        try {
            $code = $compiler->compileString($template);
        } catch (Throwable $exception) {
            $context->report(
                $directive,
                $this->sentence($exception->getMessage())
            );

            return;
        }

        // Laravel intentionally leaves unknown/custom directives untouched.
        // Keep validating their argument expressions with a neutral PHP call;
        // otherwise malformed arguments can disappear from PHP syntax checks.
        if ($code === $template || ! str_contains($code, '<?php')) {
            $code = "<?php f{$arguments};";
        }

        $parseError = PhpSource::parseError($code);

        if ($parseError !== null) {
            $context->report(
                $directive,
                $this->sentence($parseError)
            );
        }
    }

    private function sentence(string $message): string
    {
        $message = trim($message);

        return preg_match('/[.!?]\z/u', $message) === 1 ? $message : $message.'.';
    }

    private function validationTemplate(string $name, string $arguments, Document $document): string
    {
        return match ($name) {
            'switch' => "@switch{$arguments}@case(null)@endswitch",
            'case' => "@switch(null)@case{$arguments}@endswitch",
            'forelse' => "@forelse{$arguments} __sheath__ @empty __sheath__ @endforelse",
            'empty' => "@empty{$arguments}@endempty",
            'break', 'continue' => "@for (;;)@{$name}{$arguments}@endfor",
            'elseif' => "@if(true)@elseif{$arguments}@endif",
            'elsecan' => "@can('__sheath__')@elsecan{$arguments}@endcan",
            'elsecannot' => "@cannot('__sheath__')@elsecannot{$arguments}@endcannot",
            'elsecanany' => "@canany(['__sheath__'])@elsecanany{$arguments}@endcanany",
            'elsepushif' => "@pushif(true, '__sheath__')@elsepushif{$arguments}@endpushif",
            'elsepush' => "@pushif(false, '__sheath__')@elsepush{$arguments}@endpushif",
            default => $this->completeOpeningDirective($name, $arguments, $document),
        };
    }

    private function completeOpeningDirective(string $name, string $arguments, Document $document): string
    {
        $metadata = $document->getDirectivesRegistry()->getDirectiveMetadata()[$name] ?? null;
        if (($metadata['role'] ?? null) !== 'open') {
            return "@{$name}{$arguments}";
        }

        $terminator = $document->getDirectivesRegistry()->getTerminator($name);

        return "@{$name}{$arguments}@{$terminator}";
    }
}
