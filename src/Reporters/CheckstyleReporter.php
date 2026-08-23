<?php

declare(strict_types=1);

namespace Forte\Sheath\Reporters;

use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Violation;

/** @internal */
class CheckstyleReporter extends AbstractReporter
{
    public function format(LintResult $result): string
    {
        if (! $result->hasViolations()) {
            return '';
        }

        $xml = sprintf('  <file name="%s">', $this->escape($this->displayPath($result->filePath)))."\n";

        foreach ($result->violations as $violation) {
            $xml .= $this->formatViolation($violation);
        }

        return $xml.'  </file>';
    }

    /**
     * @param  array<LintResult>  $results
     */
    public function formatMany(array $results): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<checkstyle version="1.0.0">'."\n";

        foreach ($results as $result) {
            $formatted = $this->format($result);
            if ($formatted !== '') {
                $xml .= $formatted."\n";
            }
        }

        return $xml.'</checkstyle>';
    }

    private function formatViolation(Violation $violation): string
    {
        return sprintf(
            '    <error line="%d" column="%d" severity="%s" message="%s" source="%s"/>'."\n",
            $violation->getLine(),
            $violation->getColumn(),
            $violation->severity->value,
            $this->escape($violation->message),
            $this->escape($violation->ruleId)
        );
    }

    private function escape(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $escaped = preg_replace(
            '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            "\u{FFFD}",
            $escaped,
        ) ?? "\u{FFFD}";

        return str_replace(
            ["\t", "\n", "\r"],
            ['&#x9;', '&#xA;', '&#xD;'],
            $escaped,
        );
    }
}
