<?php

declare(strict_types=1);

namespace Forte\Sheath\Contracts;

use Forte\Ast\Document\Document;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

interface Rule
{
    public function getId(): string;

    public function getDescription(): string;

    public function getCategory(): RuleCategory|string;

    public function getDefaultSeverity(): Severity;

    public function getSeverity(): Severity;

    public function setSeverity(Severity $severity): void;

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array;

    /**
     * @param  array<string, mixed>  $options
     */
    public function setOptions(array $options): void;

    public function isEnabled(): bool;

    public function check(Document $document, RuleContext $context): void;
}
