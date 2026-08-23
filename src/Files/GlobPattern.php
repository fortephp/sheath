<?php

declare(strict_types=1);

namespace Forte\Sheath\Files;

/** @internal */
final class GlobPattern
{
    public static function toRegex(string $glob, bool $leadingSlashAnchors = false): string
    {
        $glob = str_replace('\\', '/', $glob);
        $anchored = $leadingSlashAnchors && str_starts_with($glob, '/');
        $glob = trim($glob, '/');

        $expandBraces = self::hasBalancedBraces($glob);
        $length = strlen($glob);
        $braceDepth = 0;
        $regex = '';

        for ($i = 0; $i < $length; $i++) {
            $character = $glob[$i];

            if ($character === '*') {
                if (($glob[$i + 1] ?? '') !== '*') {
                    $regex .= '[^/]*';

                    continue;
                }

                $i++;

                if (($glob[$i + 1] ?? '') === '/') {
                    $i++;
                    $regex .= '(?:.*/)?';

                    continue;
                }

                $regex .= '.*';

                continue;
            }

            if ($character === '?') {
                $regex .= '[^/]';

                continue;
            }

            if ($expandBraces && $character === '{') {
                $braceDepth++;
                $regex .= '(?:';

                continue;
            }

            if ($expandBraces && $character === '}' && $braceDepth > 0) {
                $braceDepth--;
                $regex .= ')';

                continue;
            }

            if ($braceDepth > 0 && $character === ',') {
                $regex .= '|';

                continue;
            }

            $regex .= preg_quote($character, '#');
        }

        $start = $anchored ? '^' : '(?:^|/)';

        return '#'.$start.$regex.'(?:$|/)#u';
    }

    private static function hasBalancedBraces(string $glob): bool
    {
        $depth = 0;

        for ($i = 0, $length = strlen($glob); $i < $length; $i++) {
            if ($glob[$i] === '{') {
                $depth++;

                continue;
            }

            if ($glob[$i] !== '}') {
                continue;
            }

            if ($depth === 0) {
                return false;
            }

            $depth--;
        }

        return $depth === 0;
    }
}
