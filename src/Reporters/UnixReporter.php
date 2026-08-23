<?php

declare(strict_types=1);

namespace Forte\Sheath\Reporters;

use Forte\Sheath\Results\Violation;

/** @internal */
class UnixReporter extends LineReporter
{
    protected function formatViolation(string $filePath, Violation $violation): string
    {
        return sprintf(
            '%s:%d:%d: %s [%s]',
            $filePath,
            $violation->getLine(),
            $violation->getColumn(),
            $violation->message,
            $violation->ruleId
        );
    }
}
