<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules;

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Contracts\Rule;

/** @internal */
final class RuleExecutionPlan
{
    private ?Config $config = null;

    private int $configRevision = -1;

    private int $registryRevision = -1;

    /** @var list<array{prototype: Rule, exclusions: list<string>}> */
    private array $entries = [];

    public function matches(Config $config, int $configRevision, int $registryRevision): bool
    {
        return $this->config === $config
            && $this->configRevision === $configRevision
            && $this->registryRevision === $registryRevision;
    }

    /**
     * @param  list<array{prototype: Rule, exclusions: list<string>}>  $entries
     */
    public function replace(Config $config, int $configRevision, int $registryRevision, array $entries): void
    {
        $this->config = $config;
        $this->configRevision = $configRevision;
        $this->registryRevision = $registryRevision;
        $this->entries = $entries;
    }

    /** @return list<array{prototype: Rule, exclusions: list<string>}> */
    public function entries(): array
    {
        return $this->entries;
    }
}
