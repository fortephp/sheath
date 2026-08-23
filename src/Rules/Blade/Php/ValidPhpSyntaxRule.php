<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Php;

use Forte\Ast\Document\Document;
use Forte\Ast\Node;
use Forte\Ast\PhpBlockNode;
use Forte\Ast\PhpTagNode;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Throwable;

/** @internal */
class ValidPhpSyntaxRule extends AbstractRule
{
    public function getId(): string
    {
        return 'blade-valid-php-syntax';
    }

    public function getDescription(): string
    {
        return 'Raw PHP tags and @php blocks must compile into valid PHP.';
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
        $source = $document->source();
        if (! str_contains($source, '<?') && stripos($source, '@php') === false) {
            return;
        }

        $nodes = [];
        $document->allOfType(PhpBlockNode::class, true)->each(static function (PhpBlockNode $node) use (&$nodes): void {
            $nodes[] = $node;
        });
        $document->allOfType(PhpTagNode::class, true)->each(static function (PhpTagNode $node) use (&$nodes): void {
            $nodes[] = $node;
        });

        usort($nodes, fn (Node $left, Node $right): int => $left->startOffset() <=> $right->startOffset());

        if ($nodes === []) {
            return;
        }

        $php = '';
        $lineOwners = [];

        foreach ($nodes as $index => $node) {
            if ($index > 0) {
                $php .= "\nHTML\n";
            }

            $snippet = $node instanceof PhpBlockNode
                ? '<?php '.$node->code().' ?>'
                : $node->getDocumentContent();

            $startLine = substr_count($php, "\n") + 1;
            $endLine = $startLine + substr_count($snippet, "\n");
            $lineOwners[] = [$startLine, $endLine, $node];
            $php .= $snippet;
        }

        $error = PhpSource::parseErrorDetails($php);
        if ($error === null) {
            return;
        }

        // Raw PHP may deliberately share control flow with standard Blade
        // directives. Validate the actual compiled template before treating
        // an isolated raw-region parse failure as a real syntax error.
        try {
            $compiled = (new BladeCompiler(new Filesystem, sys_get_temp_dir()))
                ->compileString($document->source());

            if (PhpSource::parseErrorDetails($compiled) === null) {
                return;
            }
        } catch (Throwable) {
            // Preserve the raw-region diagnostic when Blade cannot compile.
        }

        $errorLine = $error['line'];
        if (preg_match('/\bUnclosed .+ on line (?<line>\d+)$/', $error['message'], $matches) === 1) {
            $errorLine = (int) $matches['line'];
        }

        $owner = $nodes[array_key_last($nodes)];
        foreach ($lineOwners as [$startLine, $endLine, $node]) {
            if ($errorLine >= $startLine && $errorLine <= $endLine) {
                $owner = $node;

                break;
            }
        }

        $context->report(
            $owner,
            rtrim($error['message'], '.').'.'
        );
    }
}
