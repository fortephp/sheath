<?php

declare(strict_types=1);

namespace Forte\Sheath\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
readonly class RequiresPackage
{
    /**
     * @param  non-empty-string  $package  Package name (e.g., 'vendor/package')
     * @param  string|null  $constraint  Composer version constraint (e.g., '^3.0', '>=2.0 <4.0')
     */
    public function __construct(
        public string $package,
        public ?string $constraint = null,
    ) {}
}
