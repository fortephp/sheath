<?php

declare(strict_types=1);

namespace Forte\Sheath\Parsing;

/** @internal */
final class JsSourceScanner
{
    private const CODE = 'code';

    private const SINGLE_QUOTED = 'single';

    private const DOUBLE_QUOTED = 'double';

    private const TEMPLATE = 'template';

    private const LINE_COMMENT = 'lineComment';

    private const BLOCK_COMMENT = 'blockComment';

    /**
     * Classify multiple offsets with one forward scan of the source.
     *
     * @param  list<int>  $positions
     * @param  array<array{int, int}>  $opaqueSpans  [start, end) offset pairs to skip
     * @return array<int, bool>
     */
    public static function classifyPositions(
        string $source,
        int $from,
        array $positions,
        array $opaqueSpans = [],
    ): array {
        if ($positions === []) {
            return [];
        }

        $positions = array_values(array_unique($positions, SORT_NUMERIC));
        sort($positions, SORT_NUMERIC);
        usort($opaqueSpans, static fn (array $left, array $right): int => $left <=> $right);

        $scanner = new self;
        $offset = $from;
        $spanIndex = 0;
        $classified = [];

        foreach ($positions as $position) {
            $scanner->scanUntil($source, $offset, $position, $opaqueSpans, $spanIndex);
            $classified[$position] = $scanner->state === self::CODE;
        }

        return $classified;
    }

    /**
     * @var self::CODE|self::SINGLE_QUOTED|self::DOUBLE_QUOTED|self::TEMPLATE|self::LINE_COMMENT|self::BLOCK_COMMENT
     */
    private string $state = self::CODE;

    /** @param array<array{int, int}> $opaqueSpans */
    private function scanUntil(
        string $source,
        int &$offset,
        int $until,
        array $opaqueSpans,
        int &$spanIndex,
    ): void {
        $spanCount = count($opaqueSpans);

        while ($offset < $until) {
            while ($spanIndex < $spanCount && $opaqueSpans[$spanIndex][1] <= $offset) {
                $spanIndex++;
            }

            if ($spanIndex < $spanCount
                && $offset >= $opaqueSpans[$spanIndex][0]
                && $offset < $opaqueSpans[$spanIndex][1]) {
                $offset = $opaqueSpans[$spanIndex][1];

                continue;
            }

            $offset += $this->consume($source[$offset], $source[$offset + 1] ?? '');
        }
    }

    private function consume(string $char, string $next): int
    {
        return match ($this->state) {
            self::CODE => $this->consumeCode($char, $next),
            self::SINGLE_QUOTED => $this->consumeQuoted($char, "'"),
            self::DOUBLE_QUOTED => $this->consumeQuoted($char, '"'),
            self::TEMPLATE => $this->consumeTemplate($char),
            self::LINE_COMMENT => $this->consumeLineComment($char),
            self::BLOCK_COMMENT => $this->consumeBlockComment($char, $next),
        };
    }

    private function consumeCode(string $char, string $next): int
    {
        if ($char === '/' && $next === '/') {
            $this->state = self::LINE_COMMENT;

            return 2;
        }

        if ($char === '/' && $next === '*') {
            $this->state = self::BLOCK_COMMENT;

            return 2;
        }

        $this->state = match ($char) {
            "'" => self::SINGLE_QUOTED,
            '"' => self::DOUBLE_QUOTED,
            '`' => self::TEMPLATE,
            default => self::CODE,
        };

        return 1;
    }

    private function consumeQuoted(string $char, string $terminator): int
    {
        if ($char === '\\') {
            return 2;
        }

        if ($char === $terminator || $char === "\n") {
            $this->state = self::CODE;
        }

        return 1;
    }

    private function consumeTemplate(string $char): int
    {
        if ($char === '\\') {
            return 2;
        }

        if ($char === '`') {
            $this->state = self::CODE;
        }

        return 1;
    }

    private function consumeLineComment(string $char): int
    {
        if ($char === "\n") {
            $this->state = self::CODE;
        }

        return 1;
    }

    private function consumeBlockComment(string $char, string $next): int
    {
        if ($char === '*' && $next === '/') {
            $this->state = self::CODE;

            return 2;
        }

        return 1;
    }
}
