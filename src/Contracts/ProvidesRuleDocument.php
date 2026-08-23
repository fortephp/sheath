<?php

declare(strict_types=1);

namespace Forte\Sheath\Contracts;

use Forte\Ast\Document\Document;
use Forte\Parser\ParserOptions;

interface ProvidesRuleDocument
{
    public function ruleDocumentKey(): string;

    public function ruleDocument(Document $document, ParserOptions $parserOptions): Document;
}
