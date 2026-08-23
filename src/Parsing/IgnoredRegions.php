<?php

declare(strict_types=1);

namespace Forte\Sheath\Parsing;

use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Violation;

/** @internal */
final class IgnoredRegions
{
    /** @param list<IgnoredRegion> $regions */
    public static function mask(string $source, array $regions): string
    {
        if ($regions === []) {
            return $source;
        }

        $parts = [];
        $offset = 0;

        foreach ($regions as $region) {
            $parts[] = substr($source, $offset, $region->startOffset - $offset);
            $ignored = substr($source, $region->startOffset, $region->endOffset - $region->startOffset);
            $parts[] = (string) preg_replace('/[^\r\n]/', ' ', $ignored);
            $offset = $region->endOffset;
        }

        $parts[] = substr($source, $offset);

        return implode('', $parts);
    }

    /**
     * @param  array<Violation>  $violations
     * @param  list<IgnoredRegion>  $regions
     * @return array<Violation>
     */
    public static function protectFixes(array $violations, array $regions): array
    {
        if ($regions === []) {
            return $violations;
        }

        $filtered = [];

        foreach ($violations as $violation) {
            $fix = $violation->fix;
            if ($fix !== null && self::intersectsFix($regions, $fix)) {
                $violation = new Violation(
                    $violation->ruleId,
                    $violation->message,
                    $violation->severity,
                    $violation->filePath,
                    $violation->start,
                    $violation->end,
                );
            }

            $filtered[] = $violation;
        }

        return $filtered;
    }

    /** @param list<IgnoredRegion> $regions */
    private static function intersectsFix(array $regions, Fix $fix): bool
    {
        foreach ($regions as $region) {
            if ($fix->endOffset < $region->startOffset) {
                return false;
            }

            if ($fix->startOffset === $fix->endOffset) {
                if ($fix->startOffset < $region->endOffset) {
                    return true;
                }

                continue;
            }

            if ($fix->startOffset < $region->endOffset && $fix->endOffset > $region->startOffset) {
                return true;
            }
        }

        return false;
    }
}
