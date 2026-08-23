<?php

declare(strict_types=1);

use Forte\Sheath\Results\Severity;

describe('Severity Enum', function (): void {
    it('converts every supported spelling', function (string $value, Severity $expected): void {
        expect(Severity::fromString($value))->toBe($expected);
    })->with([
        'error' => ['error', Severity::ERROR],
        'error number' => ['2', Severity::ERROR],
        'warning' => ['warning', Severity::WARNING],
        'warning alias' => ['warn', Severity::WARNING],
        'warning number' => ['1', Severity::WARNING],
        'info' => ['info', Severity::INFO],
        'off' => ['off', Severity::OFF],
        'off number' => ['0', Severity::OFF],
    ]);

    it('throws on invalid severity', function (): void {
        Severity::fromString('invalid');
    })->throws(InvalidArgumentException::class);

    it('checks if should report', function (): void {
        expect(Severity::ERROR->shouldReport())->toBeTrue()
            ->and(Severity::WARNING->shouldReport())->toBeTrue()
            ->and(Severity::INFO->shouldReport())->toBeTrue()
            ->and(Severity::OFF->shouldReport())->toBeFalse();
    });

    it('checks severity type', function (): void {
        expect(Severity::ERROR->isError())->toBeTrue()
            ->and(Severity::WARNING->isWarning())->toBeTrue()
            ->and(Severity::INFO->isInfo())->toBeTrue();
    });
});
