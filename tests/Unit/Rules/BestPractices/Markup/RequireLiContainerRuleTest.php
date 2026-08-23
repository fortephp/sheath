<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Accessibility\Structure\ListSemanticsRule;
use Forte\Sheath\Rules\BestPractices\Markup\RequireLiContainerRule;
use Forte\Sheath\Rules\RuleRegistry;

describe('RequireLiContainerRule', function (): void {
    it('passes for li inside ul', function (): void {
        $this->getRuleTester()->run(new RequireLiContainerRule, [
            'valid' => [
                '<ul><li>Item</li></ul>',
                '<ul><li>Item 1</li><li>Item 2</li></ul>',
            ],
        ]);
    });

    it('passes for li inside ol', function (): void {
        $this->getRuleTester()->run(new RequireLiContainerRule, [
            'valid' => [
                '<ol><li>First</li><li>Second</li></ol>',
            ],
        ]);
    });

    it('passes for li inside menu', function (): void {
        $this->getRuleTester()->run(new RequireLiContainerRule, [
            'valid' => [
                '<menu><li>Action</li></menu>',
            ],
        ]);
    });

    it('fails for li outside valid container', function (): void {
        $this->getRuleTester()->run(new RequireLiContainerRule, [
            'invalid' => [
                [
                    'code' => '<div><li>Orphan item</li></div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('passes for li as the root of a fragment', function (): void {
        $this->getRuleTester()->run(new RequireLiContainerRule, [
            'valid' => [
                '<li>Standalone item</li>',
                "@props(['href'])\n<li><a href=\"{{ \$href }}\">{{ \$slot }}</a></li>",
                "@foreach (\$items as \$item)\n<li>{{ \$item }}</li>\n@endforeach",
            ],
        ]);
    });

    it('does not cross a non-output Blade capture boundary', function (): void {
        $this->getRuleTester()->run(new RequireLiContainerRule, [
            'valid' => [
                '<div>@section("slot")<li>Item</li>@endsection</div>',
                '<div>@push("slot")<li>Item</li>@endpush</div>',
                '<div>@pushif($enabled, "slot")<li>Item</li>@endpushif</div>',
            ],
            'invalid' => [[
                'code' => '<div>@section("slot")<li>Item</li>@show</div>',
                'errors' => 1,
            ]],
        ]);
    });

    it('detects multiple invalid li elements', function (): void {
        $this->getRuleTester()->run(new RequireLiContainerRule, [
            'invalid' => [
                [
                    'code' => '<div><li>One</li><li>Two</li></div>',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    describe('deference to a11y-list-semantics', function (): void {
        $lint = function (string $code, array $rules): array {
            $registry = new RuleRegistry;
            $config = Config::make();

            foreach ($rules as $rule) {
                $registry->register($rule);
                $config->setRule($rule->getId(), $rule->getDefaultSeverity()->value);
            }

            return array_map(
                fn ($violation): string => $violation->ruleId,
                (new Linter($registry))->lint($code, 'test.blade.php', $config)->violations
            );
        };

        it('stands down when a11y-list-semantics is running', function () use ($lint): void {
            expect($lint('<div><li>Orphan</li></div>', [new ListSemanticsRule, new RequireLiContainerRule]))
                ->toBe(['a11y-list-semantics']);
        });

        it('reports itself when a11y-list-semantics is not running', function () use ($lint): void {
            expect($lint('<div><li>Orphan</li></div>', [new RequireLiContainerRule]))
                ->toBe(['best-practices-require-li-container']);
        });
    });
});
