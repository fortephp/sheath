<?php

declare(strict_types=1);

namespace Forte\Sheath\Console\Handlers;

use Forte\Sheath\Baselines\Baseline;
use Forte\Sheath\Baselines\BaselineGenerator;
use Forte\Sheath\Console\Concerns\WritesNotices;
use Forte\Sheath\Exceptions\BaselineException;
use Forte\Sheath\Files\FileSystem;
use Forte\Sheath\Files\PathResolver;
use Forte\Sheath\Results\LintResult;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;

/** @internal */
readonly class BaselineHandler
{
    use WritesNotices;

    private BaselineGenerator $generator;

    protected function noticeOutput(): OutputStyle
    {
        return $this->command->getOutput();
    }

    public function __construct(
        private Command $command,
        private string $baselinePath,
        private int $lineTolerance = Baseline::DEFAULT_LINE_TOLERANCE
    ) {
        $this->generator = new BaselineGenerator;
    }

    /**
     * @param  array<string>  $files
     */
    public function preloadFileContents(array $files): void
    {
        foreach ($files as $file) {
            if (! file_exists($file)) {
                continue;
            }

            $content = FileSystem::readFile($file);
            if ($content !== null) {
                $this->generator->preloadFileContent($file, $content);
            }
        }
    }

    public function preloadFileContent(string $file, string $content): void
    {
        $this->generator->preloadFileContent($file, $content);
    }

    /**
     * @param  array<LintResult>  $results
     * @return array<LintResult>|null
     */
    public function generateBaseline(array $results): ?array
    {
        $baseline = $this->generator->generate($results, $this->baselinePath, $this->lineTolerance);

        if (! $baseline->save()) {
            $this->noticeError('Failed to save baseline file.');

            return null;
        }

        $this->notice(sprintf(
            'Baseline generated with %d violation(s) at %s',
            $baseline->getTotalCount(),
            $this->displayBaselinePath()
        ));

        $this->showBaselineSummary($baseline);

        return array_map(
            fn ($result) => new LintResult($result->filePath, [], $result->hasParseErrors, $result->sourceHash),
            $results
        );
    }

    /**
     * @param  array<LintResult>  $results
     * @return array<LintResult>|null
     *
     * @throws BaselineException
     */
    public function updateBaseline(array $results): ?array
    {
        $baseline = Baseline::load($this->baselinePath, $this->lineTolerance);

        $oldCount = $baseline->getTotalCount();
        $baseline = $this->generator->update($baseline, $results);
        $newCount = $baseline->getTotalCount();

        if (! $baseline->save($this->baselinePath)) {
            $this->noticeError('Failed to save baseline file.');

            return null;
        }

        $diff = $newCount - $oldCount;
        $diffStr = $diff >= 0 ? "+{$diff}" : (string) $diff;

        $this->notice(sprintf(
            'Baseline updated: %d violation(s) (%s) at %s',
            $newCount,
            $diffStr,
            $this->displayBaselinePath()
        ));

        if ($diff < 0) {
            $this->notice(sprintf('Fixed %d violation(s)!', abs($diff)));
        } elseif ($diff > 0) {
            $this->noticeWarning(sprintf('Added %d new violation(s) to baseline.', $diff));
        }

        $this->showBaselineSummary($baseline);

        return array_map(
            fn ($result) => new LintResult($result->filePath, [], $result->hasParseErrors, $result->sourceHash),
            $results
        );
    }

    /**
     * @param  array<LintResult>  $results
     * @return array<LintResult>
     *
     * @throws BaselineException
     */
    public function applyBaseline(array $results): array
    {
        if (! file_exists($this->baselinePath)) {
            return $results;
        }

        $baseline = Baseline::load($this->baselinePath, $this->lineTolerance);

        if ($baseline->isEmpty()) {
            return $results;
        }

        $hashGenerator = $this->generator->createHashGenerator();
        $filteredResults = $baseline->filterResults($results, $hashGenerator);

        $originalCount = array_sum(array_map(fn ($r) => count($r->violations), $results));
        $filteredCount = array_sum(array_map(fn ($r) => count($r->violations), $filteredResults));
        $baselineCount = $originalCount - $filteredCount;

        if ($baselineCount > 0) {
            $this->notice(sprintf(
                '%d violation(s) ignored by baseline (%s)',
                $baselineCount,
                $this->displayBaselinePath()
            ));
        }

        return $filteredResults;
    }

    private function displayBaselinePath(): string
    {
        return PathResolver::normalizeSeparators($this->baselinePath);
    }

    private function showBaselineSummary(Baseline $baseline, int $maxViolations = 10): void
    {
        if ($maxViolations <= 0) {
            $maxViolations = 10;
        }

        $byRule = $baseline->getCountsByRule();

        if (empty($byRule)) {
            return;
        }

        arsort($byRule);
        $this->noticeNewLine();
        $this->notice('Violations by rule:');

        foreach (array_slice($byRule, 0, $maxViolations) as $ruleId => $count) {
            $this->noticeLine(sprintf('  %s: %d', $ruleId, $count));
        }

        if (count($byRule) > $maxViolations) {
            $this->noticeLine(sprintf('  ... and %d more rules', count($byRule) - $maxViolations));
        }
    }
}
