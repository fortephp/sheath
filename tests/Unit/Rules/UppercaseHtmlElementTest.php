<?php

declare(strict_types=1);

use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Rules\Accessibility\Content\FormLabelRule;
use Forte\Sheath\Rules\Accessibility\Headings\NoEmptyHeadingsRule;
use Forte\Sheath\Rules\Accessibility\Headings\NoSkipHeadingLevelsRule;
use Forte\Sheath\Rules\Accessibility\Structure\ListSemanticsRule;
use Forte\Sheath\Rules\BestPractices\Markup\NoObsoleteTagsRule;
use Forte\Sheath\Rules\Performance\NoRenderBlockingResourcesRule;
use Forte\Sheath\Rules\Seo\NoMultipleH1Rule;

it('treats HTML element names as ASCII case-insensitive in grouped rules', function (Rule $rule, string $code): void {
    $this->getRuleTester()->run($rule, [
        'invalid' => [[
            'code' => $code,
            'errors' => 1,
        ]],
    ]);
})->with([
    'empty heading' => [new NoEmptyHeadingsRule, '<H1></H1>'],
    'skipped heading level' => [new NoSkipHeadingLevelsRule, '<H1>One</H1><H3>Three</H3>'],
    'multiple h1' => [new NoMultipleH1Rule, '<H1>One</H1><H1>Two</H1>'],
    'unlabelled form control' => [new FormLabelRule, '<INPUT type="text" name="email">'],
    'invalid list item nesting' => [new ListSemanticsRule, '<UL><DIV><LI>Not direct</LI></DIV></UL>'],
    'obsolete tag' => [new NoObsoleteTagsRule, '<CENTER>Legacy</CENTER>'],
    'render-blocking script' => [new NoRenderBlockingResourcesRule, '<HTML><HEAD><SCRIPT src="app.js"></SCRIPT></HEAD></HTML>'],
]);
