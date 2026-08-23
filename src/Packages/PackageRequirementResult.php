<?php

declare(strict_types=1);

namespace Forte\Sheath\Packages;

class PackageRequirementResult
{
    /**
     * @param  array<string>  $unmetRequirements
     */
    private function __construct(
        public bool $satisfied,
        public bool $unknown,
        public array $unmetRequirements,
        public ?string $message,
    ) {}

    public static function satisfied(): self
    {
        return new self(
            satisfied: true,
            unknown: false,
            unmetRequirements: [],
            message: null,
        );
    }

    /**
     * @param  array<string>  $unmet
     */
    public static function unsatisfied(array $unmet): self
    {
        return new self(
            satisfied: false,
            unknown: false,
            unmetRequirements: $unmet,
            message: null,
        );
    }

    public static function unknown(string $message): self
    {
        return new self(
            satisfied: false,
            unknown: true,
            unmetRequirements: [],
            message: $message,
        );
    }

    public function getDescription(): string
    {
        if ($this->satisfied) {
            return 'All package requirements satisfied';
        }

        if ($this->unknown) {
            return $this->message ?? 'Unable to verify requirements';
        }

        return 'Unmet requirements: '.implode(', ', $this->unmetRequirements);
    }
}
