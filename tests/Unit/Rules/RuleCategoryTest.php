<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Rules\RuleRegistry;

class ProjectConventionRule extends AbstractRule
{
    public function getId(): string
    {
        return 'project-no-data-testid';
    }

    public function getDescription(): string
    {
        return 'Production templates should not ship with data-testid attributes.';
    }

    public function getCategory(): RuleCategory|string
    {
        return 'project-conventions';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $document->queryElements()->each(function ($element) use ($context): void {
            if ($element->hasAttribute('data-testid')) {
                $context->report($element, 'Remove data-testid attributes from production templates.');
            }
        });
    }
}

describe('RuleCategory', function (): void {
    it('labels each family', function (RuleCategory $category, string $label): void {
        expect($category->label())->toBe($label);
    })->with([
        [RuleCategory::ACCESSIBILITY, 'Accessibility'],
        [RuleCategory::BEST_PRACTICES, 'Best Practices'],
        [RuleCategory::BLADE, 'Blade'],
        [RuleCategory::PERFORMANCE, 'Performance'],
        [RuleCategory::SECURITY, 'Security'],
        [RuleCategory::SEO, 'SEO'],
    ]);

    it('resolves a family from a rule ID prefix', function (): void {
        expect(RuleCategory::fromRuleId('a11y-alt-text'))->toBe(RuleCategory::ACCESSIBILITY)
            ->and(RuleCategory::fromRuleId('perf-lazy-load-images'))->toBe(RuleCategory::PERFORMANCE)
            ->and(RuleCategory::fromRuleId('project-no-data-testid'))->toBeNull();
    });

    describe('normalising a category that may be custom', function (): void {
        it('reads the name of either form', function (): void {
            expect(RuleCategory::nameFor(RuleCategory::ACCESSIBILITY))->toBe('a11y')
                ->and(RuleCategory::nameFor('project-conventions'))->toBe('project-conventions');
        });

        it('reads the label of either form', function (): void {
            expect(RuleCategory::labelFor(RuleCategory::ACCESSIBILITY))->toBe('Accessibility')
                ->and(RuleCategory::labelFor('project-conventions'))->toBe('project-conventions');
        });
    });
});

describe('custom rule categories', function (): void {
    it('runs a rule with a custom category', function (): void {
        $registry = new RuleRegistry;
        $registry->register(ProjectConventionRule::class);

        $config = Config::make()->setRule('project-no-data-testid', 'warning');

        $result = (new Linter($registry))->lint(
            '<button type="button" data-testid="save">Save</button>',
            'test.blade.php',
            $config
        );

        expect($result->violations)->toHaveCount(1)
            ->and($result->violations[0]->ruleId)->toBe('project-no-data-testid');
    });
});
