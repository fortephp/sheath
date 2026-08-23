<?php

declare(strict_types=1);

namespace Forte\Sheath\Results;

use Forte\Ast\Node;
use InvalidArgumentException;
use UnexpectedValueException;

readonly class Violation
{
    public string $message;

    public function __construct(
        public string $ruleId,
        string $message,
        public Severity $severity,
        public string $filePath,
        public Position $start,
        public Position $end,
        public ?Fix $fix = null,
    ) {
        $this->message = self::normalizeMessage($message);
    }

    private static function normalizeMessage(string $message): string
    {
        if (preg_match('//u', $message) !== 1) {
            $encoded = json_encode($message, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            $decoded = json_decode($encoded, flags: JSON_THROW_ON_ERROR);

            if (! is_string($decoded)) {
                throw new UnexpectedValueException('Failed to normalize a diagnostic message as UTF-8.');
            }

            $message = $decoded;
        }

        return trim((string) preg_replace('/\s*\R\s*/u', ' ', $message));
    }

    public static function fromNode(
        string $ruleId,
        string $message,
        Severity $severity,
        string $filePath,
        Node $node,
        ?Fix $fix = null,
    ): self {
        return new self(
            $ruleId,
            $message,
            $severity,
            $filePath,
            Position::fromNode($node, false),
            Position::fromNode($node, true),
            $fix,
        );
    }

    public function getLine(): int
    {
        return $this->start->line;
    }

    public function getColumn(): int
    {
        return $this->start->character;
    }

    public function hasFixAvailable(): bool
    {
        return $this->fix !== null;
    }

    public function hasDangerousFix(): bool
    {
        return $this->fix !== null && $this->fix->dangerous;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['ruleId', 'message', 'severity', 'filePath'] as $key) {
            if (! isset($data[$key]) || ! is_string($data[$key])) {
                throw new InvalidArgumentException("Violation {$key} must be a string.");
            }
        }

        if ($data['ruleId'] === '' || $data['filePath'] === '') {
            throw new InvalidArgumentException('Violation ruleId and filePath must be non-empty strings.');
        }

        foreach (['offset', 'line', 'column', 'endOffset', 'endLine', 'endColumn'] as $key) {
            if (! isset($data[$key]) || ! is_int($data[$key])) {
                throw new InvalidArgumentException("Violation {$key} must be an integer.");
            }
        }

        if (self::hasInvalidPositions($data)) {
            throw new InvalidArgumentException('Violation positions are invalid.');
        }

        $severity = Severity::tryFrom($data['severity']);
        if ($severity === null) {
            throw new InvalidArgumentException("Violation severity [{$data['severity']}] is invalid.");
        }

        $fix = null;
        if (isset($data['fix'])) {
            if (! is_array($data['fix'])) {
                throw new InvalidArgumentException('Violation fix must be an object or null.');
            }

            /** @var array<string, mixed> $fixData */
            $fixData = $data['fix'];
            $fix = Fix::fromArray($fixData);
        }

        return new self(
            ruleId: $data['ruleId'],
            message: $data['message'],
            severity: $severity,
            filePath: $data['filePath'],
            start: new Position($data['offset'], $data['line'], $data['column']),
            end: new Position($data['endOffset'], $data['endLine'], $data['endColumn']),
            fix: $fix,
        );
    }

    /** @param array<string, mixed> $data */
    private static function hasInvalidPositions(array $data): bool
    {
        $offsetsAreInvalid = $data['offset'] < 0
            || $data['endOffset'] < $data['offset'];
        $linesAreInvalid = $data['line'] < 1
            || $data['endLine'] < $data['line'];
        $columnsAreInvalid = $data['column'] < 1
            || $data['endColumn'] < 1;
        $sameLineRangeIsReversed = $data['endLine'] === $data['line']
            && $data['endColumn'] < $data['column'];

        return $offsetsAreInvalid
            || $linesAreInvalid
            || $columnsAreInvalid
            || $sameLineRangeIsReversed;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ruleId' => $this->ruleId,
            'message' => $this->message,
            'severity' => $this->severity->value,
            'filePath' => $this->filePath,
            'offset' => $this->start->offset,
            'line' => $this->start->line,
            'column' => $this->start->character,
            'endOffset' => $this->end->offset,
            'endLine' => $this->end->line,
            'endColumn' => $this->end->character,
            'fix' => $this->fix?->toArray(),
            'fixAvailable' => $this->hasFixAvailable(),
            'dangerousFix' => $this->hasDangerousFix(),
        ];
    }
}
