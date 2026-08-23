<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Concerns;

use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Fix;

trait ReportsWithFix
{
    protected function openingTagEndOffset(ElementNode $element): ?int
    {
        if ($element->startOffset() < 0) {
            return null;
        }

        $document = $element->getDocument();
        $afterTagEnd = $document->findOpeningTagEndPosition($element->index());

        if ($afterTagEnd <= 0 || $afterTagEnd > strlen($document->source())) {
            return null;
        }

        $gtOffset = $afterTagEnd - 1;

        if (($document->source()[$gtOffset] ?? '') !== '>') {
            return null;
        }

        return $gtOffset;
    }

    protected function attributeInsertOffset(ElementNode $element): ?int
    {
        $gtOffset = $this->openingTagEndOffset($element);

        if ($gtOffset === null) {
            return null;
        }

        $source = $element->getDocument()->source();
        $offset = $this->skipBackWhitespace($source, $gtOffset, $element->startOffset());

        if ($offset > $element->startOffset() && $source[$offset - 1] === '/') {
            $offset = $this->skipBackWhitespace($source, $offset - 1, $element->startOffset());
        }

        return $offset;
    }

    protected function createAddAttributeFix(ElementNode $element, string $name, string $value, bool $dangerous = false): ?Fix
    {
        return $this->createInsertAttributeFix(
            $element,
            $name.'="'.$this->escapeAttributeValue($value).'"',
            $dangerous
        );
    }

    /**
     * @param  string  $attrString  For example `rel="noreferrer noopener"`
     */
    protected function createInsertAttributeFix(ElementNode $element, string $attrString, bool $dangerous = false): ?Fix
    {
        $insertOffset = $this->attributeInsertOffset($element);

        if ($insertOffset === null) {
            return null;
        }

        return new Fix($insertOffset, $insertOffset, ' '.$attrString, $dangerous);
    }

    protected function createInsertAfterOpeningTagFix(ElementNode $element, string $content, bool $dangerous = false): ?Fix
    {
        $gtOffset = $this->openingTagEndOffset($element);

        if ($gtOffset === null) {
            return null;
        }

        $insertOffset = $gtOffset + 1;

        return new Fix($insertOffset, $insertOffset, $content, $dangerous);
    }

    protected function createRemoveAttributeFix(Attribute $attribute, bool $dangerous = true): ?Fix
    {
        $startOffset = $attribute->startOffset();
        $endOffset = $attribute->endOffset();

        if ($startOffset < 0 || $endOffset < $startOffset) {
            return null;
        }

        $source = $attribute->getDocument()->source();

        return new Fix(
            $this->findWhitespaceStart($source, $startOffset),
            $endOffset,
            '',
            $dangerous
        );
    }

    /**
     * @param  string  $newValue  The full replacement attribute, e.g. `class="new-value"`
     */
    protected function createReplaceAttributeFix(Attribute $attribute, string $newValue, bool $dangerous = false): ?Fix
    {
        $startOffset = $attribute->startOffset();
        $endOffset = $attribute->endOffset();

        if ($startOffset < 0 || $endOffset < $startOffset) {
            return null;
        }

        return new Fix($startOffset, $endOffset, $newValue, $dangerous);
    }

    protected function createSelfClosingFix(ElementNode $element): ?Fix
    {
        if ($element->isSelfClosing()) {
            return null;
        }

        $insertOffset = $this->attributeInsertOffset($element);
        $gtOffset = $this->openingTagEndOffset($element);

        if ($insertOffset === null || $gtOffset === null || $gtOffset < $insertOffset) {
            return null;
        }

        return new Fix(
            $gtOffset,
            $gtOffset,
            $insertOffset < $gtOffset ? '/' : ' /',
            priority: Fix::PRIORITY_TRAILING_SYNTAX,
        );
    }

    protected function createCollapseToSelfClosingFix(ElementNode $element): ?Fix
    {
        $insertOffset = $this->attributeInsertOffset($element);
        $endOffset = $element->endOffset();

        if ($insertOffset === null || $endOffset <= $insertOffset) {
            return null;
        }

        return new Fix($insertOffset, $endOffset, ' />');
    }

    protected function escapeAttributeValue(string $value): string
    {
        return str_replace('"', '&quot;', $value);
    }

    private function skipBackWhitespace(string $source, int $offset, int $limit): int
    {
        while ($offset > $limit && $offset > 0 && ctype_space($source[$offset - 1])) {
            $offset--;
        }

        return $offset;
    }
}
