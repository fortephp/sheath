<?php

declare(strict_types=1);

namespace Forte\Sheath\Exceptions;

class RuleNotFoundException extends SheathException
{
    public static function forId(string $ruleId): self
    {
        return new self("Rule '{$ruleId}' not found in registry.");
    }
}
