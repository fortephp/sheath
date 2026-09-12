<?php

declare(strict_types=1);

namespace Forte\Sheath\Tests;

use Forte\Sheath\Reporters\ReporterRegistry;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\ServiceProvider;
use Forte\Sheath\SheathManager;
use Forte\Sheath\Testing\RuleTester;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected SheathManager $manager;

    protected RuleRegistry $ruleRegistry;

    protected ReporterRegistry $reporterRegistry;

    protected function resolveApplication(): Application
    {
        // Laravel's default absolute cache prefixes do not include Windows drive letters.
        return parent::resolveApplication()->addAbsoluteCachePathPrefix(sys_get_temp_dir());
    }

    protected function getPackageProviders($app): array
    {
        return [
            ServiceProvider::class,
        ];
    }

    protected function getRuleTester(): RuleTester
    {
        return new RuleTester;
    }

    protected function setUpSheathManager(): void
    {
        $this->ruleRegistry = new RuleRegistry;
        $this->reporterRegistry = new ReporterRegistry;
        $this->manager = new SheathManager($this->ruleRegistry, $this->reporterRegistry);
    }
}
