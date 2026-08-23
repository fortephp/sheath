<?php

declare(strict_types=1);

namespace Forte\Sheath\Parsing;

/** @internal */
final class SrcsetParser
{
    /** @return list<string> */
    public static function urls(string $srcset): array
    {
        return array_column(self::candidates($srcset), 'url');
    }

    public static function hasValidCandidate(string $srcset): bool
    {
        foreach (self::candidates($srcset) as $candidate) {
            if (self::descriptorsAreValid($candidate['descriptors'])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{url: string, descriptors: list<string>}> */
    private static function candidates(string $srcset): array
    {
        $candidates = [];
        $position = 0;
        $length = strlen($srcset);

        while ($position < $length) {
            while ($position < $length
                && (self::isAsciiWhitespace($srcset[$position]) || $srcset[$position] === ',')) {
                $position++;
            }

            if ($position >= $length) {
                break;
            }

            $urlStart = $position;
            while ($position < $length && ! self::isAsciiWhitespace($srcset[$position])) {
                $position++;
            }

            $url = substr($srcset, $urlStart, $position - $urlStart);
            $endedByComma = str_ends_with($url, ',');

            if ($endedByComma) {
                $url = rtrim($url, ',');
            }

            if ($url !== '') {
                $descriptors = [];

                if (! $endedByComma) {
                    $descriptorStart = $position;
                    $inParentheses = false;
                    while ($position < $length) {
                        $character = $srcset[$position];

                        if ($character === '(') {
                            $inParentheses = true;
                        } elseif ($character === ')') {
                            $inParentheses = false;
                        } elseif ($character === ',' && ! $inParentheses) {
                            break;
                        }

                        $position++;
                    }

                    $descriptorText = trim(substr($srcset, $descriptorStart, $position - $descriptorStart));
                    $descriptors = $descriptorText === ''
                        ? []
                        : (preg_split('/[\x09\x0A\x0C\x0D\x20]+/', $descriptorText) ?: []);
                }

                $candidates[] = ['url' => $url, 'descriptors' => $descriptors];
            }

            if ($endedByComma) {
                continue;
            }

            if ($position < $length && $srcset[$position] === ',') {
                $position++;
            }
        }

        return $candidates;
    }

    /** @param list<string> $descriptors */
    private static function descriptorsAreValid(array $descriptors): bool
    {
        if ($descriptors === []) {
            return true;
        }

        $seen = [];
        foreach ($descriptors as $descriptor) {
            if (preg_match('/^[0-9]+w$/', $descriptor) === 1) {
                $type = 'w';
                $valid = (int) substr($descriptor, 0, -1) > 0;
            } elseif (preg_match('/^[0-9]+h$/', $descriptor) === 1) {
                $type = 'h';
                $valid = (int) substr($descriptor, 0, -1) > 0;
            } elseif (preg_match('/^(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?x$/', $descriptor) === 1) {
                $type = 'x';
                $density = (float) substr($descriptor, 0, -1);
                $valid = $density > 0 && is_finite($density);
            } else {
                return false;
            }

            if (! $valid || isset($seen[$type])) {
                return false;
            }

            $seen[$type] = true;
        }

        if (isset($seen['x'])) {
            return count($seen) === 1;
        }

        return ! isset($seen['h']) || isset($seen['w']);
    }

    private static function isAsciiWhitespace(string $character): bool
    {
        return $character === ' '
            || $character === "\t"
            || $character === "\n"
            || $character === "\f"
            || $character === "\r";
    }
}
