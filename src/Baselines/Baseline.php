<?php

declare(strict_types=1);

namespace Forte\Sheath\Baselines;

use Forte\Sheath\Exceptions\BaselineException;
use Forte\Sheath\Files\FileSystem;
use Forte\Sheath\Files\PathResolver;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Violation;
use JsonException;

/** @phpstan-type BaselineEntry array{ruleId: string, line: int, message: string, hash: string} */
class Baseline
{
    private const NON_BASELINABLE_RULE_IDS = ['parse-error'];

    public const VERSION = 3;

    public const DEFAULT_PATH = 'sheath-baseline.json';

    public const DEFAULT_LINE_TOLERANCE = 3;

    /**
     * @var array<string, list<BaselineEntry>>
     */
    private array $violations = [];

    /**
     * @var array{total: int, byRule: array<string, int>}
     */
    private array $counts = ['total' => 0, 'byRule' => []];

    private ?string $generated = null;

    public function __construct(
        private readonly string $path = self::DEFAULT_PATH,
        private readonly int $lineTolerance = self::DEFAULT_LINE_TOLERANCE
    ) {}

    /**
     * @throws BaselineException
     */
    public static function load(string $path = self::DEFAULT_PATH, int $lineTolerance = self::DEFAULT_LINE_TOLERANCE): self
    {
        $baseline = new self($path, $lineTolerance);

        if (! file_exists($path)) {
            return $baseline;
        }

        if (! is_file($path) || ! is_readable($path)) {
            throw BaselineException::unreadable($path);
        }

        $content = FileSystem::readFile($path);
        if ($content === null) {
            throw BaselineException::unreadable($path);
        }

        try {
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw BaselineException::invalidJson($path, $e->getMessage());
        }

        if (! is_array($data) || array_is_list($data)) {
            throw BaselineException::invalidStructure($path, 'expected an object at the document root.');
        }

        $version = $data['version'] ?? null;
        if (! is_int($version) || $version < 1) {
            throw BaselineException::invalidStructure($path, 'schema version must be a positive integer.');
        }

        if ($version > self::VERSION) {
            throw BaselineException::unsupportedVersion($path, $version, self::VERSION);
        }

        $violations = $data['violations'] ?? null;
        if (! is_array($violations)) {
            throw BaselineException::invalidStructure($path, 'violations must be an object keyed by file path.');
        }

        $baseline->violations = $baseline->removeNonBaselinableViolations(
            $baseline->rekeyByRelativePath(self::validateViolations($path, $violations))
        );
        $baseline->recount();

        $generated = $data['generated'] ?? null;
        if ($generated !== null && ! is_string($generated)) {
            throw BaselineException::invalidStructure($path, 'generated must be an ISO-8601 string or null.');
        }

        $baseline->generated = $generated;

        return $baseline;
    }

    public function save(?string $path = null): bool
    {
        $path ??= $this->path;

        $this->generated = date('c');

        $data = [
            'version' => self::VERSION,
            'generated' => $this->generated,
            'violations' => $this->violations,
            'counts' => $this->counts,
        ];

        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        return FileSystem::writeFileAtomically($path, $json);
    }

    /**
     * @param  array<array-key, mixed>  $violations
     * @return array<string, list<BaselineEntry>>
     *
     * @throws BaselineException
     */
    private static function validateViolations(string $path, array $violations): array
    {
        $validated = [];

        foreach ($violations as $filePath => $entries) {
            if (! is_string($filePath) || $filePath === '' || ! is_array($entries) || ! array_is_list($entries)) {
                throw BaselineException::invalidStructure($path, 'each violation key must be a file path mapped to a list of entries.');
            }

            $validated[$filePath] = [];

            foreach ($entries as $entry) {
                $validated[$filePath][] = self::validateViolationEntry($path, $filePath, $entry);
            }
        }

        return $validated;
    }

    /**
     * @return BaselineEntry
     *
     * @throws BaselineException
     */
    private static function validateViolationEntry(string $path, string $filePath, mixed $entry): array
    {
        if (! is_array($entry)) {
            self::invalidViolationEntry($path, $filePath);
        }

        $ruleId = $entry['ruleId'] ?? null;
        $line = $entry['line'] ?? null;
        $message = $entry['message'] ?? null;
        $hash = $entry['hash'] ?? null;

        if (! is_string($ruleId) || trim($ruleId) === '') {
            self::invalidViolationEntry($path, $filePath);
        }

        if (! is_int($line) || $line < 1) {
            self::invalidViolationEntry($path, $filePath);
        }

        if (! is_string($message) || ! is_string($hash)) {
            self::invalidViolationEntry($path, $filePath);
        }

        return compact('ruleId', 'line', 'message', 'hash');
    }

    /**
     * @throws BaselineException
     */
    private static function invalidViolationEntry(string $path, string $filePath): never
    {
        throw BaselineException::invalidStructure($path, "violation entries for [{$filePath}] have an invalid shape.");
    }

    private function recount(): void
    {
        $this->counts = ['total' => 0, 'byRule' => []];

        foreach ($this->violations as $entries) {
            foreach ($entries as $entry) {
                $this->counts['total']++;
                $this->counts['byRule'][$entry['ruleId']] = ($this->counts['byRule'][$entry['ruleId']] ?? 0) + 1;
            }
        }
    }

    public function addViolation(Violation $violation, string $contentHash): void
    {
        if (! $this->isBaselinableRule($violation->ruleId)) {
            return;
        }

        $filePath = $this->normalizePath($violation->filePath);
        $this->violations[$filePath][] = self::entryFromViolation($violation, $contentHash);

        $this->counts['total']++;
        $this->counts['byRule'][$violation->ruleId] = ($this->counts['byRule'][$violation->ruleId] ?? 0) + 1;
    }

    public function isBaselined(Violation $violation, string $contentHash): bool
    {
        if (! $this->isBaselinableRule($violation->ruleId)) {
            return false;
        }

        $filePath = $this->normalizePath($violation->filePath);

        if (! isset($this->violations[$filePath])) {
            return false;
        }

        foreach ($this->violations[$filePath] as $baselined) {
            if ($this->matchesViolation($violation, $contentHash, $baselined)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<LintResult>  $results
     * @param  callable(Violation): string  $hashGenerator
     * @return array<LintResult>
     */
    public function filterResults(array $results, callable $hashGenerator): array
    {
        return array_map(
            fn (LintResult $result) => $this->filterResult($result, $hashGenerator),
            $results
        );
    }

    /**
     * @param  callable(Violation): string  $hashGenerator
     */
    public function filterResult(LintResult $result, callable $hashGenerator): LintResult
    {
        $hashes = array_map(fn (Violation $v): string => (string) $hashGenerator($v), $result->violations);
        $unmatched = $this->unmatchedViolations($result, $hashes);

        return new LintResult(
            $result->filePath,
            array_values($unmatched),
            $result->hasParseErrors,
            $result->sourceHash,
        );
    }

    /**
     * @param  callable(Violation): string  $hashGenerator
     */
    public function recordUnbaselined(LintResult $result, callable $hashGenerator): void
    {
        $hashes = array_map(fn (Violation $v): string => (string) $hashGenerator($v), $result->violations);

        foreach ($this->unmatchedViolations($result, $hashes) as $position => $violation) {
            $this->addViolation($violation, $hashes[$position]);
        }
    }

    /**
     * @param  array<array-key, string>  $hashes
     * @return array<array-key, Violation>
     */
    private function unmatchedViolations(LintResult $result, array $hashes): array
    {
        $entries = $this->violations[$this->normalizePath($result->filePath)] ?? [];
        $index = new BaselineEntryIndex($entries);
        $unmatched = $result->violations;

        foreach ($unmatched as $position => $violation) {
            if (! $this->isBaselinableRule($violation->ruleId)) {
                continue;
            }

            if ($index->claimExact($violation->ruleId, $hashes[$position]) !== null) {
                unset($unmatched[$position]);
            }
        }

        foreach ($unmatched as $position => $violation) {
            if (! $this->isBaselinableRule($violation->ruleId)) {
                continue;
            }

            if ($index->claimTolerant(
                $violation->ruleId,
                $violation->message,
                $violation->getLine(),
                $this->lineTolerance,
            ) !== null) {
                unset($unmatched[$position]);
            }
        }

        return $unmatched;
    }

    private function isBaselinableRule(string $ruleId): bool
    {
        return ! in_array($ruleId, self::NON_BASELINABLE_RULE_IDS, true);
    }

    /**
     * @param  array<string, list<BaselineEntry>>  $violations
     * @return array<string, list<BaselineEntry>>
     */
    private function removeNonBaselinableViolations(array $violations): array
    {
        foreach ($violations as $filePath => $entries) {
            $entries = array_values(array_filter(
                $entries,
                fn (array $entry): bool => $this->isBaselinableRule($entry['ruleId'])
            ));

            if ($entries === []) {
                unset($violations[$filePath]);

                continue;
            }

            $violations[$filePath] = $entries;
        }

        return $violations;
    }

    /**
     * @param  array<LintResult>  $currentResults
     * @param  callable(Violation): string  $hashGenerator
     */
    public function pruneFixed(array $currentResults, callable $hashGenerator): void
    {
        $currentMap = [];
        foreach ($currentResults as $result) {
            $filePath = $this->normalizePath($result->filePath);
            $currentMap[$filePath] = [];

            foreach ($result->violations as $violation) {
                $hash = (string) $hashGenerator($violation);
                $currentMap[$filePath][] = self::entryFromViolation($violation, $hash);
            }
        }

        $newViolations = [];

        foreach ($this->violations as $filePath => $baselinedViolations) {
            if (! isset($currentMap[$filePath])) {
                $newViolations[$filePath] = $baselinedViolations;

                continue;
            }

            $index = new BaselineEntryIndex($currentMap[$filePath]);
            $keptViolations = [];

            foreach ($baselinedViolations as $baselined) {
                if ($index->claimMatching($baselined, $this->lineTolerance) !== null) {
                    $keptViolations[] = $baselined;
                }
            }

            if (! empty($keptViolations)) {
                $newViolations[$filePath] = $keptViolations;
            }
        }

        $this->violations = $newViolations;
        $this->recount();
    }

    public function getTotalCount(): int
    {
        return $this->counts['total'];
    }

    /**
     * @return array<string, int>
     */
    public function getCountsByRule(): array
    {
        return $this->counts['byRule'];
    }

    /**
     * @return list<BaselineEntry>
     */
    public function getViolationsForFile(string $filePath): array
    {
        $normalized = $this->normalizePath($filePath);

        return $this->violations[$normalized] ?? [];
    }

    /**
     * @return array<string>
     */
    public function getFilePaths(): array
    {
        return array_keys($this->violations);
    }

    public function isEmpty(): bool
    {
        return $this->counts['total'] === 0;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getGeneratedAt(): ?string
    {
        return $this->generated;
    }

    public function getLineTolerance(): int
    {
        return $this->lineTolerance;
    }

    private function isWithinTolerance(int $currentLine, int $baselinedLine): bool
    {
        return abs($currentLine - $baselinedLine) <= $this->lineTolerance;
    }

    /**
     * @param  BaselineEntry  $baselined
     */
    private function matchesViolation(Violation $violation, string $contentHash, array $baselined): bool
    {
        if ($violation->ruleId !== $baselined['ruleId']) {
            return false;
        }

        if ($contentHash === $baselined['hash']) {
            return true;
        }

        if ($violation->message !== $baselined['message']) {
            return false;
        }

        return $this->isWithinTolerance($violation->getLine(), $baselined['line']);
    }

    /**
     * @param  array<string, list<BaselineEntry>>  $violations
     * @return array<string, list<BaselineEntry>>
     */
    private function rekeyByRelativePath(array $violations): array
    {
        $rekeyed = [];

        foreach ($violations as $filePath => $entries) {
            $key = $this->normalizePath($filePath);

            foreach ($entries as $entry) {
                $rekeyed[$key][] = $entry;
            }
        }

        return $rekeyed;
    }

    private function normalizePath(string $path): string
    {
        return PathResolver::toRelativePath($path);
    }

    /** @return BaselineEntry */
    private static function entryFromViolation(Violation $violation, string $hash): array
    {
        return [
            'ruleId' => $violation->ruleId,
            'line' => $violation->getLine(),
            'message' => $violation->message,
            'hash' => $hash,
        ];
    }
}
