<?php

declare(strict_types=1);

namespace Forte\Sheath\Reporters;

use Forte\Sheath\Contracts\Reporter;
use Forte\Sheath\Files\PathResolver;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Severity;

abstract class AbstractReporter implements Reporter
{
    /**
     * @param  array<LintResult>  $results
     */
    public function formatMany(array $results): string
    {
        $output = [];

        foreach ($results as $result) {
            $formatted = $this->format($result);
            if ($formatted !== '') {
                $output[] = $formatted;
            }
        }

        return $this->combineResults($output, $results);
    }

    /**
     * @param  array<string>  $formattedResults
     * @param  array<LintResult>  $results
     */
    protected function combineResults(array $formattedResults, array $results): string
    {
        return implode("\n\n", $formattedResults);
    }

    protected function displayPath(string $path): string
    {
        return PathResolver::toRelativePath($path);
    }

    protected function displayPathOnOneLine(string $path): string
    {
        return preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static fn (array $match): string => match ($match[0]) {
                "\t" => '\\t',
                "\n" => '\\n',
                "\r" => '\\r',
                default => sprintf('\\x%02X', ord($match[0])),
            },
            $this->displayPath($path),
        ) ?? '';
    }

    /**
     * @param  array<LintResult>  $results
     * @return array{errors: int, warnings: int, infos: int, files: int, fixable: int}
     */
    protected function calculateTotals(array $results): array
    {
        $totals = [
            'errors' => 0,
            'warnings' => 0,
            'infos' => 0,
            'files' => 0,
            'fixable' => 0,
        ];

        foreach ($results as $result) {
            if ($result->hasViolations()) {
                $totals['files']++;
            }

            foreach ($result->violations as $violation) {
                match ($violation->severity) {
                    Severity::ERROR => $totals['errors']++,
                    Severity::WARNING => $totals['warnings']++,
                    Severity::INFO => $totals['infos']++,
                    default => null,
                };

                if ($violation->hasFixAvailable()) {
                    $totals['fixable']++;
                }
            }
        }

        return $totals;
    }
}
