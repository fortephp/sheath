<?php

declare(strict_types=1);

namespace Forte\Sheath\Reporters;

use Forte\Sheath\Results\Violation;

/** @internal */
class CompactReporter extends LineReporter
{
    protected function formatViolation(string $filePath, Violation $violation): string
    {
        return sprintf(
            '%s: line %d, col %d, %s - %s (%s)',
            $filePath,
            $violation->getLine(),
            $violation->getColumn(),
            strtoupper($violation->severity->value),
            $violation->message,
            $violation->ruleId
        );
    }
}
