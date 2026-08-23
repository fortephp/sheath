<?php

declare(strict_types=1);

namespace Forte\Sheath\Tests\Fixtures\Cache;

use Forte\Ast\Document\Document;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

final class MutableRule extends AbstractRule
{
    public function getId(): string
    {
        return 'mutable-cache-probe';
    }

    public function getDescription(): string
    {
        return 'Rule used to verify source-aware cache invalidation.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void {}
}
