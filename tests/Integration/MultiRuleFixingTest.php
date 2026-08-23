<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Fixer;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Accessibility\Aria\NoAbstractRolesRule;
use Forte\Sheath\Rules\Accessibility\Aria\NoAriaHiddenOnFocusableRule;
use Forte\Sheath\Rules\Accessibility\Aria\NoInvalidRoleRule;
use Forte\Sheath\Rules\Accessibility\Focus\NoAccesskeyRule;
use Forte\Sheath\Rules\Accessibility\Focus\NoPositiveTabindexRule;
use Forte\Sheath\Rules\Accessibility\Structure\NoNonScalableViewportRule;
use Forte\Sheath\Rules\BestPractices\Attributes\ButtonTypeRule;
use Forte\Sheath\Rules\BestPractices\Attributes\NoDuplicateAttrsRule;
use Forte\Sheath\Rules\BestPractices\Attributes\NoDuplicateClassRule;
use Forte\Sheath\Rules\BestPractices\Markup\NoScriptStyleTypeRule;
use Forte\Sheath\Rules\BestPractices\Markup\SelfClosingVoidElementsRule;
use Forte\Sheath\Rules\Performance\LazyLoadImagesRule;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Rules\Security\NoTargetBlankRule;

describe('Multi-Rule Fixing Integration', function (): void {
    beforeEach(function (): void {
        $this->registry = new RuleRegistry;
        $this->registry->registerMany([
            NoAbstractRolesRule::class,
            NoAccesskeyRule::class,
            NoAriaHiddenOnFocusableRule::class,
            NoInvalidRoleRule::class,
            NoNonScalableViewportRule::class,
            NoPositiveTabindexRule::class,
            ButtonTypeRule::class,
            NoDuplicateAttrsRule::class,
            NoDuplicateClassRule::class,
            NoScriptStyleTypeRule::class,
            SelfClosingVoidElementsRule::class,
            LazyLoadImagesRule::class,
            NoTargetBlankRule::class,
        ]);

        $this->linter = new Linter($this->registry);
        $this->fixer = new Fixer;
    });

    it('can apply multiple fixes from different rules on the same element', function (): void {
        $config = Config::make([
            'rules' => [
                'a11y-no-accesskey' => 'error',
                'a11y-no-positive-tabindex' => 'error',
            ],
        ]);

        $template = '<button accesskey="s" tabindex="5">Submit</button>';

        $result = $this->linter->lint($template, 'test.blade.php', $config);

        expect($result->hasViolations())->toBeTrue()
            ->and($result->violations)->toHaveCount(2)
            ->and($result->getFixableCount())->toBe(2);

        $fixes = array_map(fn ($v) => $v->fix, $result->violations);
        $fixResult = $this->fixer->applyFixes($template, $fixes, includeDangerous: true);

        expect($fixResult->content)->toBe('<button tabindex="0">Submit</button>')
            ->and($fixResult->appliedCount)->toBe(2)
            ->and($fixResult->allApplied())->toBeTrue();
    });

    it('composes an attribute insertion with self-closing syntax after existing whitespace', function (): void {
        $config = Config::make([
            'rules' => [
                'perf-lazy-load-images' => ['warning', ['skipAboveFold' => false]],
                'best-practices-self-closing-void-elements' => ['warning', ['style' => 'always']],
            ],
        ]);
        $template = '<img src="photo.jpg" >';

        $result = $this->linter->lint($template, 'test.blade.php', $config);
        $fixes = array_map(fn ($violation) => $violation->fix, $result->violations);
        $fixResult = $this->fixer->applyFixes($template, $fixes, includeDangerous: true);

        expect($result->violations)->toHaveCount(2)
            ->and($fixResult->content)->toBe('<img src="photo.jpg" loading="lazy" />')
            ->and($fixResult->allApplied())->toBeTrue();
    });

    it('can apply fixes from multiple accessibility rules', function (): void {
        $config = Config::make([
            'rules' => [
                'a11y-no-abstract-roles' => 'error',
                'a11y-no-aria-hidden-on-focusable' => 'error',
                'best-practices-button-type' => 'error',
            ],
        ]);

        $template = '<button role="widget" aria-hidden="true">Click</button>';

        $result = $this->linter->lint($template, 'test.blade.php', $config);

        expect($result->hasViolations())->toBeTrue()
            ->and($result->violations)->toHaveCount(3)
            ->and($result->getFixableCount())->toBe(3);

        $fixes = array_map(fn ($v) => $v->fix, $result->violations);
        $fixResult = $this->fixer->applyFixes($template, $fixes, includeDangerous: true);

        expect($fixResult->content)->toBe('<button type="button">Click</button>')
            ->and($fixResult->appliedCount)->toBe(3)
            ->and($fixResult->allApplied())->toBeTrue();
    });

    it('composes value-rewriting fixes without exposing decoded quotes', function (): void {
        $config = Config::make([
            'rules' => [
                'a11y-no-abstract-roles' => 'error',
                'best-practices-no-duplicate-class' => 'error',
            ],
        ]);
        $template = '<div class="card card &quot;featured" role="widget &quot;foo">Content</div>';

        $result = $this->linter->lint($template, 'test.blade.php', $config);
        $fixes = array_map(fn ($violation) => $violation->fix, $result->violations);
        $fixResult = $this->fixer->applyFixes($template, $fixes, includeDangerous: true);

        expect($result->violations)->toHaveCount(2)
            ->and($fixResult->content)
            ->toBe('<div class="card &quot;featured" role="&quot;foo">Content</div>')
            ->and($fixResult->allApplied())->toBeTrue();
    });

    it('handles fixes across multiple elements', function (): void {
        $config = Config::make([
            'rules' => [
                'a11y-no-accesskey' => 'error',
                'a11y-no-invalid-role' => 'error',
                'best-practices-button-type' => 'error',
            ],
        ]);

        $template = <<<'HTML'
<div>
    <a href="/" accesskey="h">Home</a>
    <button role="invalid-role">Click</button>
    <button>Submit</button>
</div>
HTML;

        $result = $this->linter->lint($template, 'test.blade.php', $config);

        expect($result->hasViolations())->toBeTrue();

        $fixes = array_filter(array_map(fn ($v) => $v->fix, $result->violations));
        $fixResult = $this->fixer->applyFixes($template, $fixes, includeDangerous: true);

        expect($fixResult->content)->toBe(<<<'HTML'
<div>
    <a href="/">Home</a>
    <button type="button">Click</button>
    <button type="button">Submit</button>
</div>
HTML)
            ->and($fixResult->appliedCount)->toBe(4)
            ->and($fixResult->allApplied())->toBeTrue();
    });

    it('handles viewport meta with multiple issues', function (): void {
        $template = '<meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no, maximum-scale=1">';

        $result = $this->linter->lint($template, 'test.blade.php', Config::make([
            'rules' => ['a11y-no-non-scalable-viewport' => 'error'],
        ]));

        expect($result->hasViolations())->toBeTrue()
            ->and($result->violations)->toHaveCount(2)
            ->and($result->getFixableCount())->toBe(2);

        $fixes = array_map(fn ($v) => $v->fix, $result->violations);
        $fixResult = $this->fixer->applyFixes($template, $fixes, includeDangerous: true);

        expect($fixResult->content)->toBe('<meta name="viewport" content="width=device-width, initial-scale=1">')
            ->and($fixResult->appliedCount)->toBe(1)
            ->and($fixResult->hasOverlaps)->toBeTrue();
    });

    it('can fix complex template with many rule violations', function (): void {
        $config = Config::make([
            'rules' => [
                'a11y-no-accesskey' => 'error',
                'a11y-no-positive-tabindex' => 'error',
                'a11y-no-abstract-roles' => 'error',
                'a11y-no-invalid-role' => 'error',
                'best-practices-button-type' => 'error',
                'best-practices-self-closing-void-elements' => 'error',
                'best-practices-no-script-style-type' => 'error',
                'security-no-target-blank' => 'warning',
            ],
        ]);

        $template = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
    <script type="text/javascript" src="app.js"></script>
    <link rel="stylesheet" type="text/css" href="style.css">
</head>
<body>
    <nav role="widget">
        <a href="/" accesskey="h" tabindex="1">Home</a>
        <a href="/about" target="_blank" rel="opener">About</a>
    </nav>
    <main>
        <button tabindex="2">Submit</button>
        <img src="logo.png" />
        <br />
        <div role="invalid">Content</div>
    </main>
</body>
</html>
HTML;

        $result = $this->linter->lint($template, 'test.blade.php', $config);

        expect($result->hasViolations())->toBeTrue();

        $fixableViolations = array_filter($result->violations, fn ($v) => $v->hasFixAvailable());
        expect(count($fixableViolations))->toBeGreaterThan(5);

        $fixes = array_filter(array_map(fn ($v) => $v->fix, $result->violations));
        $fixResult = $this->fixer->applyFixes($template, $fixes, includeDangerous: true);

        expect($fixResult->appliedCount)->toBeGreaterThan(5);
    });

    it('handles mixed abstract and invalid roles', function (): void {
        $config = Config::make([
            'rules' => [
                'a11y-no-abstract-roles' => 'error',
                'a11y-no-invalid-role' => 'error',
            ],
        ]);

        $template1 = '<div role="widget button">Content</div>';
        $result1 = $this->linter->lint($template1, 'test.blade.php', $config);

        expect($result1->violations)->toHaveCount(1)
            ->and($result1->violations[0]->ruleId)->toBe('a11y-no-abstract-roles');

        $template2 = '<div role="button invalid">Content</div>';
        $result2 = $this->linter->lint($template2, 'test.blade.php', $config);

        expect($result2->violations)->toBe([]);

        $template3 = '<div role="widget nonsense">Content</div>';
        $result3 = $this->linter->lint($template3, 'test.blade.php', $config);

        expect(array_map(fn ($v) => $v->ruleId, $result3->violations))
            ->toBe(['a11y-no-abstract-roles', 'a11y-no-invalid-role']);
    });

    it('reports abstract roles itself when a11y-no-abstract-roles is off', function (): void {
        $result = $this->linter->lint('<div role="widget">Content</div>', 'test.blade.php', Config::make([
            'rules' => ['a11y-no-invalid-role' => 'error'],
        ]));

        expect($result->violations)->toHaveCount(1)
            ->and($result->violations[0]->ruleId)->toBe('a11y-no-invalid-role');
    });

    it('preserves valid roles when fixing mixed role violations', function (): void {
        $template = '<div role="navigation widget">Nav</div>';
        $result = $this->linter->lint($template, 'test.blade.php', Config::make([
            'rules' => ['a11y-no-abstract-roles' => 'error'],
        ]));

        expect($result->violations)->toHaveCount(1)
            ->and($result->getFixableCount())->toBe(1);

        $fix = $result->violations[0]->fix;
        $fixResult = $this->fixer->applyFixes($template, [$fix]);

        expect($fixResult->content)->toContain('navigation')
            ->and($fixResult->content)->not->toContain('widget');
    });

    it('handles duplicate class and attribute fixes together', function (): void {
        $config = Config::make([
            'rules' => [
                'best-practices-no-duplicate-class' => 'error',
                'best-practices-no-duplicate-attrs' => 'error',
            ],
        ]);

        $template = '<div class="foo bar foo" id="test" id="test2">Content</div>';

        $result = $this->linter->lint($template, 'test.blade.php', $config);

        expect($result->hasViolations())->toBeTrue();

        $fixes = array_filter(array_map(fn ($v) => $v->fix, $result->violations));
        $fixResult = $this->fixer->applyFixes($template, $fixes);

        expect($fixResult->content)->toBe('<div class="foo bar" id="test">Content</div>')
            ->and($fixResult->appliedCount)->toBe(2)
            ->and($fixResult->allApplied())->toBeTrue();
    });

    it('applies fixes in correct order for deeply nested elements', function (): void {
        $config = Config::make([
            'rules' => [
                'a11y-no-accesskey' => 'error',
                'best-practices-button-type' => 'error',
            ],
        ]);

        $template = <<<'HTML'
<div>
    <section>
        <form>
            <fieldset>
                <button accesskey="s">Save</button>
                <button accesskey="c">Cancel</button>
            </fieldset>
        </form>
    </section>
</div>
HTML;

        $result = $this->linter->lint($template, 'test.blade.php', $config);

        expect($result->violations)->toHaveCount(4);

        $fixes = array_filter(array_map(fn ($v) => $v->fix, $result->violations));
        $fixResult = $this->fixer->applyFixes($template, $fixes, includeDangerous: true);

        expect($fixResult->content)->toBe(<<<'HTML'
<div>
    <section>
        <form>
            <fieldset>
                <button type="submit">Save</button>
                <button type="submit">Cancel</button>
            </fieldset>
        </form>
    </section>
</div>
HTML)
            ->and($fixResult->appliedCount)->toBe(4)
            ->and($fixResult->allApplied())->toBeTrue();
    });
});
