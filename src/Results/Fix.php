<?php

declare(strict_types=1);

namespace Forte\Sheath\Results;

use Forte\Ast\Node;
use InvalidArgumentException;

readonly class Fix
{
    public const PRIORITY_TRAILING_SYNTAX = 100;

    public function __construct(
        public int $startOffset,
        public int $endOffset,
        public string $replacement,
        public bool $dangerous = false,
        public int $priority = 0,
    ) {
        if ($this->startOffset < 0 || $this->endOffset < $this->startOffset) {
            throw new InvalidArgumentException('Fix offsets are invalid.');
        }
    }

    public static function fromNode(Node $node, string $replacement, bool $dangerous = false): self
    {
        return new self(
            $node->startOffset(),
            $node->endOffset(),
            $replacement,
            $dangerous
        );
    }

    public static function dangerous(int $startOffset, int $endOffset, string $replacement): self
    {
        return new self($startOffset, $endOffset, $replacement, true);
    }

    public static function dangerousFromNode(Node $node, string $replacement): self
    {
        return self::fromNode($node, $replacement, true);
    }

    public function apply(string $content): string
    {
        if ($this->endOffset > strlen($content)) {
            throw new InvalidArgumentException('Fix range exceeds the content length.');
        }

        return substr_replace(
            $content,
            $this->replacement,
            $this->startOffset,
            $this->endOffset - $this->startOffset
        );
    }

    /**
     * @return array{startOffset: int, endOffset: int, replacement: string, dangerous: bool, priority?: int}
     */
    public function toArray(): array
    {
        $data = [
            'startOffset' => $this->startOffset,
            'endOffset' => $this->endOffset,
            'replacement' => $this->replacement,
            'dangerous' => $this->dangerous,
        ];

        if ($this->priority !== 0) {
            $data['priority'] = $this->priority;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['startOffset', 'endOffset'] as $key) {
            if (! isset($data[$key]) || ! is_int($data[$key])) {
                throw new InvalidArgumentException("Fix {$key} must be an integer.");
            }
        }

        if ($data['startOffset'] < 0 || $data['endOffset'] < $data['startOffset']) {
            throw new InvalidArgumentException('Fix offsets are invalid.');
        }

        if (! isset($data['replacement']) || ! is_string($data['replacement'])) {
            throw new InvalidArgumentException('Fix replacement must be a string.');
        }

        if (isset($data['dangerous']) && ! is_bool($data['dangerous'])) {
            throw new InvalidArgumentException('Fix dangerous must be a boolean.');
        }

        if (isset($data['priority']) && ! is_int($data['priority'])) {
            throw new InvalidArgumentException('Fix priority must be an integer.');
        }

        return new self(
            startOffset: $data['startOffset'],
            endOffset: $data['endOffset'],
            replacement: $data['replacement'],
            dangerous: $data['dangerous'] ?? false,
            priority: $data['priority'] ?? 0,
        );
    }
}
