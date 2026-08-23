<?php

declare(strict_types=1);

namespace Forte\Sheath\Analysis;

use Forte\Ast\Document\Document;
use InvalidArgumentException;
use WeakMap;

final class AnalysisStore
{
    /** @var WeakMap<Document, array<class-string, object>> */
    private WeakMap $analyses;

    public function __construct()
    {
        $this->analyses = new WeakMap;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $type
     * @param  callable(): T  $factory
     * @return T
     */
    public function remember(Document $document, string $type, callable $factory): object
    {
        $analyses = $this->analyses[$document] ?? [];
        $cached = $analyses[$type] ?? null;
        if ($cached instanceof $type) {
            return $cached;
        }

        $analysis = $factory();
        if (! $analysis instanceof $type) {
            throw new InvalidArgumentException(
                'Analysis factory for ['.$type.'] returned ['.get_debug_type($analysis).'].'
            );
        }

        $analyses[$type] = $analysis;
        $this->analyses[$document] = $analyses;

        return $analysis;
    }
}
