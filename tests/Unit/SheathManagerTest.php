<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Configuration\DefaultConfigFactory;
use Forte\Sheath\Configuration\PackagePresets;
use Forte\Sheath\Contracts\IgnoredRegionProvider;
use Forte\Sheath\Parsing\IgnoredRegion;
use Forte\Sheath\Reporters\ReporterRegistry;
use Forte\Sheath\Reporters\StylishReporter;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\FixResult;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Accessibility\Content\ImgAltTextRule;
use Forte\Sheath\Rules\BestPractices\Markup\SelfClosingVoidElementsRule;
use Forte\Sheath\Rules\Performance\LazyLoadImagesRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Rules\Security\CsrfFieldRule;
use Forte\Sheath\Rules\Security\NoRawEchoRule;
use Forte\Sheath\SheathManager;
use Illuminate\Config\Repository;

final class ManagerIgnoredRegionProvider implements IgnoredRegionProvider
{
    public function id(): string
    {
        return 'manager-test';
    }

    public function regions(string $source, string $filePath): iterable
    {
        return str_starts_with($source, 'ignore:')
            ? [new IgnoredRegion(0, strlen($source))]
            : [];
    }

    public function cacheContext(): string
    {
        return 'v1';
    }
}

abstract class FixProbeRewriteRule extends AbstractRule
{
    abstract protected function fromTag(): string;

    abstract protected function toTag(): string;

    protected function dangerous(): bool
    {
        return false;
    }

    public function getDescription(): string
    {
        return 'Probe rule for SheathManager::fix tests.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BEST_PRACTICES;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $document->findElementsByName($this->fromTag())->each(function (ElementNode $element) use ($context): void {
            $replacement = sprintf('<%1$s>x</%1$s>', $this->toTag());

            $context->report(
                $element,
                "Replace <{$this->fromTag()}> with <{$this->toTag()}>.",
                Fix::fromNode($element, $replacement, $this->dangerous())
            );
        });
    }
}

class FixProbeBoldToItalicRule extends FixProbeRewriteRule
{
    public function getId(): string
    {
        return 'probe-fix-b-to-i';
    }

    protected function fromTag(): string
    {
        return 'b';
    }

    protected function toTag(): string
    {
        return 'i';
    }
}

class FixProbeItalicToEmRule extends FixProbeRewriteRule
{
    public function getId(): string
    {
        return 'probe-fix-i-to-em';
    }

    protected function fromTag(): string
    {
        return 'i';
    }

    protected function toTag(): string
    {
        return 'em';
    }
}

class FixProbeItalicToBoldRule extends FixProbeRewriteRule
{
    public function getId(): string
    {
        return 'probe-fix-i-to-b';
    }

    protected function fromTag(): string
    {
        return 'i';
    }

    protected function toTag(): string
    {
        return 'b';
    }
}

class FixProbeDangerousUnderlineRule extends FixProbeRewriteRule
{
    public function getId(): string
    {
        return 'probe-fix-dangerous-u';
    }

    protected function fromTag(): string
    {
        return 'u';
    }

    protected function toTag(): string
    {
        return 'em';
    }

    protected function dangerous(): bool
    {
        return true;
    }
}

describe('SheathManager', function (): void {
    beforeEach(function (): void {
        $this->setUpSheathManager();
    });

    describe('registerRule', function (): void {
        it('registers a single rule', function (): void {
            $this->manager->registerRule(ImgAltTextRule::class);

            expect($this->manager->hasRule('a11y-alt-text'))->toBeTrue();
        });

    });

    describe('registerRules', function (): void {
        it('registers multiple rules at once', function (): void {
            $this->manager->registerRules([
                ImgAltTextRule::class,
                NoRawEchoRule::class,
            ]);

            expect($this->manager->hasRule('a11y-alt-text'))->toBeTrue()
                ->and($this->manager->hasRule('security-no-raw-echo'))->toBeTrue();
        });

    });

    describe('hasRule', function (): void {
        it('returns false for unregistered rules', function (): void {
            expect($this->manager->hasRule('non-existent-rule'))->toBeFalse();
        });

    });

    describe('discoverRules', function (): void {
        it('discovers rules from a directory', function (): void {
            $rulesPath = dirname(__DIR__, 2).'/src/Rules/Accessibility';

            $this->manager->discoverRules($rulesPath, 'Forte\\Sheath\\Rules\\Accessibility');

            expect($this->manager->hasRule('a11y-alt-text'))->toBeTrue()
                ->and($this->manager->hasRule('a11y-form-label'))->toBeTrue();
        });

        it('handles non-existent directory gracefully', function (): void {
            $this->manager->discoverRules('/non/existent/path', 'App\\Rules');

            expect($this->manager->getRules())->toBeEmpty();
        });
    });

    describe('default config', function (): void {
        it('applies the recommended preset when no config is present', function (): void {
            $this->manager->registerRule(CsrfFieldRule::class);

            $config = $this->manager->getDefaultConfig();

            expect($config->getPreset())->toBe(['recommended'])
                ->and($config->getRules())->toHaveKey('security-csrf-field');
        });

        it('uses the preset severity rather than a blanket warning', function (): void {
            $this->manager->registerRule(CsrfFieldRule::class);

            $result = $this->manager->lint(
                '<form method="POST"></form>',
                'test.blade.php'
            );

            expect($result->hasViolations())->toBeTrue()
                ->and($result->violations[0]->severity)->toBe(Severity::ERROR);
        });

        it('only enables rules the preset lists', function (): void {
            $this->manager->registerRule(CsrfFieldRule::class);

            expect($this->manager->getDefaultConfig()->getRules())
                ->not->toHaveKey('blade-prefer-unless');
        });

        it('uses the shared fallback ignore defaults', function (): void {
            expect($this->manager->getDefaultConfig()->getIgnore())
                ->toContain('resources/views/emails/**');
        });

        it('prefers injected Laravel config when available', function (): void {
            $registry = new RuleRegistry;
            $registry->register(CsrfFieldRule::class);

            $manager = new SheathManager(
                $registry,
                new ReporterRegistry,
                null,
                new Repository([
                    'sheath' => [
                        'paths' => ['resources/views'],
                        'ignore' => ['vendor/**'],
                        'rules' => ['security-csrf-field' => 'error'],
                    ],
                ])
            );

            $result = $manager->lint(
                '<form method="POST"></form>',
                'test.blade.php'
            );

            expect($result->hasViolations())->toBeTrue()
                ->and($result->violations[0]->severity)->toBe(Severity::ERROR)
                ->and($manager->getDefaultConfig()->getIgnore())->toBe(['vendor/**']);
        });
    });

    describe('lintFile', function (): void {
        it('throws when the file cannot be read', function (): void {
            expect(fn () => $this->manager->lintFile('missing-file-'.uniqid().'.blade.php'))
                ->toThrow(RuntimeException::class);
        });
    });

    describe('registerPreset', function (): void {
        afterEach(function (): void {
            PackagePresets::reset();
        });

        it('registers into the shared store by default, visible to resolution', function (): void {
            $this->manager->registerRule(CsrfFieldRule::class);
            $this->manager->registerPreset('acme', ['security-csrf-field' => 'error']);

            $config = DefaultConfigFactory::resolve(
                ['preset' => ['empty', 'acme']],
                $this->ruleRegistry
            );

            expect($config->getRules())->toHaveKey('security-csrf-field');
        });

        it('registers into an injected store, keeping the shared one clean', function (): void {
            $store = new PackagePresets;
            $manager = new SheathManager(
                $this->ruleRegistry,
                $this->reporterRegistry,
                packagePresets: $store
            );

            $manager->registerPreset('acme', ['security-csrf-field' => 'error']);

            expect($store->declared('acme'))->toBe(['security-csrf-field' => 'error'])
                ->and(PackagePresets::names())->not->toContain('acme')
                ->and($manager->getPackagePresets())->toBe($store);
        });
    });

    describe('registerIgnoredRegionProvider', function (): void {
        it('registers providers fluently and uses them for manager linting', function (): void {
            $this->manager->registerRule(ImgAltTextRule::class);
            $result = $this->manager
                ->registerIgnoredRegionProvider(ManagerIgnoredRegionProvider::class)
                ->lint(
                    'ignore:<img src="inside">',
                    'view.blade.php',
                    Config::make(['rules' => ['a11y-alt-text' => 'error']]),
                );

            expect($result->violations)->toBeEmpty()
                ->and($this->manager->getIgnoredRegionRegistry()->all())
                ->toHaveKey('manager-test');
        });
    });

    describe('registerReporter', function (): void {
        it('registers a reporter class under a name', function (): void {
            $this->manager->registerReporter('custom', StylishReporter::class);

            expect($this->manager->getReporters())->toContain('custom')
                ->and($this->manager->getReporter('custom'))->toBeInstanceOf(StylishReporter::class);
        });

        it('registers a reporter instance and returns self for chaining', function (): void {
            $instance = new StylishReporter;

            $result = $this->manager->registerReporter('custom', $instance);

            expect($result)->toBe($this->manager)
                ->and($this->manager->getReporter('custom'))->toBe($instance);
        });
    });

    describe('fix', function (): void {
        $fixConfig = fn (): Config => Config::make()->setRules([
            'probe-fix-b-to-i' => 'warning',
            'probe-fix-i-to-em' => 'warning',
            'probe-fix-dangerous-u' => 'warning',
        ]);

        beforeEach(function (): void {
            $this->manager->registerRules([
                FixProbeBoldToItalicRule::class,
                FixProbeItalicToEmRule::class,
                FixProbeItalicToBoldRule::class,
                FixProbeDangerousUnderlineRule::class,
            ]);
        });

        it('returns a FixResult carrying the fixed content', function () use ($fixConfig): void {
            $result = $this->manager->fix('<i>x</i>', 'test.blade.php', $fixConfig());

            expect($result)->toBeInstanceOf(FixResult::class)
                ->and($result->content)->toBe('<em>x</em>')
                ->and($result->appliedCount)->toBe(1)
                ->and($result->skippedCount)->toBe(0)
                ->and($result->hasOverlaps)->toBeFalse();
        });

        it('sums applied fixes across passes, one pass revealing the next', function () use ($fixConfig): void {
            $result = $this->manager->fix('<b>x</b>', 'test.blade.php', $fixConfig());

            expect($result->content)->toBe('<em>x</em>')
                ->and($result->appliedCount)->toBe(2)
                ->and($result->skippedCount)->toBe(0);
        });

        it('reports withheld dangerous fixes as skipped, leaving content untouched', function () use ($fixConfig): void {
            $result = $this->manager->fix('<u>x</u>', 'test.blade.php', $fixConfig());

            expect($result->content)->toBe('<u>x</u>')
                ->and($result->appliedCount)->toBe(0)
                ->and($result->skippedCount)->toBe(1);
        });

        it('applies dangerous fixes when asked, reporting nothing skipped', function () use ($fixConfig): void {
            $result = $this->manager->fix('<u>x</u>', 'test.blade.php', $fixConfig(), includeDangerous: true);

            expect($result->content)->toBe('<em>x</em>')
                ->and($result->appliedCount)->toBe(1)
                ->and($result->skippedCount)->toBe(0);
        });

        it('reports a clean end state when nothing was fixable', function () use ($fixConfig): void {
            $result = $this->manager->fix('<em>x</em>', 'test.blade.php', $fixConfig());

            expect($result->content)->toBe('<em>x</em>')
                ->and($result->appliedCount)->toBe(0)
                ->and($result->skippedCount)->toBe(0)
                ->and($result->hasOverlaps)->toBeFalse()
                ->and($result->overlappingFixes)->toBe([]);
        });

        it('stops when fixes enter a content cycle instead of exhausting the pass limit', function (): void {
            $config = Config::make()->setRules([
                'probe-fix-b-to-i' => 'warning',
                'probe-fix-i-to-b' => 'warning',
            ]);

            $result = $this->manager->fix('<b>x</b>', 'test.blade.php', $config, maxPasses: 10);

            expect($result->content)->toBe('<b>x</b>')
                ->and($result->appliedCount)->toBe(2)
                ->and($result->skippedCount)->toBe(1)
                ->and($result->allApplied())->toBeFalse();
        });

        it('composes attribute and trailing-syntax fixes in valid order regardless of rule order', function (): void {
            $this->manager->registerRules([
                LazyLoadImagesRule::class,
                SelfClosingVoidElementsRule::class,
            ]);

            $entries = [
                'perf-lazy-load-images' => ['warning', ['skipAboveFold' => false]],
                'best-practices-self-closing-void-elements' => ['warning', ['style' => 'always']],
            ];

            foreach ([$entries, array_reverse($entries, true)] as $rules) {
                $result = $this->manager->fix(
                    '<img src="x">',
                    'test.blade.php',
                    Config::make()->setRules($rules),
                    includeDangerous: true,
                );

                expect($result->content)->toBe('<img src="x" loading="lazy" />')
                    ->and(Document::parse($result->content)->hasErrors())->toBeFalse()
                    ->and($result->appliedCount)->toBe(2)
                    ->and($result->skippedCount)->toBe(0);
            }
        });
    });
});
