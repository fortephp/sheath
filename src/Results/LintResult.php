<?php

declare(strict_types=1);

namespace Forte\Sheath\Results;

use Forte\Sheath\Parallel\Contracts\WorkerResult;
use InvalidArgumentException;

final readonly class LintResult implements WorkerResult
{
    /**
     * @param  array<Violation>  $violations
     */
    public function __construct(
        public string $filePath,
        public array $violations,
        public bool $hasParseErrors = false,
        public ?string $sourceHash = null,
    ) {}

    public function hasViolations(): bool
    {
        return count($this->violations) > 0;
    }

    public function hasErrors(): bool
    {
        foreach ($this->violations as $violation) {
            if ($violation->severity->isError()) {
                return true;
            }
        }

        return false;
    }

    public function hasWarnings(): bool
    {
        foreach ($this->violations as $violation) {
            if ($violation->severity->isWarning()) {
                return true;
            }
        }

        return false;
    }

    public function hasInfos(): bool
    {
        foreach ($this->violations as $violation) {
            if ($violation->severity === Severity::INFO) {
                return true;
            }
        }

        return false;
    }

    public function getErrorCount(): int
    {
        $count = 0;

        foreach ($this->violations as $violation) {
            if ($violation->severity->isError()) {
                $count++;
            }
        }

        return $count;
    }

    public function getWarningCount(): int
    {
        $count = 0;

        foreach ($this->violations as $violation) {
            if ($violation->severity->isWarning()) {
                $count++;
            }
        }

        return $count;
    }

    public function getInfoCount(): int
    {
        $count = 0;

        foreach ($this->violations as $violation) {
            if ($violation->severity === Severity::INFO) {
                $count++;
            }
        }

        return $count;
    }

    public function getFixableCount(): int
    {
        $count = 0;

        foreach ($this->violations as $violation) {
            if ($violation->hasFixAvailable()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array<Fix>
     */
    public function getFixes(): array
    {
        $fixes = [];

        foreach ($this->violations as $violation) {
            if ($violation->fix !== null) {
                $fixes[] = $violation->fix;
            }
        }

        return $fixes;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        if (! isset($data['filePath']) || ! is_string($data['filePath']) || $data['filePath'] === '') {
            throw new InvalidArgumentException('Lint result filePath must be a non-empty string.');
        }

        if (! isset($data['violations']) || ! is_array($data['violations']) || ! array_is_list($data['violations'])) {
            throw new InvalidArgumentException('Lint result violations must be a list.');
        }

        if (isset($data['hasParseErrors']) && ! is_bool($data['hasParseErrors'])) {
            throw new InvalidArgumentException('Lint result hasParseErrors must be a boolean.');
        }

        if (isset($data['sourceHash']) && ! is_string($data['sourceHash'])) {
            throw new InvalidArgumentException('Lint result sourceHash must be a string or null.');
        }

        $violations = [];
        foreach ($data['violations'] as $rawViolation) {
            if (! is_array($rawViolation)) {
                throw new InvalidArgumentException('Every lint result violation must be an object.');
            }

            /** @var array<string, mixed> $rawViolation */
            $violations[] = Violation::fromArray($rawViolation);
        }

        return new self(
            filePath: $data['filePath'],
            violations: $violations,
            hasParseErrors: $data['hasParseErrors'] ?? false,
            sourceHash: $data['sourceHash'] ?? null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'filePath' => $this->filePath,
            'violations' => array_map(fn (Violation $v) => $v->toArray(), $this->violations),
            'hasParseErrors' => $this->hasParseErrors,
            'errorCount' => $this->getErrorCount(),
            'warningCount' => $this->getWarningCount(),
            'infoCount' => $this->getInfoCount(),
            'fixableCount' => $this->getFixableCount(),
        ];

        if ($this->sourceHash !== null) {
            $data['sourceHash'] = $this->sourceHash;
        }

        return $data;
    }
}
