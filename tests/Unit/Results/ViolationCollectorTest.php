<?php

declare(strict_types=1);

use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;
use Forte\Sheath\Results\ViolationCollector;

describe('Violation Collector', function (): void {
    beforeEach(function (): void {
        $this->collector = new ViolationCollector;
    });

    it('can sort violations by position', function (): void {
        foreach ([[50, 3, 1], [30, 1, 20], [10, 1, 5]] as [$offset, $line, $column]) {
            $this->collector->add(new Violation(
                'test-rule',
                'Test message',
                Severity::ERROR,
                'test.blade.php',
                new Position($offset, $line, $column),
                new Position($offset + 5, $line, $column + 5),
            ));
        }

        $this->collector->sort();

        $violations = $this->collector->all();

        expect(array_map(
            fn (Violation $violation): array => [$violation->start->line, $violation->start->character],
            $violations,
        ))->toBe([[1, 5], [1, 20], [3, 1]]);
    });
});
