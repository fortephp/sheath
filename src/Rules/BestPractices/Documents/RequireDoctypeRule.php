<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\BestPractices\Documents;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\DoctypeNode;
use Forte\Ast\Document\Document;
use Forte\Ast\EchoNode;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Ast\PhpBlockNode;
use Forte\Ast\PhpTagNode;
use Forte\Ast\TextNode;
use Forte\Ast\VerbatimNode;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ChecksRenderPathGuarantees;
use Forte\Sheath\Rules\Concerns\ValidatesDocumentStructure;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Support\HtmlWhitespace;

/** @internal */
class RequireDoctypeRule extends AbstractRule
{
    use ChecksRenderPathGuarantees;
    use ValidatesDocumentStructure;

    private const HTML5_DOCTYPE = '/^(?i:<!DOCTYPE[\x09\x0A\x0C\x0D\x20]+html)(?:(?i:[\x09\x0A\x0C\x0D\x20]+SYSTEM)[\x09\x0A\x0C\x0D\x20]+(?:"about:legacy-compat"|\'about:legacy-compat\'))?[\x09\x0A\x0C\x0D\x20]*>$/';

    public function getId(): string
    {
        return 'best-practices-require-doctype';
    }

    public function getDescription(): string
    {
        return 'HTML documents should have a DOCTYPE declaration.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BEST_PRACTICES;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $htmlElement = $this->getHtmlElement($document);

        if ($htmlElement === null) {
            return;
        }

        $doctype = $this->getDoctypeBefore($document, $htmlElement->startOffset());

        if ($this->everyRenderPathContainsBefore(
            $document->children(),
            static fn (mixed $node): bool => $node instanceof DoctypeNode
                && $node->startOffset() < $htmlElement->startOffset()
                && (bool) preg_match(self::HTML5_DOCTYPE, $node->getDocumentContent()),
            fn (mixed $node): bool => $this->rendersContentBeforeDoctype($node),
            fn (Node $node): bool => $this->shouldDescendBeforeDoctype($node),
        )) {
            return;
        }

        $context->report(
            $htmlElement,
            'HTML document is missing a leading <!DOCTYPE html>.',
            $this->createDoctypeFix($document, $htmlElement, $doctype)
        );
    }

    private function rendersContentBeforeDoctype(mixed $node): bool
    {
        if ($node instanceof TextNode) {
            $content = $node->getContent();
            if ($node->startOffset() === 0 && str_starts_with($content, "\xEF\xBB\xBF")) {
                $content = substr($content, 3);
            }

            return ! HtmlWhitespace::isOnly($content);
        }

        if ($node instanceof EchoNode || $node instanceof ElementNode || $node instanceof VerbatimNode) {
            return true;
        }

        if ($node instanceof PhpBlockNode || $node instanceof PhpTagNode) {
            return PhpSource::mayProduceOutput($node->code());
        }

        if (! $node instanceof DirectiveNode
            || $node->isOpening()
            || $node->isIntermediate()
            || $node->isClosing()) {
            return false;
        }

        return ! in_array(strtolower($node->nameText()), [
            'aware',
            'break',
            'continue',
            'inject',
            'php',
            'props',
            'unset',
            'use',
        ], true);
    }

    private function shouldDescendBeforeDoctype(Node $node): bool
    {
        if (! $node instanceof DirectiveBlockNode) {
            return true;
        }

        $name = strtolower($node->nameText());
        if (in_array($name, ['push', 'pushonce', 'prepend', 'prependonce'], true)) {
            return false;
        }

        if ($name !== 'section') {
            return true;
        }

        return strtolower($node->endDirective()?->nameText() ?? '') === 'show';
    }

    private function createDoctypeFix(Document $document, ElementNode $htmlElement, ?DoctypeNode $doctype): ?Fix
    {
        $doctypes = $this->getDoctypeNodes($document);

        // Replacing or inserting one declaration cannot repair a document that
        // already contains another declaration elsewhere.
        if (count($doctypes) > 1 || ($doctype === null && $doctypes !== [])) {
            return null;
        }

        if ($doctype !== null) {
            if (! $this->everyRenderPathContainsBefore(
                $document->children(),
                static fn (mixed $node): bool => $node === $doctype,
                fn (mixed $node): bool => $this->rendersContentBeforeDoctype($node),
                fn (Node $node): bool => $this->shouldDescendBeforeDoctype($node),
            )) {
                return null;
            }

            return Fix::dangerous($doctype->startOffset(), $doctype->endOffset(), '<!DOCTYPE html>');
        }

        if (! $this->everyRenderPathContainsBefore(
            $document->children(),
            static fn (mixed $node): bool => $node === $htmlElement,
            fn (mixed $node): bool => $this->rendersContentBeforeDoctype($node),
            fn (Node $node): bool => $this->shouldDescendBeforeDoctype($node),
        )) {
            return null;
        }

        $insertOffset = $htmlElement->startOffset();
        $newline = $this->sourceNewline($htmlElement->getDocument()->source());

        return Fix::dangerous($insertOffset, $insertOffset, "<!DOCTYPE html>{$newline}");
    }
}
