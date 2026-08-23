<?php

declare(strict_types=1);

namespace Forte\Sheath\Reporters;

use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;
use Symfony\Component\Console\Formatter\OutputFormatter;

/** @internal */
class StylishReporter extends AbstractReporter
{
    public function format(LintResult $result): string
    {
        if (! $result->hasViolations()) {
            return '';
        }

        $output = [];

        $output[] = $this->formatHeader($this->displayPathOnOneLine($result->filePath));

        $positions = [];
        $positionWidth = 0;
        $messageWidth = 0;
        $errorCount = 0;
        $warningCount = 0;
        $infoCount = 0;

        foreach ($result->violations as $index => $violation) {
            $position = $this->positionOf($violation);
            $positions[$index] = $position;
            $positionWidth = max($positionWidth, mb_strlen($position));
            $messageWidth = max($messageWidth, mb_strlen($violation->message));

            match ($violation->severity) {
                Severity::ERROR => $errorCount++,
                Severity::WARNING => $warningCount++,
                Severity::INFO => $infoCount++,
                default => null,
            };
        }

        foreach ($result->violations as $index => $violation) {
            $output[] = $this->formatViolation($violation, $positions[$index], $positionWidth, $messageWidth);
        }

        $summary = sprintf(
            '  %s %s, %s %s',
            $errorCount,
            $errorCount === 1 ? 'error' : 'errors',
            $warningCount,
            $warningCount === 1 ? 'warning' : 'warnings'
        );

        if ($infoCount > 0) {
            $summary .= sprintf(', %d %s', $infoCount, $infoCount === 1 ? 'info' : 'infos');
        }

        $output[] = '';
        $output[] = $summary;

        return implode("\n", $output);
    }

    protected function combineResults(array $formattedResults, array $results): string
    {
        $output = implode("\n\n", $formattedResults);

        $totals = $this->calculateTotals($results);

        if ($totals['errors'] > 0 || $totals['warnings'] > 0 || $totals['infos'] > 0) {
            $output .= "\n\n".str_repeat('=', 60)."\n";

            $totalLine = sprintf(
                'Total: %d %s, %d %s',
                $totals['errors'],
                $totals['errors'] === 1 ? 'error' : 'errors',
                $totals['warnings'],
                $totals['warnings'] === 1 ? 'warning' : 'warnings'
            );

            if ($totals['infos'] > 0) {
                $totalLine .= sprintf(', %d %s', $totals['infos'], $totals['infos'] === 1 ? 'info' : 'infos');
            }

            $output .= $totalLine.sprintf(
                " in %d %s\n",
                $totals['files'],
                $totals['files'] === 1 ? 'file' : 'files'
            );

            if ($totals['fixable'] > 0) {
                $output .= sprintf(
                    "%d %s can be automatically fixed\n",
                    $totals['fixable'],
                    $totals['fixable'] === 1 ? 'problem' : 'problems'
                );
            }
        }

        return $output;
    }

    private function formatHeader(string $filePath): string
    {
        return '<options=bold>'.OutputFormatter::escape($filePath).'</>';
    }

    private function formatViolation(Violation $violation, string $position, int $positionWidth, int $messageWidth): string
    {
        $icon = match ($violation->severity) {
            Severity::ERROR => '<fg=red>✗</>',
            Severity::WARNING => '<fg=yellow>⚠</>',
            Severity::INFO => '<fg=cyan>ℹ</>',
            default => ' ',
        };

        return sprintf(
            '  %s %s  %s  %s',
            $icon,
            $this->pad($position, $positionWidth, left: true),
            $this->pad($violation->message, $messageWidth),
            '<fg=gray>'.OutputFormatter::escape($violation->ruleId).'</>'
        );
    }

    private function positionOf(Violation $violation): string
    {
        return sprintf('%d:%d', $violation->getLine(), $violation->getColumn());
    }

    private function pad(string $value, int $width, bool $left = false): string
    {
        $padding = str_repeat(' ', max(0, $width - mb_strlen($value)));
        $escaped = OutputFormatter::escape($value);

        return $left ? $padding.$escaped : $escaped.$padding;
    }
}
