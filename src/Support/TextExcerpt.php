<?php

declare(strict_types=1);

namespace Forte\Sheath\Support;

/** @internal */
final class TextExcerpt
{
    public static function bytes(string $text, int $maximumBytes, int $prefixBytes): string
    {
        if (strlen($text) <= $maximumBytes) {
            return $text;
        }

        return mb_strcut($text, 0, $prefixBytes, 'UTF-8').'...';
    }
}
