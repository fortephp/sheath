<?php

declare(strict_types=1);

namespace Forte\Sheath\Console\Handlers;

use Forte\Sheath\Console\Concerns\WritesNotices;
use Forte\Sheath\Contracts\ReceivesRunContext;
use Forte\Sheath\Exceptions\ReporterNotFoundException;
use Forte\Sheath\Files\FileSystem;
use Forte\Sheath\Files\PathResolver;
use Forte\Sheath\Reporters\ReporterRegistry;
use Forte\Sheath\Reporters\StylishReporter;
use Forte\Sheath\Results\LintResult;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use InvalidArgumentException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/** @internal */
readonly class OutputHandler
{
    use WritesNotices;

    public function __construct(
        private Command $command,
        private ReporterRegistry $reporterRegistry,
    ) {}

    protected function noticeOutput(): OutputStyle
    {
        return $this->command->getOutput();
    }

    /**
     * @return int A non-negative threshold, or -1 when disabled
     *
     * @throws InvalidArgumentException
     */
    public static function parseMaxWarnings(mixed $value): int
    {
        if ($value === null || $value === '') {
            return -1;
        }

        if (is_int($value)) {
            $value = (string) $value;
        }

        if (is_string($value)) {
            $value = trim($value);

            if ($value === '-1' || ctype_digit($value)) {
                return (int) $value;
            }
        }

        throw new InvalidArgumentException(
            'The --max-warnings option must be a non-negative integer, or -1 to disable the threshold.'
        );
    }

    /** @throws ReporterNotFoundException */
    public function validateFormat(string $format): void
    {
        if (! $this->reporterRegistry->has($format)) {
            throw ReporterNotFoundException::forName(
                $format,
                $this->reporterRegistry->getAvailableReporters(),
            );
        }
    }

    /**
     * @param  array<LintResult>  $results
     *
     * @throws ReporterNotFoundException
     */
    public function output(array $results, string $format, ?string $outputPath = null): bool
    {
        $reporter = $this->reporterRegistry->get($format);

        if ($reporter instanceof ReceivesRunContext) {
            $reporter->receiveRunContext([
                'maxWarnings' => self::parseMaxWarnings($this->command->option('max-warnings')),
            ]);
        }

        $output = $reporter->formatMany($results);

        if ($outputPath !== null) {
            if ($reporter instanceof StylishReporter) {
                $output = (new OutputFormatter(false))->format($output) ?? '';
            }

            $absolutePath = PathResolver::toAbsolutePath($outputPath);
            if (! FileSystem::writeFileAtomically($absolutePath, $output)) {
                $this->noticeError("Failed to write results to {$outputPath}");

                return false;
            }

            $this->notice("Results written to {$outputPath}");

            return true;
        }

        if (! empty($output)) {
            if ($reporter instanceof StylishReporter) {
                $this->command->line($output);
            } else {
                $this->command->getOutput()->writeln($output, OutputInterface::OUTPUT_RAW);
            }
        }

        return true;
    }

    /**
     * @param  array<string, int|float>  $stats
     */
    public function showStats(array $stats): void
    {
        $this->noticeNewLine();
        $this->notice('Performance Statistics:');
        $this->noticeLine(sprintf('  Files processed: %d', $stats['filesProcessed'] ?? 0));
        $this->noticeLine(sprintf('  Files from cache: %d', $stats['filesFromCache'] ?? 0));
        if (($stats['packageRulesSkipped'] ?? 0) > 0) {
            $this->noticeLine(sprintf('  Package rules skipped: %d', $stats['packageRulesSkipped']));
        }
        if (($stats['packageRulesDisabled'] ?? 0) > 0) {
            $this->noticeLine(sprintf('  Package rules disabled: %d', $stats['packageRulesDisabled']));
        }
        if (($stats['presetRulesUnavailable'] ?? 0) > 0) {
            $this->noticeLine(sprintf('  Preset rules unavailable: %d', $stats['presetRulesUnavailable']));
        }
        $this->noticeLine(sprintf('  Lint time: %.3fs', $stats['lintTime'] ?? 0));
        $this->noticeLine(sprintf('  Total time: %.3fs', $stats['totalTime'] ?? 0));
    }
}
