<?php

declare(strict_types=1);

namespace Forte\Sheath\Parallel\Contracts;

/** @internal */
interface WorkerResult
{
    public function getFilePath(): string;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static;
}
