<?php

declare(strict_types=1);

use Forte\Sheath\Packages\PackageRequirementMode;

describe('PackageRequirementMode Enum', function (): void {
    describe('fromString', function (): void {
        it('parses every mode case-insensitively', function (string $value, PackageRequirementMode $expected): void {
            expect(PackageRequirementMode::fromString($value))->toBe($expected);
        })->with([
            'skip' => ['skip', PackageRequirementMode::SKIP],
            'mixed-case skip' => ['Skip', PackageRequirementMode::SKIP],
            'disable' => ['DISABLE', PackageRequirementMode::DISABLE],
            'ignore' => ['IGNORE', PackageRequirementMode::IGNORE],
        ]);

        it('throws for unknown values', function (string $value): void {
            expect(fn (): PackageRequirementMode => PackageRequirementMode::fromString($value))
                ->toThrow(InvalidArgumentException::class);
        })->with(['unknown', '', 'invalid', 'disbale']);
    });
});
