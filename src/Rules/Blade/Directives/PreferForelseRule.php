<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Directives;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\TextNode;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Support\LoopVariablesExtractor;

/** @internal */
class PreferForelseRule extends AbstractRule
{
    public function getId(): string
    {
        return 'blade-prefer-forelse';
    }

    public function getDescription(): string
    {
        return 'Prefer @forelse over @if with empty/count check followed by @foreach.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::INFO;
    }

    /**
     * @var array<int, true>
     */
    private array $reportedIfBlocks = [];

    public function check(Document $document, RuleContext $context): void
    {
        $this->reportedIfBlocks = [];

        $document->queryBlockDirectives()->each(function (DirectiveBlockNode $block) use ($context): void {
            if (! $block->isForeach()) {
                return;
            }

            $foreachArgs = $block->arguments();
            if ($foreachArgs === null) {
                return;
            }

            $loopVariables = (new LoopVariablesExtractor)->extractDetails($foreachArgs);
            $foreachVariable = $loopVariables->isValid ? $loopVariables->variable : null;

            $ifBlock = $block->closestDirectiveBlock('if');
            if ($ifBlock === null) {
                return;
            }

            $ifIndex = $ifBlock->index();
            if (isset($this->reportedIfBlocks[$ifIndex])) {
                return;
            }

            $ifArgs = $ifBlock->arguments();
            if ($ifArgs === null) {
                return;
            }

            if ($this->isCollectionCheck($ifArgs, $foreachVariable)) {
                $this->reportedIfBlocks[$ifIndex] = true;

                $elseDirective = null;
                $branchCount = 0;
                foreach ($ifBlock->intermediateDirectives() as $intermediate) {
                    $branchCount++;
                    if (strtolower($intermediate->nameText()) === 'else') {
                        $elseDirective = $intermediate;
                    }
                }

                $hasElse = $elseDirective !== null;

                $startDirective = $ifBlock->startDirective();
                $message = $hasElse
                    ? 'Conditional loop can be expressed with @forelse and @empty.'
                    : 'Conditional loop can be expressed with @forelse.';

                $fix = $branchCount === 1 && $elseDirective !== null
                    ? $this->createForelseFix($ifBlock, $block, $elseDirective)
                    : null;

                $context->report($startDirective ?? $ifBlock, $message, $fix);
            }
        });
    }

    private function createForelseFix(
        DirectiveBlockNode $ifBlock,
        DirectiveBlockNode $foreachBlock,
        DirectiveNode $elseDirective
    ): ?Fix {
        $ifStart = $ifBlock->startDirective();
        $ifEnd = $ifBlock->endDirective();
        $foreachStart = $foreachBlock->startDirective();
        $foreachEnd = $foreachBlock->endDirective();

        if ($ifStart === null || $ifEnd === null || $foreachStart === null || $foreachEnd === null) {
            return null;
        }

        if (! $this->branchHoldsOnly($ifStart, $foreachBlock)) {
            return null;
        }

        if ($this->containsLoopSeparator($foreachBlock)) {
            return null;
        }

        $arguments = $foreachBlock->arguments();
        if ($arguments === null || trim($arguments) === '') {
            return null;
        }

        $source = $ifBlock->getDocument()->source();

        $loopBody = substr($source, $foreachStart->endOffset(), $foreachEnd->startOffset() - $foreachStart->endOffset());
        $emptyBody = substr($source, $elseDirective->endOffset(), $ifEnd->startOffset() - $elseDirective->endOffset());

        $baseIndent = $this->lineIndentAt($source, $ifStart->startOffset());
        $unit = $this->indentUnit($source, $baseIndent, $foreachStart->startOffset());

        $replacement = '@forelse '.trim($arguments)
            .$this->dedent($loopBody, $baseIndent, $unit)
            .'@empty'
            .$emptyBody
            .'@endforelse';

        return Fix::dangerous($ifBlock->startOffset(), $ifBlock->endOffset(), $replacement);
    }

    private function branchHoldsOnly(DirectiveNode $branch, DirectiveBlockNode $only): bool
    {
        $found = false;

        foreach ($branch->children() as $child) {
            if ($child instanceof TextNode && trim($child->render()) === '') {
                continue;
            }

            if ($child instanceof DirectiveBlockNode && $child->startOffset() === $only->startOffset()) {
                $found = true;

                continue;
            }

            return false;
        }

        return $found;
    }

    private function containsLoopSeparator(DirectiveBlockNode $foreachBlock): bool
    {
        foreach ($foreachBlock->descendants() as $node) {
            if ($node instanceof DirectiveNode
                && strtolower($node->nameText()) === 'empty'
                && ($node->arguments() === null || trim($node->arguments()) === '')) {
                return true;
            }
        }

        return false;
    }

    private function lineIndentAt(string $source, int $offset): string
    {
        $lineStart = strrpos(substr($source, 0, $offset), "\n");
        $lineStart = $lineStart === false ? 0 : $lineStart + 1;

        $indent = substr($source, $lineStart, $offset - $lineStart);

        return trim($indent) === '' ? $indent : '';
    }

    private function indentUnit(string $source, string $baseIndent, int $nestedOffset): string
    {
        $nestedIndent = $this->lineIndentAt($source, $nestedOffset);

        if ($nestedIndent !== '' && str_starts_with($nestedIndent, $baseIndent)) {
            $unit = substr($nestedIndent, strlen($baseIndent));

            if ($unit !== '') {
                return $unit;
            }
        }

        return str_contains($baseIndent, "\t") ? "\t" : '    ';
    }

    private function dedent(string $body, string $baseIndent, string $unit): string
    {
        $prefix = $baseIndent.$unit;

        $lines = explode("\n", $body);

        foreach ($lines as $index => $line) {
            if (str_starts_with($line, $prefix)) {
                $lines[$index] = $baseIndent.substr($line, strlen($prefix));
            }
        }

        return implode("\n", $lines);
    }

    private function stripOuterParens(string $condition): string
    {
        $condition = trim($condition);
        if (str_starts_with($condition, '(') && str_ends_with($condition, ')')) {
            $depth = 0;
            $len = strlen($condition);
            for ($i = 0; $i < $len - 1; $i++) {
                if ($condition[$i] === '(') {
                    $depth++;
                } elseif ($condition[$i] === ')') {
                    $depth--;
                }
                if ($depth === 0) {
                    return $condition;
                }
            }

            return trim(substr($condition, 1, -1));
        }

        return $condition;
    }

    private function isCollectionCheck(string $condition, ?string $foreachVariable): bool
    {
        $condition = $this->stripOuterParens(trim($condition));

        $var = $foreachVariable ? preg_quote($foreachVariable, '/') : '\$\w+';
        $arrow = '->';
        $nonEmptyCount = '(?:>\s*0|>=\s*1)';

        $patterns = [

            '/^count\s*\(\s*'.$var.'\s*\)\s*'.$nonEmptyCount.'$/',
            '/^count\s*\(\s*'.$var.'\s*\)$/',
            '/^'.$var.$arrow.'count\s*\(\s*\)\s*'.$nonEmptyCount.'$/',
            '/^'.$var.$arrow.'count\s*\(\s*\)$/',

            '/^'.$var.$arrow.'isNotEmpty\s*\(\s*\)$/',
            '/^'.$var.$arrow.'isNotEmpty\s*\(\s*\)\s*===?\s*true$/',
            '/^'.$var.$arrow.'isEmpty\s*\(\s*\)\s*===?\s*false$/',
            '/^!\s*'.$var.$arrow.'isEmpty\s*\(\s*\)$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $condition)) {
                return true;
            }
        }

        return false;
    }
}
