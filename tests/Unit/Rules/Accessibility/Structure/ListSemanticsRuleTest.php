<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Structure\ListSemanticsRule;

describe('ListSemanticsRule', function (): void {
    it('passes for li inside list containers', function (string $code): void {
        $this->getRuleTester()->run(new ListSemanticsRule, ['valid' => [$code]]);
    })->with([
        'ul container' => '<ul><li>Item 1</li><li>Item 2</li></ul>',
        'ol container' => '<ol><li>First</li><li>Second</li></ol>',
        'menu container' => '<menu><li>Action 1</li><li>Action 2</li></menu>',
    ]);

    it('passes for dt/dd inside dl', function (string $code): void {
        $this->getRuleTester()->run(new ListSemanticsRule, ['valid' => [$code]]);
    })->with([
        'direct children' => '<dl><dt>Term</dt><dd>Definition</dd></dl>',
        'inside div within dl' => '<dl><div><dt>Term</dt><dd>Definition</dd></div></dl>',
        'multiple div groups' => '<dl><div><dt>Term 1</dt><dd>Def 1</dd></div><div><dt>Term 2</dt><dd>Def 2</dd></div></dl>',
        'mixed direct and grouped' => '<dl><dt>Direct Term</dt><dd>Direct Def</dd><div><dt>Grouped Term</dt><dd>Grouped Def</dd></div></dl>',
    ]);

    it('passes for nested lists', function (string $code): void {
        $this->getRuleTester()->run(new ListSemanticsRule, ['valid' => [$code]]);
    })->with([
        'nested ul' => '<ul><li>Item<ul><li>Nested</li></ul></li></ul>',
        'nested ol' => '<ol><li>Item<ol><li>Nested</li></ol></li></ol>',
    ]);

    it('passes for list items with various content types', function (string $code): void {
        $this->getRuleTester()->run(new ListSemanticsRule, ['valid' => [$code]]);
    })->with([
        'multiple li in ul' => '<ul><li>One</li><li>Two</li><li>Three</li></ul>',
        'li with complex content' => '<ul><li><span>Span</span> text</li><li><a href="#">Link</a></li></ul>',
        'ol with multiple li' => '<ol><li>First</li><li>Second</li><li>Third</li></ol>',
        'menu with buttons' => '<menu><li><button>Action 1</button></li><li><button>Action 2</button></li></menu>',
        'li with whitespace between' => '<ul><li>First</li>  <li>Second</li></ul>',
    ]);

    it('passes for a list item that is the whole fragment', function (string $code): void {
        $this->getRuleTester()->run(new ListSemanticsRule, ['valid' => [$code]]);
    })->with([
        'li component' => "@props(['href'])\n<li><a href=\"{{ \$href }}\">{{ \$slot }}</a></li>",
        'dt and dd component' => "<dt>{{ \$term }}</dt>\n<dd>{{ \$slot }}</dd>",
        'li in a loop' => "@foreach (\$items as \$item)\n<li>{{ \$item }}</li>\n@endforeach",
    ]);

    it('fails for list elements outside proper containers', function (string $code): void {
        $this->getRuleTester()->run(new ListSemanticsRule, [
            'invalid' => [['code' => $code, 'errors' => 1]],
        ]);
    })->with([
        'li in div' => [
            '<div><li>Orphan item</li></div>',
        ],
        'dt in div' => [
            '<div><dt>Orphan term</dt></div>',
        ],
        'dd in div' => [
            '<div><dd>Orphan definition</dd></div>',
        ],
        'li in nav div' => [
            '<nav><div class="menu"><li>Item</li></div></nav>',
        ],
        'li in span' => [
            '<span><li>Bad item</li></span>',
        ],
    ]);

    it('reports dt/dd in deeply nested invalid structures', function (): void {
        $this->getRuleTester()->run(new ListSemanticsRule, [
            'invalid' => [[
                'code' => '<article><div class="terms"><dt>Term</dt><dd>Def</dd></div></article>',
                'errors' => 2,
            ]],
        ]);
    });
});
