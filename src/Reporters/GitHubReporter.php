<?php

declare(strict_types=1);

namespace Forte\Sheath\Reporters;

use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;

/** @internal */
class GitHubReporter extends AbstractReporter
{
    public function format(LintResult $result): string
    {
        if (! $result->hasViolations()) {
            return '';
        }

        $filePath = $this->displayPath($result->filePath);

        $lines = [];

        foreach ($result->violations as $violation) {
            $lines[] = $this->formatViolation($filePath, $violation);
        }

        return implode("\n", $lines);
    }

    private function formatViolation(string $filePath, Violation $violation): string
    {
        $level = match ($violation->severity) {
            Severity::ERROR => 'error',
            Severity::WARNING => 'warning',
            default => 'notice',
        };

        return sprintf(
            '::%s file=%s,line=%d,col=%d::%s',
            $level,
            $this->escapeProperty($filePath),
            $violation->getLine(),
            $violation->getColumn(),
            $this->escapeData($violation->message.' ['.$violation->ruleId.']'),
        );
    }

    /**
     * @see https://docs.github.com/en/actions/reference/workflow-commands-for-github-actions
     */
    private function escapeData(string $value): string
    {
        return str_replace(
            ['%', "\r", "\n"],
            ['%25', '%0D', '%0A'],
            $value
        );
    }

    private function escapeProperty(string $value): string
    {
        return str_replace(
            ['%', "\r", "\n", ':', ','],
            ['%25', '%0D', '%0A', '%3A', '%2C'],
            $value
        );
    }
}
