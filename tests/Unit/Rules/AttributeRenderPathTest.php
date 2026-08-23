<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Aria\NoAbstractRolesRule;
use Forte\Sheath\Rules\Accessibility\Aria\NoAriaHiddenOnFocusableRule;
use Forte\Sheath\Rules\Accessibility\Aria\NoInvalidRoleRule;
use Forte\Sheath\Rules\Accessibility\Content\AnchorContentRule;
use Forte\Sheath\Rules\Accessibility\Content\ButtonAccessibleNameRule;
use Forte\Sheath\Rules\Accessibility\Content\FormLabelRule;
use Forte\Sheath\Rules\Accessibility\Content\ImgAltTextRule;
use Forte\Sheath\Rules\Accessibility\Content\RequireFrameTitleRule;
use Forte\Sheath\Rules\Accessibility\Focus\NoAccesskeyRule;
use Forte\Sheath\Rules\Accessibility\Focus\NoAutofocusRule;
use Forte\Sheath\Rules\Accessibility\Focus\NoPositiveTabindexRule;
use Forte\Sheath\Rules\Accessibility\Structure\HtmlLangRule;
use Forte\Sheath\Rules\BestPractices\Attributes\ButtonTypeRule;
use Forte\Sheath\Rules\BestPractices\Attributes\NoInlineStylesRule;
use Forte\Sheath\Rules\BestPractices\Attributes\RequireFormMethodRule;
use Forte\Sheath\Rules\Performance\LazyLoadImagesRule;
use Forte\Sheath\Rules\Performance\NoRenderBlockingResourcesRule;
use Forte\Sheath\Rules\Performance\RequireExplicitSizeRule;
use Forte\Sheath\Rules\Performance\ResponsiveImagesRule;
use Forte\Sheath\Rules\Security\NoInlineJsRule;
use Forte\Sheath\Rules\Security\NoTargetBlankRule;
use Forte\Sheath\Rules\Security\PreferHttpsRule;

function legacyConditionalTargetBlankRule(): NoTargetBlankRule
{
    $rule = new NoTargetBlankRule;
    $rule->setOptions(['requireExplicitNoopener' => true]);

    return $rule;
}

function allImagesMustBeLazyRule(): LazyLoadImagesRule
{
    $rule = new LazyLoadImagesRule;
    $rule->setOptions(['skipAboveFold' => false]);

    return $rule;
}

describe('attributes across Blade render paths', function (): void {
    it('reports when a required attribute exists on only some render paths', function (object $rule, string $code): void {
        $this->getRuleTester()->run($rule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'image alt' => [
            new ImgAltTextRule,
            '<img src="x.jpg" @if($described) alt="Chart" @endif>',
        ],
        'frame title' => [
            new RequireFrameTitleRule,
            '<iframe @if($titled) title="Map" @endif></iframe>',
        ],
        'html language' => [
            new HtmlLangRule,
            '<html @if($localized) lang="en" @endif><body></body></html>',
        ],
        'button type' => [
            new ButtonTypeRule,
            '<button @if($submits) type="submit" @endif>Go</button>',
        ],
        'form method' => [
            new RequireFormMethodRule,
            '<form @if($posts) method="post" @endif></form>',
        ],
        'anchor name' => [
            new AnchorContentRule,
            '<a href="/help" @if($labelled) aria-label="Help" @endif><svg aria-hidden="true"></svg></a>',
        ],
        'button name' => [
            new ButtonAccessibleNameRule,
            '<button type="button" @if($labelled) aria-label="Menu" @endif><svg aria-hidden="true"></svg></button>',
        ],
        'form control label' => [
            new FormLabelRule,
            '<input type="text" @if($labelled) aria-label="Query" @endif>',
        ],
        'explicit image dimensions' => [
            new RequireExplicitSizeRule,
            '<img src="x.jpg" @if($sized) width="640" height="480" @endif>',
        ],
        'responsive source set' => [
            new ResponsiveImagesRule,
            '<img src="large.jpg" width="640" height="480" alt="Chart" @if($responsive) srcset="large.jpg 1x" @endif>',
        ],
        'lazy loading' => [
            allImagesMustBeLazyRule(),
            '<img src="below-fold.jpg" alt="Chart" @if($lazy) loading="lazy" @endif>',
        ],
        'non-blocking script' => [
            new NoRenderBlockingResourcesRule,
            '<html><head><script src="app.js" @if($deferred) defer @endif></script></head><body></body></html>',
        ],
        'legacy noopener' => [
            legacyConditionalTargetBlankRule(),
            '<a href="https://example.test" target="_blank" @if($safe) rel="noopener" @endif>Docs</a>',
        ],
    ]);

    it('accepts required attributes supplied on every known branch or by an opaque provider', function (object $rule, string $code): void {
        $this->getRuleTester()->run($rule, ['valid' => [$code]]);
    })->with([
        'button type in both branches' => [
            new ButtonTypeRule,
            '<button @if($submits) type="submit" @else type="button" @endif>Go</button>',
        ],
        'image alt in both branches' => [
            new ImgAltTextRule,
            '<img src="x.jpg" @if($decorative) alt="" @else alt="Chart" @endif>',
        ],
        'attribute bag in one branch and explicit value in the other' => [
            new RequireFormMethodRule,
            '<form @if($custom) {{ $attributes }} @else method="post" @endif></form>',
        ],
    ]);

    it('validates attributes that are present only inside a conditional branch', function (object $rule, string $code): void {
        $this->getRuleTester()->run($rule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'accesskey' => [
            new NoAccesskeyRule,
            '<button @if($shortcut) accesskey="s" @endif>Save</button>',
        ],
        'autofocus' => [
            new NoAutofocusRule,
            '<input aria-label="Query" @if($focus) autofocus @endif>',
        ],
        'positive tabindex' => [
            new NoPositiveTabindexRule,
            '<div @if($prioritized) tabindex="2" @endif>Panel</div>',
        ],
        'inline style' => [
            new NoInlineStylesRule,
            '<div @if($red) style="color: red" @endif>Alert</div>',
        ],
        'inline JavaScript' => [
            new NoInlineJsRule,
            '<button @if($legacy) onclick="save()" @endif>Save</button>',
        ],
        'insecure URL' => [
            new PreferHttpsRule,
            '<a @if($legacy) href="http://example.test" @else href="https://example.test" @endif>Docs</a>',
        ],
        'abstract role' => [
            new NoAbstractRolesRule,
            '<div @if($widget) role="widget" @endif>Control</div>',
        ],
        'invalid role' => [
            new NoInvalidRoleRule,
            '<div @if($special) role="not-a-role" @endif>Content</div>',
        ],
        'explicit opener' => [
            new NoTargetBlankRule,
            '<a target="_blank" @if($unsafe) rel="opener" @endif>Docs</a>',
        ],
    ]);

    it('correlates repeated and complementary simple predicates across separate blocks', function (object $rule, string $code): void {
        $this->getRuleTester()->run($rule, ['valid' => [$code]]);
    })->with([
        'button type complements' => [
            new ButtonTypeRule,
            '<button @if($x) type="button" @endif @if(!$x) type="submit" @endif>Go</button>',
        ],
        'form method unless complement' => [
            new RequireFormMethodRule,
            '<form @if($x) method="get" @endif @unless($x) method="post" @endunless></form>',
        ],
        'image alt complements' => [
            new ImgAltTextRule,
            '<img @if($x) alt="A" @endif @if(!$x) alt="B" @endif>',
        ],
        'html language complements' => [
            new HtmlLangRule,
            '<html @if($x) lang="en" @endif @if(!$x) lang="fr" @endif></html>',
        ],
        'aria hidden and disabled co-vary' => [
            new NoAriaHiddenOnFocusableRule,
            '<button @if($x) aria-hidden="true" @endif @if($x) disabled @endif>Save</button>',
        ],
        'script applicability and satisfaction share one path' => [
            new NoRenderBlockingResourcesRule,
            '<head><script src="a.js" @if($x) type="text/plain" @else defer @endif></script></head>',
        ],
        'image loading exemptions share one path' => [
            allImagesMustBeLazyRule(),
            '<img src="x.jpg" @if($x) loading="lazy" @else fetchpriority="high" @endif>',
        ],
        'svg exemption and bitmap dimensions share one path' => [
            new RequireExplicitSizeRule,
            '<img @if($x) src="icon.svg" @else src="photo.jpg" width="100" height="100" @endif>',
        ],
    ]);

    it('does not fail open when the explicit path bound is exceeded', function (): void {
        $conditionals = '';
        foreach (range(1, 8) as $index) {
            $conditionals .= " @if(\$p{$index}) method=\"get\" @endif";
        }

        $this->getRuleTester()->run(new RequireFormMethodRule, [
            'invalid' => [[
                'code' => "<form{$conditionals}></form>",
                'errors' => 1,
            ]],
        ]);
    });

    it('analyzes switch arms instead of treating control directives as opaque', function (): void {
        $this->getRuleTester()->run(new NoPositiveTabindexRule, [
            'invalid' => [[
                'code' => '<div @switch($x) @case(1) tabindex="2" @break @default tabindex="3" @endswitch></div>',
                'errors' => 1,
            ]],
        ]);

        $this->getRuleTester()->run(new ButtonTypeRule, [
            'invalid' => [[
                'code' => '<button @switch($x) @case(1) @if($stop) @break @endif @case(2) type="button" @break @default type="button" @endswitch>Go</button>',
                'errors' => 1,
            ]],
        ]);
    });

    it('honors first-attribute ordering around opaque providers', function (): void {
        $this->getRuleTester()->run(new ButtonTypeRule, [
            'valid' => ['<button {{ $attributes }} type="bogus">Go</button>'],
            'invalid' => [[
                'code' => '<button type="bogus" {{ $attributes }}>Go</button>',
                'errors' => 1,
            ]],
        ]);

        $this->getRuleTester()->run(new RequireFormMethodRule, [
            'valid' => ['<form {{ $attributes }} method="bogus"></form>'],
            'invalid' => [[
                'code' => '<form method="bogus" {{ $attributes }}></form>',
                'errors' => 1,
            ]],
        ]);
    });

    it('does not treat known non-attribute directives as wildcard providers', function (): void {
        $this->getRuleTester()->run(new ButtonTypeRule, [
            'invalid' => [[
                'code' => '<button @csrf>Go</button>',
                'errors' => 1,
            ]],
        ]);
    });
});
