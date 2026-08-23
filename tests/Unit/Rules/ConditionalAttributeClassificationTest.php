<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Aria\NoAriaHiddenOnFocusableRule;
use Forte\Sheath\Rules\Accessibility\Content\AnchorContentRule;
use Forte\Sheath\Rules\Accessibility\Headings\NoHeadingInsideButtonRule;
use Forte\Sheath\Rules\Accessibility\Structure\TableHeadersRule;
use Forte\Sheath\Rules\BestPractices\Attributes\NoDuplicateClassRule;
use Forte\Sheath\Rules\BestPractices\Markup\NoNestedInteractiveRule;
use Forte\Sheath\Rules\Blade\Helpers\PreferJsonInScriptRule;
use Forte\Sheath\Rules\Performance\NoRenderBlockingResourcesRule;
use Forte\Sheath\Rules\Seo\RequireOpenGraphRule;

function tableScopeOnEveryPathRule(): TableHeadersRule
{
    $rule = new TableHeadersRule;
    $rule->setOptions(['requireScope' => true]);

    return $rule;
}

describe('classification attributes in Blade branches', function (): void {
    it('recognizes elements that become interactive or hidden on a known branch', function (object $rule, string $code): void {
        $this->getRuleTester()->run($rule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'conditionally linked empty anchor' => [
            new AnchorContentRule,
            '<a @if($linked) href="/settings" @endif><svg aria-hidden="true"></svg></a>',
        ],
        'conditionally linked nested anchor' => [
            new NoNestedInteractiveRule,
            '<button><a @if($linked) href="/settings" @endif>Settings</a></button>',
        ],
        'conditionally linked ancestor' => [
            new NoNestedInteractiveRule,
            '<a @if($linked) href="/settings" @endif><button>Settings</button></a>',
        ],
        'conditional button role' => [
            new NoHeadingInsideButtonRule,
            '<div @if($acts) role="button" @endif><h2>Action</h2></div>',
        ],
        'conditional aria-hidden' => [
            new NoAriaHiddenOnFocusableRule,
            '<button @if($hidden) aria-hidden="true" @endif>Action</button>',
        ],
        'conditional focusability' => [
            new NoAriaHiddenOnFocusableRule,
            '<div aria-hidden="true" @if($focusable) tabindex="0" @endif>Panel</div>',
        ],
        'conditional media controls' => [
            new NoAriaHiddenOnFocusableRule,
            '<video aria-hidden="true" @if($interactive) controls @endif></video>',
        ],
    ]);

    it('validates conditional values that classify metadata and attributes', function (object $rule, string $code): void {
        $this->getRuleTester()->run($rule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'table scope on one path' => [
            tableScopeOnEveryPathRule(),
            '<table><tr><th @if($scoped) scope="col" @endif>Name</th></tr><tr><td>Ada</td></tr></table>',
        ],
        'duplicate conditional class token' => [
            new NoDuplicateClassRule,
            '<div @if($active) class="active active" @endif></div>',
        ],
        'non-JavaScript type on one path' => [
            new PreferJsonInScriptRule,
            '<script @if($template) type="text/x-template" @endif>{{ $payload }}</script>',
        ],
        'deferred non-JavaScript type on one path' => [
            new NoRenderBlockingResourcesRule,
            '<html><head><script src="app.js" @if($template) type="text/x-template" @endif></script></head><body></body></html>',
        ],
        'Open Graph content on one path' => [
            new RequireOpenGraphRule,
            '<html><head><meta property="og:title" @if($titled) content="Title" @endif><meta property="og:type" content="website"><meta property="og:image" content="image.jpg"><meta property="og:url" content="https://example.test"></head><body></body></html>',
        ],
    ]);

    it('accepts non-JavaScript script types on every branch', function (object $rule, string $code): void {
        $this->getRuleTester()->run($rule, ['valid' => [$code]]);
    })->with([
        'escaped echo rule' => [
            new PreferJsonInScriptRule,
            '<script @if($a) type="text/x-template" @else type="text/plain" @endif>{{ $payload }}</script>',
        ],
        'render-blocking rule' => [
            new NoRenderBlockingResourcesRule,
            '<html><head><script src="template.js" @if($a) type="text/x-template" @else type="text/plain" @endif></script></head><body></body></html>',
        ],
    ]);

    it('does not combine focusability and aria-hidden from mutually exclusive attribute branches', function (): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, [
            'valid' => [
                '<div @if($hidden) aria-hidden="true" @else tabindex="0" @endif>Panel</div>',
                '<button @if($hidden) aria-hidden="true" tabindex="-1" @endif>Action</button>',
            ],
        ]);
    });
});
