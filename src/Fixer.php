<?php

declare(strict_types=1);

namespace Forte\Sheath;

use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\FixResult;
use InvalidArgumentException;

class Fixer
{
    /**
     * @param  array<Fix>  $fixes
     */
    public function applyFixes(string $content, array $fixes, bool $includeDangerous = false): FixResult
    {
        if (empty($fixes)) {
            return new FixResult(
                content: $content,
                appliedCount: 0,
            );
        }

        $applicableFixes = array_filter(
            $fixes,
            fn (Fix $fix) => $includeDangerous || ! $fix->dangerous
        );

        $skippedCount = count($fixes) - count($applicableFixes);

        if (empty($applicableFixes)) {
            return new FixResult(
                content: $content,
                appliedCount: 0,
                skippedCount: $skippedCount,
            );
        }

        $applicableFixes = array_values($applicableFixes);

        $contentLength = strlen($content);
        $effectiveFixes = [];
        foreach ($applicableFixes as $fix) {
            if ($fix->endOffset > $contentLength) {
                // Preserve Fix::apply()'s public validation contract.
                $fix->apply($content);
            }

            $length = $fix->endOffset - $fix->startOffset;
            if (substr($content, $fix->startOffset, $length) === $fix->replacement) {
                $skippedCount++;

                continue;
            }

            $effectiveFixes[] = $fix;
        }

        if ($effectiveFixes === []) {
            return new FixResult(
                content: $content,
                appliedCount: 0,
                skippedCount: $skippedCount,
            );
        }

        [$accepted, $overlaps] = $this->resolveOverlaps($effectiveFixes);

        $skippedCount += count($effectiveFixes) - count($accepted);

        $content = $this->applyAcceptedFixes($content, $accepted);

        return new FixResult(
            content: $content,
            appliedCount: count($accepted),
            skippedCount: $skippedCount,
            hasOverlaps: $overlaps !== [],
            overlappingFixes: $overlaps,
        );
    }

    /**
     * @param  array<Fix>  $fixes
     * @return array{array<Fix>, array<array{Fix, Fix}>}
     */
    private function resolveOverlaps(array $fixes): array
    {
        usort($fixes, fn (Fix $a, Fix $b) => [$a->startOffset, $a->endOffset] <=> [$b->startOffset, $b->endOffset]);

        $accepted = [];
        $overlaps = [];

        foreach ($fixes as $fix) {
            $previous = $accepted[array_key_last($accepted)] ?? null;
            $conflict = $previous !== null && $this->fixesOverlap($previous, $fix)
                ? $previous
                : null;

            if ($conflict !== null) {
                $overlaps[] = [$conflict, $fix];

                continue;
            }

            $accepted[] = $fix;
        }

        return [$accepted, $overlaps];
    }

    /** @param array<Fix> $fixes */
    private function applyAcceptedFixes(string $content, array $fixes): string
    {
        $last = $fixes[array_key_last($fixes)] ?? null;
        if ($last !== null && $last->endOffset > strlen($content)) {
            throw new InvalidArgumentException('Fix range exceeds the content length.');
        }

        $chunks = [];
        $offset = 0;

        foreach ($this->composeSharedInsertions($fixes) as $fix) {
            $chunks[] = substr($content, $offset, $fix->startOffset - $offset);
            $chunks[] = $fix->replacement;
            $offset = $fix->endOffset;
        }

        $chunks[] = substr($content, $offset);

        return implode('', $chunks);
    }

    /**
     * @param  array<Fix>  $fixes
     * @return array<Fix>
     */
    private function composeSharedInsertions(array $fixes): array
    {
        $composed = [];

        for ($index = 0, $count = count($fixes); $index < $count; $index++) {
            $fix = $fixes[$index];

            if ($fix->startOffset !== $fix->endOffset) {
                $composed[] = $fix;

                continue;
            }

            $shared = [$fix];
            while (isset($fixes[$index + 1])
                && $fixes[$index + 1]->startOffset === $fix->startOffset
                && $fixes[$index + 1]->endOffset === $fix->endOffset) {
                $shared[] = $fixes[++$index];
            }

            if (count($shared) === 1) {
                $composed[] = $fix;

                continue;
            }

            usort($shared, fn (Fix $left, Fix $right): int => $left->priority <=> $right->priority);

            $composed[] = new Fix(
                $fix->startOffset,
                $fix->endOffset,
                implode('', array_map(fn (Fix $item): string => $item->replacement, $shared)),
            );
        }

        return $composed;
    }

    private function fixesOverlap(Fix $a, Fix $b): bool
    {
        if ($a->startOffset === $b->startOffset
            && ($a->startOffset !== $a->endOffset || $b->startOffset !== $b->endOffset)) {
            return true;
        }

        return $a->startOffset < $b->endOffset && $b->startOffset < $a->endOffset;
    }
}
