<?php

declare(strict_types=1);

namespace Forte\Sheath\Tests\Fixtures\Cache;

use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;

abstract class MutableRuleBase extends AbstractRule
{
    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }
}
