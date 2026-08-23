<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Exceptions\ConfigurationException;
use Forte\Sheath\Linter;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\BestPractices\Markup\NoObsoleteTagsRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Testing\RuleTester;

class OptionDefaultsProbeRule extends AbstractRule
{
    /** @var array<string, mixed> */
    public static array $executedOptions = [];

    protected array $options = [
        'flag' => 'default-flag',
        'items' => ['a', 'b'],
    ];

    public function getId(): string
    {
        return 'probe-option-defaults';
    }

    public function getDescription(): string
    {
        return 'Reports the options the rule executed with.';
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
        self::$executedOptions = $this->getOptions();

        $document->queryElements()->each(function (ElementNode $element) use ($context): void {
            $context->report($element, 'Option probe.');
        });
    }
}

/** @return array<string, mixed> */
function lintWithProbe(?array $configuredOptions): array
{
    $registry = new RuleRegistry;
    $registry->register(OptionDefaultsProbeRule::class);

    $ruleConfig = ['severity' => 'warning'];
    if ($configuredOptions !== null) {
        $ruleConfig['options'] = $configuredOptions;
    }

    $config = Config::make()->setRule('probe-option-defaults', $ruleConfig);

    $result = (new Linter($registry))->lint('<div></div>', 'test.blade.php', $config);

    expect($result->violations)->toHaveCount(1);

    return OptionDefaultsProbeRule::$executedOptions;
}

describe('rule option defaults', function (): void {
    it('keeps property-declared defaults through a real Linter run with no options configured', function (): void {
        expect(lintWithProbe(null))->toBe(['flag' => 'default-flag', 'items' => ['a', 'b']]);
    });

    it('merges partially configured options over the declared defaults', function (): void {
        expect(lintWithProbe(['flag' => 'custom']))
            ->toBe(['flag' => 'custom', 'items' => ['a', 'b']]);
    });

    it('lets a configured option override a default without touching the others', function (): void {
        expect(lintWithProbe(['items' => ['x']]))
            ->toBe(['flag' => 'default-flag', 'items' => ['x']]);
    });

    it('restores declared defaults when setOptions receives an empty array', function (): void {
        $rule = new OptionDefaultsProbeRule;
        $rule->setOptions(['flag' => 'custom']);
        $rule->setOptions([]);

        expect($rule->getOptions())->toBe([
            'flag' => 'default-flag',
            'items' => ['a', 'b'],
        ]);
    });

    it('exposes only the explicitly configured options via getConfiguredOptions', function (): void {
        $rule = new OptionDefaultsProbeRule;
        $rule->setOptions(['flag' => 'custom']);

        expect($rule->getConfiguredOptions())->toBe(['flag' => 'custom'])
            ->and($rule->getOptions())->toBe(['flag' => 'custom', 'items' => ['a', 'b']]);
    });

    it('rejects unknown options on configurable rules', function (): void {
        expect(fn () => (new OptionDefaultsProbeRule)->setOptions(['flg' => 'custom']))
            ->toThrow(ConfigurationException::class);
    });

    it('rejects option values with the wrong type', function (): void {
        expect(fn () => (new OptionDefaultsProbeRule)->setOptions(['items' => 'a,b']))
            ->toThrow(ConfigurationException::class);
    });

    it('leaves the universal exclude option to path normalization', function (): void {
        $rule = new OptionDefaultsProbeRule;
        $rule->setOptions(['exclude' => 'legacy/']);

        expect($rule->getConfiguredOptions())->toBe(['exclude' => 'legacy/']);
    });

    describe('RuleTester matches Linter behavior', function (): void {
        it('exercises the declared defaults when a test sets no options', function (): void {
            (new RuleTester)->run(new OptionDefaultsProbeRule, [
                'invalid' => [
                    [
                        'code' => '<div></div>',
                        'errors' => 1,
                    ],
                ],
            ]);

            expect(OptionDefaultsProbeRule::$executedOptions)
                ->toBe(['flag' => 'default-flag', 'items' => ['a', 'b']]);
        });

        it('merges explicitly set options over defaults, as production does', function (): void {
            $rule = new OptionDefaultsProbeRule;
            $rule->setOptions(['flag' => 'custom']);

            (new RuleTester)->run($rule, [
                'invalid' => [
                    [
                        'code' => '<div></div>',
                        'errors' => 1,
                    ],
                ],
            ]);

            expect(OptionDefaultsProbeRule::$executedOptions)
                ->toBe(['flag' => 'custom', 'items' => ['a', 'b']]);
        });
    });

    it('keeps NoObsoleteTagsRule allow semantics under a real Linter run', function (): void {
        $registry = new RuleRegistry;
        $registry->register(NoObsoleteTagsRule::class);

        $config = Config::make()->setRule('best-practices-no-obsolete-tags', [
            'severity' => 'warning',
            'options' => ['allow' => ['spacer']],
        ]);

        $result = (new Linter($registry))->lint(
            '<spacer></spacer><marquee></marquee>',
            'test.blade.php',
            $config
        );

        expect($result->violations)->toHaveCount(1);
    });
});
