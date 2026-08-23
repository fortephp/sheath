<?php

declare(strict_types=1);

namespace Forte\Sheath\Tests\Fixtures\Parallel;

use Forte\Sheath\Contracts\IgnoredRegionProvider;
use Forte\Sheath\Parsing\IgnoredRegion;

final class MarkerIgnoredRegionProvider implements IgnoredRegionProvider
{
    public function id(): string
    {
        return 'parallel-marker';
    }

    public function regions(string $source, string $filePath): iterable
    {
        $offset = 0;

        while (($start = strpos($source, '[[ignore]]', $offset)) !== false) {
            $end = strpos($source, '[[/ignore]]', $start + 10);
            $end = $end === false ? strlen($source) : $end + 11;
            yield new IgnoredRegion($start, $end);
            $offset = $end;
        }
    }

    public function cacheContext(): string
    {
        return 'v1';
    }
}
