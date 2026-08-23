<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Accessibility\Aria\NoAbstractRolesRule;
use Forte\Sheath\Rules\Accessibility\Aria\NoInvalidRoleRule;
use Forte\Sheath\Rules\RuleRegistry;

describe('NoInvalidRoleRule', function (): void {
    it('passes for valid ARIA roles', function (string $code): void {
        $this->getRuleTester()->run(new NoInvalidRoleRule, ['valid' => [$code]]);
    })->with([
        'button role' => '<button role="button">Click</button>',
        'multiple valid roles' => '<div role="button checkbox">Toggle</div>',
        'unknown role with valid fallback' => '<div role="future-role button">Toggle</div>',
        'valid role before unknown fallback' => '<div role="button future-role">Toggle</div>',
    ]);

    it('uses the first duplicate role attribute on each render path', function (): void {
        $this->getRuleTester()->run(new NoInvalidRoleRule, [
            'valid' => ['<div role="button" @if($x) role="bogus" @endif>Action</div>'],
        ]);
    });

    it('is case insensitive', function (): void {
        $this->getRuleTester()->run(new NoInvalidRoleRule, [
            'valid' => ['<div role="Navigation">Nav</div>'],
        ]);
    });

    it('skips dynamic role attributes', function (string $code): void {
        $this->getRuleTester()->run(new NoInvalidRoleRule, ['valid' => [$code]]);
    })->with([
        'alpine bound' => '<div :role="dynamicRole">Content</div>',
        'blade interpolation' => '<div role="{{ $role }}">Content</div>',
    ]);

    it('fails for an unknown role', function (): void {
        $this->getRuleTester()->run(new NoInvalidRoleRule, [
            'invalid' => [[
                'code' => '<div role="custom-widget">Widget</div>',
                'errors' => 1,
            ]],
        ]);
    });

    it('reports multiple invalid roles', function (): void {
        $this->getRuleTester()->run(new NoInvalidRoleRule, [
            'invalid' => [[
                'code' => '<div role="foo bar">Content</div>',
                'errors' => 2,
            ]],
        ]);
    });

    it('treats vertical-tab-separated text as one invalid role token', function (): void {
        $role = "foo\x0Bbar";

        $this->getRuleTester()->run(new NoInvalidRoleRule, [
            'invalid' => [[
                'code' => "<div role=\"{$role}\"></div>",
                'errors' => 1,
            ]],
        ]);
    });

    it('provides a fix to remove an invalid role attribute', function (): void {
        $this->getRuleTester()->run(new NoInvalidRoleRule, [
            'invalid' => [[
                'code' => "<div role='badvalue'>Content</div>",
                'errors' => 1,
                'hasFix' => true,
            ]],
        ]);
    });

    it('passes for roles WAI-ARIA 1.3 added', function (string $code): void {
        $this->getRuleTester()->run(new NoInvalidRoleRule, ['valid' => [$code]]);
    })->with([
        'image' => '<svg role="image" aria-label="Chart"><path d="M1 1" /></svg>',
        'comment' => '<div role="comment">Nice work</div>',
        'suggestion' => '<div role="suggestion">Try this</div>',
        'sectionheader' => '<div role="sectionheader">Heading</div>',
        'sectionfooter' => '<div role="sectionfooter">Footer</div>',
        'associationlist' => '<div role="associationlist">List</div>',
        'associationlistitemkey' => '<div role="associationlistitemkey">Key</div>',
        'associationlistitemvalue' => '<div role="associationlistitemvalue">Value</div>',
        'mark' => '<span role="mark">Highlighted</span>',
    ]);

    it('keeps the ARIA 1.2 name for the role renamed in 1.3', function (): void {
        $this->getRuleTester()->run(new NoInvalidRoleRule, [
            'valid' => ['<svg role="img" aria-label="Chart"><path d="M1 1" /></svg>'],
        ]);
    });

    it('passes for standardized extension-module roles', function (string $code): void {
        $this->getRuleTester()->run(new NoInvalidRoleRule, ['valid' => [$code]]);
    })->with([
        'DPUB page break' => '<span role="doc-pagebreak" aria-label="Page 2"></span>',
        'DPUB table of contents' => '<nav role="doc-toc">Contents</nav>',
        'DPUB deprecated but valid role' => '<li role="doc-biblioentry">Reference</li>',
        'Graphics document' => '<svg role="graphics-document" aria-label="Diagram"></svg>',
        'Graphics object' => '<g role="graphics-object"></g>',
        'Graphics symbol' => '<path role="graphics-symbol" aria-label="Warning" />',
    ]);

    it('passes for roles that are deprecated but still valid', function (): void {
        $this->getRuleTester()->run(new NoInvalidRoleRule, [
            'valid' => ['<div role="directory">Index</div>'],
        ]);
    });

    describe('abstract roles', function (): void {
        $lint = function (string $code, array $ruleIds): array {
            $registry = new RuleRegistry;
            $config = Config::make();

            foreach ($ruleIds as $rule) {
                $registry->register($rule);
                $config->setRule($rule->getId(), $rule->getDefaultSeverity()->value);
            }

            return array_map(
                fn ($violation): string => $violation->ruleId,
                (new Linter($registry))->lint($code, 'test.blade.php', $config)->violations
            );
        };

        it('leaves them to a11y-no-abstract-roles when that rule is running', function () use ($lint): void {
            expect($lint('<div role="widget">x</div>', [new NoAbstractRolesRule, new NoInvalidRoleRule]))
                ->toBe(['a11y-no-abstract-roles']);
        });

        it('reports them itself when a11y-no-abstract-roles is not running', function () use ($lint): void {
            expect($lint('<div role="widget">x</div>', [new NoInvalidRoleRule]))
                ->toBe(['a11y-no-invalid-role']);
        });

        it('still reports a genuinely invalid role alongside an abstract one', function () use ($lint): void {
            expect($lint('<div role="widget nonsense">x</div>', [new NoAbstractRolesRule, new NoInvalidRoleRule]))
                ->toBe(['a11y-no-abstract-roles', 'a11y-no-invalid-role']);
        });
    });
});
