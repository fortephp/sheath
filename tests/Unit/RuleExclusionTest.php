<?php

declare(strict_types=1);

use Forte\Sheath\Caching\ResultCache;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Configuration\DefaultConfigFactory;
use Forte\Sheath\Configuration\PackagePresets;
use Forte\Sheath\Linter;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\Accessibility\Aria\NoAbstractRolesRule;
use Forte\Sheath\Rules\Accessibility\Aria\NoInvalidRoleRule;
use Forte\Sheath\Rules\Accessibility\Content\ImgAltTextRule;
use Forte\Sheath\Rules\Accessibility\Structure\ListSemanticsRule;
use Forte\Sheath\Rules\BestPractices\Markup\RequireLiContainerRule;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Rules\Security\NoTargetBlankRule;

afterEach(function (): void {
    PackagePresets::reset();
});

function ruleExclusionLinter(): Linter
{
    $registry = new RuleRegistry;
    $registry->register(ImgAltTextRule::class);
    $registry->register(NoTargetBlankRule::class);

    return new Linter($registry);
}

describe('per-rule exclude option', function (): void {
    it('skips the rule on a matching path and runs it elsewhere', function (): void {
        $linter = ruleExclusionLinter();
        $config = Config::make([
            'rules' => [
                'a11y-alt-text' => ['error', ['exclude' => ['views/native/']]],
            ],
        ]);

        $native = $linter->lint('<img src="x.jpg">', 'resources/views/native/home.blade.php', $config);
        $web = $linter->lint('<img src="x.jpg">', 'resources/views/web/home.blade.php', $config);

        expect($native->hasViolations())->toBeFalse()
            ->and($web->hasViolations())->toBeTrue()
            ->and($web->violations[0]->ruleId)->toBe('a11y-alt-text');
    });

    it('accepts glob patterns with the same dialect as the global ignore list', function (): void {
        $linter = ruleExclusionLinter();
        $config = Config::make([
            'rules' => [
                'a11y-alt-text' => ['error', ['exclude' => ['**/native/**']]],
            ],
        ]);

        $native = $linter->lint('<img src="x.jpg">', 'resources/views/native/deep/home.blade.php', $config);
        $web = $linter->lint('<img src="x.jpg">', 'resources/views/web/home.blade.php', $config);

        expect($native->hasViolations())->toBeFalse()
            ->and($web->hasViolations())->toBeTrue();
    });

    it('matches the path form the rule itself would see, absolute included', function (): void {
        $linter = ruleExclusionLinter();
        $config = Config::make([
            'rules' => [
                'a11y-alt-text' => ['error', ['exclude' => ['views/native/']]],
            ],
        ]);

        $result = $linter->lint('<img src="x.jpg">', 'C:\\app\\resources\\views\\native\\home.blade.php', $config);

        expect($result->hasViolations())->toBeFalse();
    });

    it('reads the long options form too', function (): void {
        $linter = ruleExclusionLinter();
        $config = Config::make([
            'rules' => [
                'a11y-alt-text' => [
                    'severity' => 'error',
                    'options' => ['exclude' => ['views/native/']],
                ],
            ],
        ]);

        $result = $linter->lint('<img src="x.jpg">', 'resources/views/native/home.blade.php', $config);

        expect($result->hasViolations())->toBeFalse();
    });

    it('treats an empty exclude list as a no-op', function (): void {
        $linter = ruleExclusionLinter();
        $config = Config::make([
            'rules' => [
                'a11y-alt-text' => ['error', ['exclude' => []]],
            ],
        ]);

        $result = $linter->lint('<img src="x.jpg">', 'resources/views/native/home.blade.php', $config);

        expect($result->hasViolations())->toBeTrue();
    });

    it('coerces a bare string into a single-pattern list', function (): void {
        $linter = ruleExclusionLinter();
        $config = Config::make([
            'rules' => [
                'a11y-alt-text' => ['error', ['exclude' => 'views/native/']],
            ],
        ]);

        $native = $linter->lint('<img src="x.jpg">', 'resources/views/native/home.blade.php', $config);
        $web = $linter->lint('<img src="x.jpg">', 'resources/views/web/home.blade.php', $config);

        expect($native->hasViolations())->toBeFalse()
            ->and($web->hasViolations())->toBeTrue();
    });

    it('neutralises non-string junk instead of failing the run', function (): void {
        $linter = ruleExclusionLinter();
        $config = Config::make([
            'rules' => [
                'a11y-alt-text' => ['error', ['exclude' => 42]],
            ],
        ]);

        $result = $linter->lint('<img src="x.jpg">', 'resources/views/native/home.blade.php', $config);

        expect($result->hasViolations())->toBeTrue();
    });

    it('withholds fixes along with findings on excluded paths', function (): void {
        $linter = ruleExclusionLinter();
        $config = Config::make([
            'rules' => [
                'security-no-target-blank' => ['error', ['exclude' => ['views/native/']]],
            ],
        ]);
        $blade = '<a href="https://x.test" target="_blank" rel="opener">go</a>';

        $native = $linter->lint($blade, 'resources/views/native/home.blade.php', $config);
        $web = $linter->lint($blade, 'resources/views/web/home.blade.php', $config);

        expect($native->getFixableCount())->toBe(0)
            ->and($web->getFixableCount())->toBeGreaterThan(0);
    });

    it('does not let an excluded rule suppress an active fallback rule', function (): void {
        $registry = new RuleRegistry;
        $registry->registerMany([
            ListSemanticsRule::class,
            RequireLiContainerRule::class,
            NoAbstractRolesRule::class,
            NoInvalidRoleRule::class,
        ]);
        $config = Config::make(['rules' => [
            'a11y-list-semantics' => ['error', ['exclude' => ['views/native/']]],
            'best-practices-require-li-container' => 'error',
            'a11y-no-abstract-roles' => ['error', ['exclude' => ['views/native/']]],
            'a11y-no-invalid-role' => 'error',
        ]]);

        $result = (new Linter($registry))->lint(
            '<div><li>orphan</li><span role="widget">x</span></div>',
            'resources/views/native/probe.blade.php',
            $config,
        );

        expect(array_column($result->violations, 'ruleId'))->toBe([
            'best-practices-require-li-container',
            'a11y-no-invalid-role',
        ]);
    });

    it('flows from a package preset entry through resolution to the linter', function (): void {
        PackagePresets::register('scoped', [
            'a11y-alt-text' => ['error', ['exclude' => ['views/native/']]],
        ]);

        $registry = new RuleRegistry;
        $registry->register(ImgAltTextRule::class);

        $config = DefaultConfigFactory::resolve(
            ['preset' => ['empty', 'scoped']],
            $registry
        );

        $linter = new Linter($registry);
        $native = $linter->lint('<img src="x.jpg">', 'resources/views/native/home.blade.php', $config);
        $web = $linter->lint('<img src="x.jpg">', 'resources/views/web/home.blade.php', $config);

        expect($native->hasViolations())->toBeFalse()
            ->and($web->hasViolations())->toBeTrue();
    });

    it('keeps entry-wins semantics: a project entry replaces the preset entry, exclude included', function (): void {
        PackagePresets::register('scoped', [
            'a11y-alt-text' => ['warning', ['exclude' => ['views/native/']]],
        ]);

        $registry = new RuleRegistry;
        $registry->register(ImgAltTextRule::class);

        $config = DefaultConfigFactory::resolve(
            [
                'preset' => ['empty', 'scoped'],
                'rules' => ['a11y-alt-text' => 'error'],
            ],
            $registry
        );

        $result = (new Linter($registry))->lint(
            '<img src="x.jpg">',
            'resources/views/native/home.blade.php',
            $config
        );

        expect($result->hasViolations())->toBeTrue()
            ->and($result->violations[0]->severity)->toBe(Severity::ERROR);
    });

    it('changes the cache context when the exclusion changes', function (): void {
        withTempDir(function (string $dir): void {
            $testFile = $dir.DIRECTORY_SEPARATOR.'exclusion-cache-probe.blade.php';
            file_put_contents($testFile, '<img src="x.jpg">');

            $cache = new ResultCache($dir.DIRECTORY_SEPARATOR.'.exclusion-test-cache');
            $cache->enable();

            $withExclude = Config::make([
                'rules' => ['a11y-alt-text' => ['error', ['exclude' => ['views/native/']]]],
            ]);
            $withoutExclude = Config::make([
                'rules' => ['a11y-alt-text' => 'error'],
            ]);

            $cache->setContext(['config' => $withExclude->toArray()]);
            $cache->put($testFile, new LintResult($testFile, []));

            expect($cache->has($testFile))->toBeTrue();

            $cache->setContext(['config' => $withoutExclude->toArray()]);

            expect($cache->has($testFile))->toBeFalse();
        }, prefix: 'sheath-exclusion-cache-');
    });
});
