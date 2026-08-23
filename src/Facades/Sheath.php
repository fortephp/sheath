<?php

declare(strict_types=1);

namespace Forte\Sheath\Facades;

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Contracts\IgnoredRegionProvider;
use Forte\Sheath\Contracts\Reporter;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Parsing\IgnoredRegionRegistry;
use Forte\Sheath\Reporters\ReporterRegistry;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\SheathManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static LintResult lint(string $content, string $filePath = 'stdin.blade.php', ?Config $config = null)
 * @method static LintResult lintFile(string $filePath, ?Config $config = null)
 * @method static \Forte\Sheath\Results\FixResult fix(string $content, string $filePath = 'stdin.blade.php', ?Config $config = null, bool $includeDangerous = false, int $maxPasses = SheathManager::MAX_FIX_PASSES)
 * @method static SheathManager registerPreset(string $name, array<string, string|array{0: string, 1: array<string, mixed>}> $rules)
 * @method static \Forte\Sheath\Configuration\PackagePresets getPackagePresets()
 * @method static IgnoredRegionRegistry getIgnoredRegionRegistry()
 * @method static RuleRegistry getRuleRegistry()
 * @method static ReporterRegistry getReporterRegistry()
 * @method static Rule getRule(string $ruleId)
 * @method static array<string> getRules()
 * @method static Reporter getReporter(string $name)
 * @method static array<string> getReporters()
 * @method static Config getDefaultConfig()
 * @method static SheathManager registerRule(string $ruleClass)
 * @method static SheathManager registerReporter(string $name, class-string<Reporter>|Reporter $reporter)
 * @method static SheathManager registerRules(array<class-string<Rule>> $ruleClasses)
 * @method static SheathManager registerIgnoredRegionProvider(class-string<IgnoredRegionProvider>|IgnoredRegionProvider $provider)
 * @method static SheathManager discoverRules(string $path, string $namespace)
 * @method static bool hasRule(string $ruleId)
 */
class Sheath extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'sheath';
    }
}
