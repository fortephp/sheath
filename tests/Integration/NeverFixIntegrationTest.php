<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Fixer;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Accessibility\Focus\NoAccesskeyRule;
use Forte\Sheath\Rules\Accessibility\Focus\NoPositiveTabindexRule;
use Forte\Sheath\Rules\BestPractices\Attributes\ButtonTypeRule;
use Forte\Sheath\Rules\BestPractices\Markup\SelfClosingVoidElementsRule;
use Forte\Sheath\Rules\RuleRegistry;

describe('neverFix Integration', function (): void {
    beforeEach(function (): void {
        $this->registry = new RuleRegistry;
        $this->registry->registerMany([
            NoAccesskeyRule::class,
            NoPositiveTabindexRule::class,
            ButtonTypeRule::class,
            SelfClosingVoidElementsRule::class,
        ]);

        $this->linter = new Linter($this->registry);
        $this->fixer = new Fixer;
    });

    it('reports violations but without fixes when rule is in neverFix', function (string $identifier): void {
        $template = '<button accesskey="s">Submit</button>';

        $result = $this->linter->lint($template, 'test.blade.php', Config::make([
            'rules' => ['a11y-no-accesskey' => 'error'],
            'neverFix' => [$identifier],
        ]));

        expect($result->hasViolations())->toBeTrue()
            ->and($result->violations)->toHaveCount(1)
            ->and($result->violations[0]->ruleId)->toBe('a11y-no-accesskey')
            ->and($result->violations[0]->fix)->toBeNull()
            ->and($result->violations[0]->hasFixAvailable())->toBeFalse()
            ->and($result->getFixableCount())->toBe(0);
    })->with([
        'rule ID' => 'a11y-no-accesskey',
        'rule class' => NoAccesskeyRule::class,
    ]);

    it('reports fix for rule not in neverFix', function (): void {
        $template = '<button accesskey="s">Submit</button>';

        $result = $this->linter->lint($template, 'test.blade.php', Config::make([
            'rules' => ['a11y-no-accesskey' => 'error'],
        ]));

        expect($result->hasViolations())->toBeTrue()
            ->and($result->violations)->toHaveCount(1)
            ->and($result->violations[0]->fix)->not->toBeNull()
            ->and($result->violations[0]->hasFixAvailable())->toBeTrue()
            ->and($result->getFixableCount())->toBe(1);
    });

    it('selectively suppresses fixes for specific rules only', function (): void {
        $template = '<button accesskey="s" tabindex="5">Submit</button>';

        $result = $this->linter->lint($template, 'test.blade.php', Config::make([
            'rules' => [
                'a11y-no-accesskey' => 'error',
                'a11y-no-positive-tabindex' => 'error',
            ],
            'neverFix' => ['a11y-no-accesskey'],
        ]));

        expect($result->violations)->toHaveCount(2);

        $accesskeyViolation = null;
        $tabindexViolation = null;
        foreach ($result->violations as $v) {
            if ($v->ruleId === 'a11y-no-accesskey') {
                $accesskeyViolation = $v;
            } elseif ($v->ruleId === 'a11y-no-positive-tabindex') {
                $tabindexViolation = $v;
            }
        }

        expect($accesskeyViolation)->not->toBeNull()
            ->and($accesskeyViolation->fix)->toBeNull()
            ->and($accesskeyViolation->hasFixAvailable())->toBeFalse();

        expect($tabindexViolation)->not->toBeNull()
            ->and($tabindexViolation->fix)->not->toBeNull()
            ->and($tabindexViolation->hasFixAvailable())->toBeTrue();
        expect($result->getFixableCount())->toBe(1);
    });

    it('prevents fixes from being applied for neverFix rules', function (): void {
        $config = Config::make([
            'rules' => [
                'a11y-no-accesskey' => 'error',
                'a11y-no-positive-tabindex' => 'error',
            ],
            'neverFix' => ['a11y-no-accesskey'],
        ]);

        $template = '<button accesskey="s" tabindex="5">Submit</button>';

        $result = $this->linter->lint($template, 'test.blade.php', $config);

        $fixes = array_filter(array_map(fn ($v) => $v->fix, $result->violations));
        expect(count($fixes))->toBe(1);
        $fixResult = $this->fixer->applyFixes($template, $fixes, includeDangerous: true);
        expect($fixResult->content)->toContain('accesskey="s"')
            ->and($fixResult->content)->toContain('tabindex="0"')
            ->and($fixResult->appliedCount)->toBe(1);
    });

    it('suppresses all fixes when all rules are in neverFix', function (): void {
        $template = '<button accesskey="s" tabindex="5">Submit</button>';

        $result = $this->linter->lint($template, 'test.blade.php', Config::make([
            'rules' => [
                'a11y-no-accesskey' => 'error',
                'a11y-no-positive-tabindex' => 'error',
                'best-practices-button-type' => 'error',
            ],
            'neverFix' => [
                'a11y-no-accesskey',
                'a11y-no-positive-tabindex',
                'best-practices-button-type',
            ],
        ]));

        expect($result->violations)->toHaveCount(3);
        expect($result->getFixableCount())->toBe(0);
        foreach ($result->violations as $violation) {
            expect($violation->fix)->toBeNull()
                ->and($violation->hasFixAvailable())->toBeFalse();
        }
    });

    it('works with multiple rules of different types in neverFix', function (): void {
        $template = '<button accesskey="s">Submit</button><br />';

        $result = $this->linter->lint($template, 'test.blade.php', Config::make([
            'rules' => [
                'a11y-no-accesskey' => 'error',
                'best-practices-button-type' => 'error',
                'best-practices-self-closing-void-elements' => 'error',
            ],
            'neverFix' => ['a11y-no-accesskey', 'best-practices-self-closing-void-elements'],
        ]));
        $accesskeyViolation = null;
        $buttonTypeViolation = null;
        $selfClosingViolation = null;

        foreach ($result->violations as $v) {
            match ($v->ruleId) {
                'a11y-no-accesskey' => $accesskeyViolation = $v,
                'best-practices-button-type' => $buttonTypeViolation = $v,
                'best-practices-self-closing-void-elements' => $selfClosingViolation = $v,
                default => null,
            };
        }

        expect($accesskeyViolation)->not->toBeNull()
            ->and($accesskeyViolation->hasFixAvailable())->toBeFalse();

        expect($selfClosingViolation)->not->toBeNull()
            ->and($selfClosingViolation->hasFixAvailable())->toBeFalse();

        expect($buttonTypeViolation)->not->toBeNull()
            ->and($buttonTypeViolation->hasFixAvailable())->toBeTrue();
    });

    it('loads neverFix from config array', function (): void {
        $config = Config::fromArray([
            'rules' => [
                'a11y-no-accesskey' => 'error',
            ],
            'neverFix' => ['a11y-no-accesskey'],
        ]);

        $template = '<button accesskey="s">Submit</button>';

        $result = $this->linter->lint($template, 'test.blade.php', $config);

        expect($result->violations)->toHaveCount(1)
            ->and($result->violations[0]->fix)->toBeNull()
            ->and($result->getFixableCount())->toBe(0);
    });

    it('works after config merge with neverFix', function (): void {
        $baseConfig = Config::make([
            'rules' => [
                'a11y-no-accesskey' => 'error',
                'a11y-no-positive-tabindex' => 'error',
            ],
        ]);

        $overrideConfig = Config::make(['neverFix' => ['a11y-no-accesskey']]);

        $baseConfig->merge($overrideConfig);

        $template = '<button accesskey="s" tabindex="5">Submit</button>';

        $result = $this->linter->lint($template, 'test.blade.php', $baseConfig);

        expect($result->violations)->toHaveCount(2);

        $accesskeyViolation = null;
        $tabindexViolation = null;
        foreach ($result->violations as $v) {
            if ($v->ruleId === 'a11y-no-accesskey') {
                $accesskeyViolation = $v;
            } elseif ($v->ruleId === 'a11y-no-positive-tabindex') {
                $tabindexViolation = $v;
            }
        }

        expect($accesskeyViolation->hasFixAvailable())->toBeFalse();
        expect($tabindexViolation->hasFixAvailable())->toBeTrue();
    });

    it('preserves violation severity when fix is suppressed', function (): void {
        $template = '<button accesskey="s">Submit</button>';

        $result = $this->linter->lint($template, 'test.blade.php', Config::make([
            'rules' => ['a11y-no-accesskey' => 'warning'],
            'neverFix' => ['a11y-no-accesskey'],
        ]));

        expect($result->violations)->toHaveCount(1)
            ->and($result->violations[0]->severity->value)->toBe('warning')
            ->and($result->violations[0]->fix)->toBeNull();
    });

    it('works with rules using reportAt instead of report', function (): void {
        $template = '<button>Submit</button>';

        $result = $this->linter->lint($template, 'test.blade.php', Config::make([
            'rules' => ['best-practices-button-type' => 'error'],
            'neverFix' => ['best-practices-button-type'],
        ]));

        expect($result->violations)->toHaveCount(1)
            ->and($result->violations[0]->fix)->toBeNull();
    });

    it('handles rule ID that does not exist in neverFix gracefully', function (): void {
        $template = '<button accesskey="s">Submit</button>';

        $result = $this->linter->lint($template, 'test.blade.php', Config::make([
            'rules' => ['a11y-no-accesskey' => 'error'],
            'neverFix' => ['non-existent-rule', 'another-fake-rule'],
        ]));
        expect($result->violations)->toHaveCount(1)
            ->and($result->violations[0]->fix)->not->toBeNull()
            ->and($result->getFixableCount())->toBe(1);
    });
});
