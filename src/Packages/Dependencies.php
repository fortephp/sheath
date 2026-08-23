<?php

declare(strict_types=1);

namespace Forte\Sheath\Packages;

use Composer\InstalledVersions;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;

/** @internal */
class Dependencies
{
    /**
     * @param  array<string, PackageInfo>  $packages
     */
    private function __construct(private array $packages) {}

    /**
     * Build the dependency set from Composer's authoritative runtime data.
     */
    public static function fromInstalledVersions(): self
    {
        return self::fromInstalledData(InstalledVersions::getAllRawData());
    }

    /**
     * @param  array<int, array<string, mixed>>  $installedData  Composer InstalledVersions raw data sets
     */
    public static function fromInstalledData(array $installedData): self
    {
        $packages = [];

        foreach ($installedData as $installed) {
            $versions = $installed['versions'] ?? null;
            if (! is_array($versions)) {
                continue;
            }

            foreach ($versions as $name => $package) {
                if (! is_string($name) || ! is_array($package)) {
                    continue;
                }

                $normalizedName = strtolower($name);
                if (isset($packages[$normalizedName])) {
                    continue;
                }

                $prettyVersion = $package['pretty_version'] ?? null;
                $normalizedVersion = $package['version'] ?? null;
                $version = is_string($prettyVersion)
                    ? $prettyVersion
                    : (is_string($normalizedVersion) ? $normalizedVersion : null);

                if ($version !== null && str_starts_with($version, 'v')) {
                    $version = substr($version, 1);
                }

                $aliases = self::stringList($package['aliases'] ?? []);
                $ranges = [];

                if (is_string($prettyVersion)) {
                    $ranges[] = $prettyVersion;
                } elseif (is_string($normalizedVersion)) {
                    $ranges[] = $normalizedVersion;
                }

                $ranges = array_merge(
                    $ranges,
                    $aliases,
                    self::stringList($package['replaced'] ?? []),
                    self::stringList($package['provided'] ?? []),
                );

                $packages[$normalizedName] = new PackageInfo(
                    version: $version,
                    isExactVersion: true,
                    aliasVersion: $aliases[0] ?? null,
                    versionRanges: $ranges !== [] ? implode(' || ', $ranges) : null,
                );
            }
        }

        return new self($packages);
    }

    /**
     * Build a dependency set from Composer manifest data for isolated tests.
     * Runtime code must use fromInstalledVersions() instead.
     *
     * @param  array<string, mixed>  $composerJson  Parsed composer.json content
     * @param  array<string, mixed>|null  $composerLock  Parsed composer.lock content (optional)
     */
    public static function fromData(array $composerJson, ?array $composerLock = null): self
    {
        $packages = [];

        if ($composerLock !== null) {
            /** @var array<int, array<string, mixed>> $lockPackages */
            $lockPackages = $composerLock['packages'] ?? [];
            /** @var array<int, array<string, mixed>> $lockPackagesDev */
            $lockPackagesDev = $composerLock['packages-dev'] ?? [];

            $aliases = self::lockAliases($composerLock);

            self::indexLockPackages($packages, $lockPackages, $aliases);
            self::indexLockPackages($packages, $lockPackagesDev, $aliases);
        }

        /** @var array<string, string> $require */
        $require = $composerJson['require'] ?? [];
        /** @var array<string, string> $requireDev */
        $requireDev = $composerJson['require-dev'] ?? [];

        self::indexJsonPackages($packages, $require);
        self::indexJsonPackages($packages, $requireDev);

        return new self($packages);
    }

    public function has(string $package): bool
    {
        return isset($this->packages[strtolower($package)]);
    }

    public function version(string $package): ?string
    {
        return $this->packages[strtolower($package)]->version ?? null;
    }

    public function compare(string $package, string $operator, string $version): bool
    {
        if ($operator === '=') {
            $operator = '==';
        }

        if (! in_array($operator, ['>', '>=', '<', '<=', '==', '!='], true)) {
            return false;
        }

        return $this->satisfies($package, $operator.$version);
    }

    public function satisfies(string $package, string $constraint): bool
    {
        $info = $this->packages[strtolower($package)] ?? null;

        if ($info === null || ! $info->isExactVersion) {
            return false;
        }

        if ($info->versionRanges !== null) {
            $parser = new VersionParser;
            $required = $parser->parseConstraints($constraint);
            $installed = $parser->parseConstraints($info->versionRanges);

            return $installed->matches($required);
        }

        if ($info->version === null) {
            return false;
        }

        if (Semver::satisfies($info->version, $constraint)) {
            return true;
        }

        return $info->aliasVersion !== null
            && Semver::satisfies($info->aliasVersion, $constraint);
    }

    /**
     * @param  array<string, PackageInfo>  $packages
     * @param  array<int, array<string, mixed>>  $lockPackages
     * @param  array<string, string>  $aliases
     */
    private static function indexLockPackages(
        array &$packages,
        array $lockPackages,
        array $aliases = [],
    ): void {
        foreach ($lockPackages as $package) {
            $name = $package['name'] ?? null;
            $version = $package['version'] ?? null;

            if (! is_string($name) || ! is_string($version)) {
                continue;
            }

            $name = strtolower($name);

            if (str_starts_with($version, 'v')) {
                $version = substr($version, 1);
            }

            $packages[$name] = new PackageInfo(
                version: $version,
                isExactVersion: true,
                aliasVersion: $aliases[$name] ?? null,
            );
        }
    }

    /**
     * @param  array<string, PackageInfo>  $packages
     * @param  array<string, string>  $jsonPackages
     */
    private static function indexJsonPackages(array &$packages, array $jsonPackages): void
    {
        foreach ($jsonPackages as $name => $constraint) {
            $normalizedName = strtolower($name);

            if (isset($packages[$normalizedName])) {
                $existing = $packages[$normalizedName];
                $aliasVersion = self::constraintAlias($constraint) ?? $existing->aliasVersion;
                $packages[$normalizedName] = new PackageInfo(
                    version: $existing->version,
                    isExactVersion: $existing->isExactVersion,
                    aliasVersion: $aliasVersion,
                );
            } else {
                $packages[$normalizedName] = new PackageInfo(
                    version: $constraint,
                    isExactVersion: false,
                    aliasVersion: self::constraintAlias($constraint),
                );
            }
        }
    }

    private static function constraintAlias(string $constraint): ?string
    {
        if (preg_match('/\s+as\s+([^\s]+)\s*$/i', $constraint, $matches) !== 1) {
            return null;
        }

        return ltrim($matches[1], 'v');
    }

    /**
     * @param  array<string, mixed>  $composerLock
     * @return array<string, string>
     */
    private static function lockAliases(array $composerLock): array
    {
        $result = [];
        $aliases = $composerLock['aliases'] ?? [];

        if (! is_array($aliases)) {
            return $result;
        }

        foreach ($aliases as $alias) {
            if (! is_array($alias)) {
                continue;
            }

            $package = $alias['package'] ?? null;
            $version = $alias['alias'] ?? null;

            if (is_string($package) && is_string($version)) {
                $result[strtolower($package)] = ltrim($version, 'v');
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
