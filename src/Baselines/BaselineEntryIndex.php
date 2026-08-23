<?php

declare(strict_types=1);

namespace Forte\Sheath\Baselines;

/**
 * @internal
 *
 * @phpstan-type BaselineEntry array{ruleId: string, line: int, message: string, hash: string}
 * @phpstan-type EntryQueue array{indices: list<int>, cursor: int}
 * @phpstan-type ToleranceGroup array{lines: array<int, EntryQueue>, all: EntryQueue, minLine: int, maxLine: int}
 */
final class BaselineEntryIndex
{
    /** @var array<string, array<string, EntryQueue>> */
    private array $exact = [];

    /** @var array<string, array<string, ToleranceGroup>> */
    private array $tolerant = [];

    /** @var array<int, true> */
    private array $claimed = [];

    /** @param list<BaselineEntry> $entries */
    public function __construct(array $entries)
    {
        foreach ($entries as $index => $entry) {
            $ruleId = self::key($entry['ruleId']);
            $hash = self::key($entry['hash']);
            $message = self::key($entry['message']);

            $this->exact[$ruleId][$hash] ??= self::queue();
            $this->exact[$ruleId][$hash]['indices'][] = $index;

            $this->tolerant[$ruleId][$message] ??= [
                'lines' => [],
                'all' => self::queue(),
                'minLine' => $entry['line'],
                'maxLine' => $entry['line'],
            ];

            $group = &$this->tolerant[$ruleId][$message];
            $group['lines'][$entry['line']] ??= self::queue();
            $group['lines'][$entry['line']]['indices'][] = $index;
            $group['all']['indices'][] = $index;
            $group['minLine'] = min($group['minLine'], $entry['line']);
            $group['maxLine'] = max($group['maxLine'], $entry['line']);
            unset($group);
        }
    }

    public function claimExact(string $ruleId, string $hash): ?int
    {
        return $this->claim($this->findExact($ruleId, $hash));
    }

    public function claimTolerant(string $ruleId, string $message, int $line, int $tolerance): ?int
    {
        return $this->claim($this->findTolerant($ruleId, $message, $line, $tolerance));
    }

    /** @param BaselineEntry $entry */
    public function claimMatching(array $entry, int $tolerance): ?int
    {
        $exact = $this->findExact($entry['ruleId'], $entry['hash']);
        $tolerant = $this->findTolerant($entry['ruleId'], $entry['message'], $entry['line'], $tolerance);

        if ($exact === null) {
            return $this->claim($tolerant);
        }

        if ($tolerant === null) {
            return $this->claim($exact);
        }

        return $this->claim(min($exact, $tolerant));
    }

    private function findExact(string $ruleId, string $hash): ?int
    {
        $ruleId = self::key($ruleId);
        $hash = self::key($hash);

        if (! isset($this->exact[$ruleId][$hash])) {
            return null;
        }

        return $this->firstAvailable($this->exact[$ruleId][$hash]);
    }

    private function findTolerant(string $ruleId, string $message, int $line, int $tolerance): ?int
    {
        if ($tolerance < 0) {
            return null;
        }

        $ruleId = self::key($ruleId);
        $message = self::key($message);

        if (! isset($this->tolerant[$ruleId][$message])) {
            return null;
        }

        $group = &$this->tolerant[$ruleId][$message];
        $lowestLine = $tolerance >= $line ? 1 : $line - $tolerance;
        $highestLine = $tolerance > PHP_INT_MAX - $line ? PHP_INT_MAX : $line + $tolerance;

        if ($lowestLine <= $group['minLine'] && $highestLine >= $group['maxLine']) {
            $candidate = $this->firstAvailable($group['all']);
            unset($group);

            return $candidate;
        }

        $candidate = null;
        $span = $highestLine - $lowestLine + 1;

        if ($span <= count($group['lines'])) {
            for ($candidateLine = $lowestLine; $candidateLine <= $highestLine; $candidateLine++) {
                if (isset($group['lines'][$candidateLine])) {
                    $candidate = $this->earlier($candidate, $this->firstAvailable($group['lines'][$candidateLine]));
                }
            }
        } else {
            foreach ($group['lines'] as $candidateLine => &$queue) {
                if ($candidateLine >= $lowestLine && $candidateLine <= $highestLine) {
                    $candidate = $this->earlier($candidate, $this->firstAvailable($queue));
                }
            }
            unset($queue);
        }

        unset($group);

        return $candidate;
    }

    /** @param EntryQueue $queue */
    private function firstAvailable(array &$queue): ?int
    {
        $count = count($queue['indices']);

        while ($queue['cursor'] < $count) {
            $index = $queue['indices'][$queue['cursor']];

            if (! isset($this->claimed[$index])) {
                return $index;
            }

            $queue['cursor']++;
        }

        return null;
    }

    private function claim(?int $index): ?int
    {
        if ($index !== null) {
            $this->claimed[$index] = true;
        }

        return $index;
    }

    private function earlier(?int $current, ?int $candidate): ?int
    {
        if ($current === null) {
            return $candidate;
        }

        if ($candidate === null) {
            return $current;
        }

        return min($current, $candidate);
    }

    /** @return EntryQueue */
    private static function queue(): array
    {
        return ['indices' => [], 'cursor' => 0];
    }

    private static function key(string $value): string
    {
        return ':'.$value;
    }
}
