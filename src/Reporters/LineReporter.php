<?php

declare(strict_types=1);

namespace Forte\Sheath\Reporters;

use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Violation;

/** @internal */
abstract class LineReporter extends AbstractReporter
{
    public function format(LintResult $result): string
    {
        if (! $result->hasViolations()) {
            return '';
        }

        $filePath = $this->displayPathOnOneLine($result->filePath);

        return implode("\n", array_map(
            fn (Violation $violation): string => $this->formatViolation($filePath, $violation),
            $result->violations,
        ));
    }

    abstract protected function formatViolation(string $filePath, Violation $violation): string;
}
