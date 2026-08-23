<?php

declare(strict_types=1);

namespace Forte\Sheath\Results;

/** @internal */
class ViolationCollector
{
    /**
     * @var array<Violation>
     */
    private array $violations = [];

    public function add(Violation $violation): void
    {
        $this->violations[] = $violation;
    }

    /**
     * @return array<Violation>
     */
    public function all(): array
    {
        return $this->violations;
    }

    public function sort(): void
    {
        if (count($this->violations) < 2) {
            return;
        }

        usort($this->violations, function (Violation $a, Violation $b) {
            if ($a->start->line !== $b->start->line) {
                return $a->start->line <=> $b->start->line;
            }

            return $a->start->character <=> $b->start->character;
        });
    }
}
