<?php

declare(strict_types=1);

namespace Forte\Sheath\Baselines;

use Forte\Sheath\Exceptions\BaselineException;
use Forte\Sheath\Files\FileSystem;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Violation;

class BaselineGenerator
{
    private const CONTEXT_LINES = 5;

    /** @var array<string, list<string>|null> */
    private array $fileLines = [];

    /**
     * @param  array<LintResult>  $results
     */
    public function generate(
        array $results,
        string $path = Baseline::DEFAULT_PATH,
        int $lineTolerance = Baseline::DEFAULT_LINE_TOLERANCE
    ): Baseline {
        $baseline = new Baseline($path, $lineTolerance);

        foreach ($results as $result) {
            $this->addResultToBaseline($baseline, $result);
        }

        return $baseline;
    }

    /**
     * @param  array<LintResult>  $results
     *
     * @throws BaselineException
     */
    public function generateAndSave(array $results, string $path = Baseline::DEFAULT_PATH): Baseline
    {
        $baseline = $this->generate($results, $path);
        if (! $baseline->save()) {
            throw BaselineException::writeFailed($path);
        }

        return $baseline;
    }

    /**
     * @param  array<LintResult>  $currentResults
     */
    public function update(Baseline $baseline, array $currentResults): Baseline
    {
        $hashGenerator = $this->createHashGenerator();

        $baseline->pruneFixed($currentResults, $hashGenerator);

        foreach ($currentResults as $result) {
            $baseline->recordUnbaselined($result, $hashGenerator);
        }

        return $baseline;
    }

    public function generateHash(Violation $violation): string
    {
        $context = $this->getViolationContext($violation);

        $hashInput = implode('|', [
            $violation->ruleId,
            $violation->message,
            $violation->getColumn(),
            $context,
        ]);

        return hash('xxh128', $hashInput);
    }

    public function createHashGenerator(): callable
    {
        return $this->generateHash(...);
    }

    private function getViolationContext(Violation $violation): string
    {
        $lines = $this->getFileLines($violation->filePath);
        if ($lines === null) {
            return '';
        }

        $lineNumber = $violation->getLine();

        $startLine = max(0, $lineNumber - self::CONTEXT_LINES - 1);
        $endLine = min(count($lines), $lineNumber + self::CONTEXT_LINES);

        $contextLines = array_slice($lines, $startLine, $endLine - $startLine);

        $normalized = array_map(trim(...), $contextLines);

        return implode("\n", $normalized);
    }

    /** @return list<string>|null */
    private function getFileLines(string $filePath): ?array
    {
        if (! array_key_exists($filePath, $this->fileLines)) {
            if (! file_exists($filePath)) {
                $this->fileLines[$filePath] = null;

                return null;
            }

            $content = FileSystem::readFile($filePath);
            $this->fileLines[$filePath] = $content === null ? null : explode("\n", $content);
        }

        return $this->fileLines[$filePath];
    }

    public function preloadFileContent(string $filePath, string $content): void
    {
        $this->fileLines[$filePath] = explode("\n", $content);
    }

    public function clearCache(): void
    {
        $this->fileLines = [];
    }

    private function addResultToBaseline(Baseline $baseline, LintResult $result): void
    {
        foreach ($result->violations as $violation) {
            $hash = $this->generateHash($violation);
            $baseline->addViolation($violation, $hash);
        }
    }
}
