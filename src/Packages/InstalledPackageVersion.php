<?php

declare(strict_types=1);

namespace Forte\Sheath\Packages;

use Composer\InstalledVersions;
use OutOfBoundsException;

/** @internal */
final class InstalledPackageVersion
{
    /**
     * Describe an installed package as "version@reference" using Composer's
     * runtime data, or "unknown" when Composer cannot answer.
     */
    public static function describe(string $package): string
    {
        if (! class_exists(InstalledVersions::class)) {
            return 'unknown';
        }

        try {
            if (! InstalledVersions::isInstalled($package)) {
                return 'unknown';
            }

            $version = InstalledVersions::getPrettyVersion($package)
                ?? InstalledVersions::getVersion($package);
            $reference = InstalledVersions::getReference($package);
        } catch (OutOfBoundsException) {
            return 'unknown';
        }

        if (! is_string($version) || $version === '') {
            return 'unknown';
        }

        return is_string($reference) && $reference !== ''
            ? $version.'@'.$reference
            : $version;
    }
}
