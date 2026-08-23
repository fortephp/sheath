<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Extensions\AbstractTreeExtension;
use Forte\Parser\Extension\TreeContext;
use Forte\Parser\NodeKindRegistry;
use Forte\Parser\ParserOptions;
use Forte\Sheath\Attributes\RequiresPackage;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Contracts\ProvidesRuleDocument;
use Forte\Sheath\Exceptions\RuleNotFoundException;
use Forte\Sheath\Linter;
use Forte\Sheath\Packages\Dependencies;
use Forte\Sheath\Packages\PackageRequirementEvaluator;
use Forte\Sheath\Packages\PackageRequirementMode;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Accessibility\Content\ImgAltTextRule;
use Forte\Sheath\Rules\BestPractices\Attributes\NoInlineStylesRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Rules\Security\NoRawEchoRule;

final class ThrowingParserExtension extends AbstractTreeExtension
{
    public function id(): string
    {
        return 'throwing-parser-extension';
    }

    protected function registerKinds(NodeKindRegistry $registry): void {}

    public function canHandle(TreeContext $ctx): bool
    {
        throw new RuntimeException('Unexpected parser extension failure.');
    }

    protected function doHandle(TreeContext $ctx): int
    {
        return 0;
    }
}

class TestConfigLeakRule extends AbstractRule
{
    public function getId(): string
    {
        return 'test-config-leak';
    }

    public function getDescription(): string
    {
        return 'Rule used to validate mutable state reset between lint runs.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $message = (string) $this->getOption('token', 'default');
        $context->reportAt(
            start: new Position(0, 1, 1),
            end: new Position(0, 1, 1),
            message: $message
        );
    }
}

#[RequiresPackage('livewire/livewire')]
class TestPackageAwareRule extends AbstractRule
{
    public function getId(): string
    {
        return 'test-package-aware';
    }

    public function getDescription(): string
    {
        return 'Rule used to validate package requirement handling during linting.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $context->reportAt(
            start: new Position(0, 1, 1),
            end: new Position(0, 1, 1),
            message: 'package rule triggered'
        );
    }
}

final class TestExecutionPlanRule extends AbstractRule
{
    public static int $optionApplications = 0;

    private int $checks = 0;

    public function getId(): string
    {
        return 'test-execution-plan';
    }

    public function getDescription(): string
    {
        return 'Rule used to validate prepared rule execution.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function setOptions(array $options): void
    {
        self::$optionApplications++;
        parent::setOptions($options);
    }

    public function check(Document $document, RuleContext $context): void
    {
        $this->checks++;
        $context->reportAt(
            new Position(0, 1, 1),
            new Position(0, 1, 1),
            $this->getOption('token', 'default').':'.$this->checks,
        );
    }
}

final readonly class TestSharedAnalysis
{
    public function __construct(public int $elementCount) {}
}

abstract class TestSharedAnalysisRule extends AbstractRule
{
    public static int $builds = 0;

    public function getDescription(): string
    {
        return 'Rule used to validate file-scoped shared analysis.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $context->analysis(TestSharedAnalysis::class, static function () use ($document): TestSharedAnalysis {
            self::$builds++;

            return new TestSharedAnalysis($document->queryElements()->count());
        });
    }
}

final class TestFirstSharedAnalysisRule extends TestSharedAnalysisRule
{
    public function getId(): string
    {
        return 'test-first-shared-analysis';
    }
}

final class TestSecondSharedAnalysisRule extends TestSharedAnalysisRule
{
    public function getId(): string
    {
        return 'test-second-shared-analysis';
    }
}

abstract class TestRuleDocumentRule extends AbstractRule implements ProvidesRuleDocument
{
    public static int $builds = 0;

    public function getDescription(): string
    {
        return 'Rule used to validate shared, length-preserving parser views.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BEST_PRACTICES;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function ruleDocumentKey(): string
    {
        return 'test-rule-document';
    }

    public function ruleDocument(Document $document, ParserOptions $parserOptions): Document
    {
        self::$builds++;

        return Document::parse(str_replace('probe', 'ready', $document->source()), $parserOptions)
            ->setFilePath($document->getFilePath());
    }

    public function check(Document $document, RuleContext $context): void
    {
        if (str_contains($document->source(), 'ready')) {
            $context->reportAt(new Position(0, 1, 1), new Position(5, 1, 6), 'normalized');
        }
    }
}

final class TestFirstRuleDocumentRule extends TestRuleDocumentRule
{
    public function getId(): string
    {
        return 'test-first-rule-document';
    }
}

final class TestSecondRuleDocumentRule extends TestRuleDocumentRule
{
    public function getId(): string
    {
        return 'test-second-rule-document';
    }
}

abstract class TestInputScopedRuleDocumentRule extends AbstractRule implements ProvidesRuleDocument
{
    public static int $builds = 0;

    public function getDescription(): string
    {
        return 'Rule used to validate parser views are scoped to their input document.';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function ruleDocumentKey(): string
    {
        return 'test-input-scoped-rule-document';
    }

    public function ruleDocument(Document $document, ParserOptions $parserOptions): Document
    {
        self::$builds++;

        return $document;
    }
}

final class TestAuthoredRuleDocumentRule extends TestInputScopedRuleDocumentRule
{
    public function getId(): string
    {
        return 'test-authored-rule-document';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function check(Document $document, RuleContext $context): void
    {
        if (str_contains($document->source(), '<x-probe')) {
            $context->reportAt(new Position(0, 1, 1), new Position(1, 1, 2), 'authored');
        }
    }
}

final class TestSemanticRuleDocumentRule extends TestInputScopedRuleDocumentRule
{
    public function getId(): string
    {
        return 'test-semantic-rule-document';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::ACCESSIBILITY;
    }

    public function check(Document $document, RuleContext $context): void
    {
        if (str_contains($document->source(), '<img')) {
            $context->reportAt(new Position(0, 1, 1), new Position(1, 1, 2), 'semantic');
        }
    }
}

final class TestMalformedRuleDocumentRule extends AbstractRule implements ProvidesRuleDocument
{
    public static int $checks = 0;

    public static string $inputSource = '';

    public function getId(): string
    {
        return 'test-malformed-rule-document';
    }

    public function getDescription(): string
    {
        return 'Rule used to ensure malformed parser views fail closed.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::ACCESSIBILITY;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function ruleDocumentKey(): string
    {
        return 'test-malformed-rule-document';
    }

    public function ruleDocument(Document $document, ParserOptions $parserOptions): Document
    {
        self::$inputSource = $document->source();
        $source = '{{'.str_repeat(' ', max(0, strlen($document->source()) - 2));

        return Document::parse($source, $parserOptions)->setFilePath($document->getFilePath());
    }

    public function check(Document $document, RuleContext $context): void
    {
        self::$checks++;
    }
}

final class TestNestedCloneStateRule extends AbstractRule
{
    public object $state;

    public function __construct()
    {
        $this->state = (object) ['checks' => 0];
        parent::__construct();
    }

    public function __clone()
    {
        $this->state = clone $this->state;
    }

    public function getId(): string
    {
        return 'test-nested-clone-state';
    }

    public function getDescription(): string
    {
        return 'Rule used to validate nested clone state isolation.';
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
        $this->state->checks++;
        $context->reportAt(
            new Position(0, 1, 1),
            new Position(0, 1, 1),
            'checks='.$this->state->checks,
        );
    }
}

describe('Linter', function (): void {
    beforeEach(function (): void {
        $this->registry = new RuleRegistry;
        $this->registry->register(ImgAltTextRule::class);
        $this->registry->register(NoInlineStylesRule::class);

        $this->linter = new Linter($this->registry);
    });

    it('can lint a simple template', function (): void {
        $result = $this->linter->lint(
            '<img src="test.jpg">',
            'test.blade.php',
            Config::make(['rules' => ['a11y-alt-text' => 'error']])
        );

        expect($result->hasViolations())->toBeTrue()
            ->and($result->violations)->toHaveCount(1)
            ->and($result->violations[0]->ruleId)->toBe('a11y-alt-text');
    });

    it('continues linting invalid UTF-8 source without a noisy encoding error', function (): void {
        $result = $this->linter->lint(
            "\xFF<img src=\"test.jpg\">",
            'legacy.blade.php',
            Config::make(['rules' => ['a11y-alt-text' => 'error']])
        );

        expect($result->hasParseErrors)->toBeFalse()
            ->and($result->violations)->toHaveCount(1)
            ->and($result->violations[0]->ruleId)->toBe('a11y-alt-text')
            ->and($result->violations[0]->start->offset)->toBe(1)
            ->and($result->violations[0]->start->character)->toBe(2);
    });

    it('reports no violations for valid template', function (): void {
        $result = $this->linter->lint(
            '<img src="test.jpg" alt="Test">',
            'test.blade.php',
            Config::make(['rules' => ['a11y-alt-text' => 'error']])
        );

        expect($result->hasViolations())->toBeFalse();
    });

    it('can run multiple rules', function (): void {
        $result = $this->linter->lint(
            '<img src="test.jpg" style="color: red">',
            'test.blade.php',
            Config::make([
                'rules' => [
                    'a11y-alt-text' => 'error',
                    'best-practices-no-inline-styles' => 'warning',
                ],
            ])
        );

        expect($result->violations)->toHaveCount(2)
            ->and($result->hasErrors())->toBeTrue()
            ->and($result->hasWarnings())->toBeTrue();
    });

    it('respects rule severity', function (): void {
        $result = $this->linter->lint(
            '<img src="test.jpg">',
            'test.blade.php',
            Config::make(['rules' => ['a11y-alt-text' => 'error']])
        );

        expect($result->violations[0]->severity)
            ->toBe(Severity::ERROR);
    });

    it('skips disabled rules', function (): void {
        $result = $this->linter->lint(
            '<img src="test.jpg">',
            'test.blade.php',
            Config::make(['rules' => ['a11y-alt-text' => 'off']])
        );

        expect($result->hasViolations())
            ->toBeFalse();
    });

    it('rejects unknown configured rules instead of silently treating the file as clean', function (): void {
        expect(fn (): mixed => $this->linter->lint(
            '<img src="test.jpg">',
            'test.blade.php',
            Config::make(['rules' => ['a11y-alt-tex' => 'error']])
        ))->toThrow(RuleNotFoundException::class);
    });

    it('passes rule options', function (): void {
        $this->registry->register(NoRawEchoRule::class);

        $result = $this->linter->lint(
            '{!! $trustedHtml !!}',
            'test.blade.php',
            Config::make([
                'rules' => [
                    'security-no-raw-echo' => ['warning', ['allowed' => ['$trustedHtml']]],
                ],
            ])
        );

        expect($result->hasViolations())
            ->toBeFalse();
    });

    it('reports parse errors as violations', function (): void {
        $result = $this->linter->lint(
            '<div>{{ unclosed',
            'test.blade.php',
            Config::make()
        );

        expect($result->hasParseErrors)->toBeTrue()
            ->and($result->hasViolations())->toBeTrue();
    });

    it('accepts deeply nested Blade that the supported compiler accepts', function (): void {
        $source = str_repeat('@if(true) ', 500).'ok '.str_repeat('@endif ', 500);

        $result = $this->linter->lint($source, 'deep.blade.php', Config::make());

        expect($result->hasParseErrors)->toBeFalse()
            ->and($result->violations)->toBe([]);
    });

    it('reports explicitly configured parser depth limits as parse errors', function (): void {
        $source = str_repeat('<div>', 20).str_repeat('</div>', 20);
        $parserOptions = ParserOptions::defaults()->depthLimits(elements: 10);

        $result = $this->linter->lint($source, 'deep.blade.php', Config::make(), $parserOptions);

        expect($result->hasParseErrors)->toBeTrue()
            ->and($result->violations)->toHaveCount(1)
            ->and($result->violations[0]->ruleId)->toBe('parse-error')
            ->and($result->violations[0]->start)->toEqual(new Position(0, 1, 1))
            ->and($result->sourceHash)->toBe(hash('xxh128', $source));
    });

    it('does not misclassify unexpected parser exceptions as parse errors', function (): void {
        $parserOptions = ParserOptions::make()
            ->withComponentPrefix('widget:')
            ->extension(new ThrowingParserExtension);

        expect(fn () => $this->linter->lint(
            '<div></div>',
            'test.blade.php',
            Config::make(),
            $parserOptions,
        ))->toThrow(RuntimeException::class)
            ->and($parserOptions->getComponentManager()->getPrefixes())
            ->toContain('widget:')
            ->not->toContain('x:');
    });

    it('does not run normal rules when parse errors exist', function (): void {
        $this->registry->register(new TestConfigLeakRule);

        $result = $this->linter->lint(
            '<div>{{ unclosed',
            'test.blade.php',
            Config::make(['rules' => ['test-config-leak' => 'error']])
        );

        expect($result->hasParseErrors)->toBeTrue()
            ->and($result->violations)->not->toBeEmpty()
            ->and(collect($result->violations)->pluck('ruleId')->all())->toBe(['parse-error']);
    });

    it('uses parser end positions for parse errors', function (): void {
        $result = $this->linter->lint(
            "<div\n@if(\$x\n",
            'test.blade.php',
            Config::make()
        );

        expect($result->hasParseErrors)->toBeTrue()
            ->and($result->violations)->not->toBeEmpty()
            ->and($result->violations[0]->ruleId)->toBe('parse-error')
            ->and($result->violations[0]->end->offset)->toBeGreaterThan($result->violations[0]->start->offset)
            ->and($result->violations[0]->end->character)->toBeGreaterThan($result->violations[0]->start->character);
    });

    it('sorts violations by position', function (): void {
        $result = $this->linter->lint(
            '<div><img src="test.jpg"><img src="test2.jpg"></div>',
            'test.blade.php',
            Config::make(['rules' => ['a11y-alt-text' => 'error']])
        );

        expect($result->violations)->toHaveCount(2)
            ->and($result->violations[0]->getLine())->toBeLessThanOrEqual($result->violations[1]->getLine());
    });

    it('resets severity between runs for shared rule instances', function (): void {
        $rule = new TestConfigLeakRule;
        $this->registry->register($rule);

        $disabled = $this->linter->lint(
            '<div></div>',
            'test.blade.php',
            Config::make(['rules' => ['test-config-leak' => 'off']])
        );

        $enabled = $this->linter->lint(
            '<div></div>',
            'test.blade.php',
            Config::make(['rules' => ['test-config-leak' => []]])
        );

        expect($disabled->hasViolations())->toBeFalse()
            ->and($enabled->hasViolations())->toBeTrue();
    });

    it('resets options between runs for shared rule instances', function (): void {
        $rule = new TestConfigLeakRule;
        $this->registry->register($rule);

        $configured = $this->linter->lint(
            '<div></div>',
            'test.blade.php',
            Config::make([
                'rules' => [
                    'test-config-leak' => [
                        'severity' => 'warning',
                        'options' => ['token' => 'configured'],
                    ],
                ],
            ])
        );

        $defaulted = $this->linter->lint(
            '<div></div>',
            'test.blade.php',
            Config::make(['rules' => ['test-config-leak' => []]])
        );

        expect($configured->violations[0]->message)->toBe('configured')
            ->and($defaulted->violations[0]->message)->toBe('default');
    });

    it('prepares configured rules once while keeping execution state file-local', function (): void {
        TestExecutionPlanRule::$optionApplications = 0;
        $this->registry->register(TestExecutionPlanRule::class);
        $config = Config::make([
            'rules' => [
                'test-execution-plan' => ['warning', ['token' => 'first']],
            ],
        ]);

        $first = $this->linter->lint('<div></div>', 'first.blade.php', $config);
        $second = $this->linter->lint('<div></div>', 'second.blade.php', $config);

        expect(TestExecutionPlanRule::$optionApplications)->toBe(1)
            ->and($first->violations[0]->message)->toBe('first:1')
            ->and($second->violations[0]->message)->toBe('first:1');

        $config->setRule('test-execution-plan', ['warning', ['token' => 'updated']]);
        $updated = $this->linter->lint('<div></div>', 'updated.blade.php', $config);

        expect(TestExecutionPlanRule::$optionApplications)->toBe(2)
            ->and($updated->violations[0]->message)->toBe('updated:1');
    });

    it('shares typed analysis between rules for one file without leaking across files', function (): void {
        TestSharedAnalysisRule::$builds = 0;
        $this->registry->register(TestFirstSharedAnalysisRule::class);
        $this->registry->register(TestSecondSharedAnalysisRule::class);
        $config = Config::make([
            'rules' => [
                'test-first-shared-analysis' => 'warning',
                'test-second-shared-analysis' => 'warning',
            ],
        ]);

        $this->linter->lint('<div><span></span></div>', 'first.blade.php', $config);
        expect(TestSharedAnalysisRule::$builds)->toBe(1);

        $this->linter->lint('<main></main>', 'second.blade.php', $config);
        expect(TestSharedAnalysisRule::$builds)->toBe(2);
    });

    it('shares a length-preserving parser view between rules without changing offsets', function (): void {
        TestRuleDocumentRule::$builds = 0;
        $registry = new RuleRegistry;
        $registry->register(TestFirstRuleDocumentRule::class);
        $registry->register(TestSecondRuleDocumentRule::class);
        $result = (new Linter($registry))->lint(
            'probe',
            'test.blade.php',
            Config::make(['rules' => [
                'test-first-rule-document' => 'warning',
                'test-second-rule-document' => 'warning',
            ]]),
        );

        expect(TestRuleDocumentRule::$builds)->toBe(1)
            ->and($result->violations)->toHaveCount(2)
            ->and($result->violations[0]->start->offset)->toBe(0)
            ->and($result->violations[0]->end->offset)->toBe(5);
    });

    it('scopes shared parser views to the authored or semantic input document', function (): void {
        TestInputScopedRuleDocumentRule::$builds = 0;
        $registry = new RuleRegistry;
        $registry->register(TestAuthoredRuleDocumentRule::class);
        $registry->register(TestSemanticRuleDocumentRule::class);
        $result = (new Linter($registry))->lint(
            '<x-probe />',
            'test.blade.php',
            Config::make([
                'componentMappings' => ['x-probe' => 'img'],
                'rules' => [
                    'test-authored-rule-document' => 'warning',
                    'test-semantic-rule-document' => 'warning',
                ],
            ]),
        );

        expect(TestInputScopedRuleDocumentRule::$builds)->toBe(2)
            ->and(array_column($result->violations, 'message'))->toBe(['authored', 'semantic']);
    });

    it('fails closed when a same-length rule document is malformed', function (): void {
        TestMalformedRuleDocumentRule::$checks = 0;
        $registry = new RuleRegistry;
        $registry->register(TestMalformedRuleDocumentRule::class);

        $result = (new Linter($registry))->lint(
            '123456789',
            'test.blade.php',
            Config::make(['rules' => ['test-malformed-rule-document' => 'warning']]),
        );

        expect($result->hasParseErrors)->toBeTrue()
            ->and($result->violations)->toHaveCount(1)
            ->and($result->violations[0]->ruleId)->toBe('parse-error')
            ->and($result->violations[0]->start->offset)->toBe(9)
            ->and($result->sourceHash)->toBe(hash('xxh128', '123456789'))
            ->and(TestMalformedRuleDocumentRule::$checks)->toBe(0);
    });

    it('fails closed when a semantic-input rule document is malformed', function (): void {
        TestMalformedRuleDocumentRule::$checks = 0;
        TestMalformedRuleDocumentRule::$inputSource = '';
        $registry = new RuleRegistry;
        $registry->register(TestMalformedRuleDocumentRule::class);

        $result = (new Linter($registry))->lint(
            '<x-probe />',
            'test.blade.php',
            Config::make([
                'componentMappings' => ['x-probe' => 'img'],
                'rules' => ['test-malformed-rule-document' => 'warning'],
            ]),
        );

        expect(TestMalformedRuleDocumentRule::$inputSource)->toContain('<img')
            ->and($result->hasParseErrors)->toBeTrue()
            ->and($result->violations)->toHaveCount(1)
            ->and($result->violations[0]->ruleId)->toBe('parse-error')
            ->and($result->violations[0]->start->offset)->toBe(strlen('<x-probe />'))
            ->and($result->violations[0]->end->offset)->toBe(strlen('<x-probe />') + 1)
            ->and(TestMalformedRuleDocumentRule::$checks)->toBe(0);
    });

    it('isolates explicitly cloned nested rule state between files', function (): void {
        $prototype = new TestNestedCloneStateRule;
        $registry = new RuleRegistry;
        $registry->register($prototype);
        $linter = new Linter($registry);
        $config = Config::make(['rules' => ['test-nested-clone-state' => 'warning']]);

        $first = $linter->lint('first', 'first.blade.php', $config);
        $second = $linter->lint('second', 'second.blade.php', $config);

        expect($first->violations[0]->message)->toBe('checks=1')
            ->and($second->violations[0]->message)->toBe('checks=1')
            ->and($prototype->state->checks)->toBe(0);
    });

    it('skips disabled package-scoped rules when package mode is disable', function (): void {
        $checker = Dependencies::fromData(
            ['require' => ['laravel/framework' => '^11.0']],
            ['packages' => [['name' => 'laravel/framework', 'version' => 'v11.0.0']]]
        );

        $registry = new RuleRegistry;
        $registry->setPackageRequirementEvaluator(new PackageRequirementEvaluator($checker));
        $registry->setPackageRequirementMode(PackageRequirementMode::DISABLE);
        $registry->register(TestPackageAwareRule::class);

        $result = (new Linter($registry))->lint(
            '<div></div>',
            'test.blade.php',
            Config::make([
                'rules' => ['test-package-aware' => 'error'],
                'packageRequirementMode' => 'disable',
            ])
        );

        expect($registry->has('test-package-aware'))->toBeTrue()
            ->and($registry->isDisabledDueToPackages('test-package-aware'))->toBeTrue()
            ->and($result->hasViolations())->toBeFalse();
    });

    it('treats the config package mode as authoritative across repeated runs', function (): void {
        $checker = Dependencies::fromData(
            ['require' => ['laravel/framework' => '^11.0']],
            ['packages' => [['name' => 'laravel/framework', 'version' => 'v11.0.0']]]
        );

        $registry = new RuleRegistry;
        $registry->setPackageRequirementEvaluator(new PackageRequirementEvaluator($checker));
        $registry->register(TestPackageAwareRule::class);
        $linter = new Linter($registry);

        expect($registry->isSkippedDueToPackages('test-package-aware'))->toBeTrue();

        $ignored = $linter->lint(
            '<div></div>',
            'test.blade.php',
            Config::make([
                'rules' => ['test-package-aware' => 'error'],
                'packageRequirementMode' => 'ignore',
            ])
        );

        $disabled = $linter->lint(
            '<div></div>',
            'test.blade.php',
            Config::make([
                'rules' => ['test-package-aware' => 'error'],
                'packageRequirementMode' => 'disable',
            ])
        );

        $skipped = $linter->lint(
            '<div></div>',
            'test.blade.php',
            Config::make([
                'rules' => ['test-package-aware' => 'error'],
                'packageRequirementMode' => 'skip',
            ])
        );

        expect($ignored->hasViolations())->toBeTrue()
            ->and($ignored->violations[0]->ruleId)->toBe('test-package-aware')
            ->and($disabled->hasViolations())->toBeFalse()
            ->and($skipped->hasViolations())->toBeFalse()
            ->and($registry->isSkippedDueToPackages('test-package-aware'))->toBeTrue();
    });
});
