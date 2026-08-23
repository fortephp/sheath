<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Php;

use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\PhpBlockNode;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoLogicInViewsRule extends AbstractRule
{
    private const COMPLEXITY_INDICATORS = [
        'foreach',
        'for',
        'while',
        'do',
        'switch',
        'function',
        'class',
        'trait',
        'interface',
        'try',
        'catch',
        'throw',
    ];

    private const DEFAULT_MAX_OPERATORS = 2;

    protected array $options = [
        'maxOperators' => self::DEFAULT_MAX_OPERATORS,
        'checkPhpBlocks' => true,
        'checkConditions' => true,
    ];

    protected array $optionRules = [
        'maxOperators' => 'non-negative-integer',
    ];

    public function getId(): string
    {
        return 'blade-no-logic-in-views';
    }

    public function getDescription(): string
    {
        return 'Disallow complex PHP logic in views.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $maxOperatorsOption = $this->getOption('maxOperators', self::DEFAULT_MAX_OPERATORS);
        $maxOperators = is_int($maxOperatorsOption) ? $maxOperatorsOption : self::DEFAULT_MAX_OPERATORS;

        $checkPhpBlocksOption = $this->getOption('checkPhpBlocks', true);
        $checkPhpBlocks = ! is_bool($checkPhpBlocksOption) || $checkPhpBlocksOption;

        $checkConditionsOption = $this->getOption('checkConditions', true);
        $checkConditions = ! is_bool($checkConditionsOption) || $checkConditionsOption;

        if ($checkPhpBlocks) {
            $this->checkPhpBlocks($document, $context);
        }

        if ($checkConditions) {
            $this->checkComplexConditions($document, $context, $maxOperators);
        }
    }

    private function checkPhpBlocks(Document $document, RuleContext $context): void
    {
        $document->allOfType(PhpBlockNode::class, true)->each(function (PhpBlockNode $block) use ($context): void {
            $content = PhpSource::stripLiterals($block->code());

            foreach (self::COMPLEXITY_INDICATORS as $keyword) {
                if (preg_match('/(?<!->)(?<!::)(?<!\$)\b'.$keyword.'\s*[\s\(]/i', $content)) {
                    $article = in_array(strtolower($keyword[0]), ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';

                    $context->report(
                        $block,
                        "@php block contains {$article} {$keyword} statement."
                    );

                    return;
                }
            }

            if ($this->hasComplexCalculations($content)) {
                $context->report(
                    $block,
                    '@php block contains a complex calculation.'
                );
            }
        });
    }

    private function checkComplexConditions(Document $document, RuleContext $context, int $maxOperators): void
    {
        $document->allOfType(DirectiveNode::class, true)->each(function (DirectiveNode $directive) use ($context, $maxOperators): void {
            if (! in_array($directive->nameText(), ['if', 'unless', 'elseif'], true)) {
                return;
            }

            $args = $directive->arguments();
            if ($args === null) {
                return;
            }

            $operatorCount = $this->countLogicalOperators($args);

            if ($operatorCount > $maxOperators) {
                $context->report(
                    $directive,
                    "Condition has {$operatorCount} logical operators; maximum is {$maxOperators}."
                );
            }
        });
    }

    private function countLogicalOperators(string $condition): int
    {
        $condition = PhpSource::stripLiterals($condition);
        $count = preg_match_all('/&&|\|\|/', $condition, $matches);

        return $count + preg_match_all('/\b(and|or)\b/i', $condition, $matches);
    }

    private function hasComplexCalculations(string $content): bool
    {
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            if (str_contains($line, '=') && ! preg_match('/[!=<>]=/', $line)) {
                $operatorCount = preg_match_all('/[^\s=<>!+\-*\/]\s*[\+\-\*\/]/', $line, $matches);

                if ($operatorCount >= 3) {
                    return true;
                }
            }
        }

        return false;
    }
}
