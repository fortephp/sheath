<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Rules\Accessibility\Aria\NoAbstractRolesRule;
use Forte\Sheath\Rules\Accessibility\Aria\NoAriaHiddenOnFocusableRule;
use Forte\Sheath\Rules\Accessibility\Aria\NoInvalidRoleRule;
use Forte\Sheath\Rules\Accessibility\Focus\NoAccesskeyRule;
use Forte\Sheath\Rules\Accessibility\Focus\NoAutofocusRule;
use Forte\Sheath\Rules\Accessibility\Focus\NoPositiveTabindexRule;
use Forte\Sheath\Rules\Accessibility\Structure\HtmlLangRule;
use Forte\Sheath\Rules\Accessibility\Structure\NoNonScalableViewportRule;
use Forte\Sheath\Rules\BestPractices\Attributes\ButtonTypeRule;
use Forte\Sheath\Rules\BestPractices\Attributes\NoDuplicateAttrsRule;
use Forte\Sheath\Rules\BestPractices\Attributes\NoDuplicateClassRule;
use Forte\Sheath\Rules\BestPractices\Attributes\NoInlineStylesRule;
use Forte\Sheath\Rules\BestPractices\Documents\RequireDoctypeRule;
use Forte\Sheath\Rules\BestPractices\Markup\NoScriptStyleTypeRule;
use Forte\Sheath\Rules\BestPractices\Markup\SelfClosingVoidElementsRule;
use Forte\Sheath\Rules\Blade\Components\ComponentSelfClosingRule;
use Forte\Sheath\Rules\Blade\Components\ComponentTagIntegrityRule;
use Forte\Sheath\Rules\Blade\Components\PreferComponentTagsRule;
use Forte\Sheath\Rules\Blade\Directives\ForelseEmptyArgumentsRule;
use Forte\Sheath\Rules\Blade\Directives\NoDirectiveSpaceRule;
use Forte\Sheath\Rules\Blade\Directives\NoElseConditionRule;
use Forte\Sheath\Rules\Blade\Directives\PreferEndsectionRule;
use Forte\Sheath\Rules\Blade\Directives\PreferForelseRule;
use Forte\Sheath\Rules\Blade\Echoes\NoPhpEchoRule;
use Forte\Sheath\Rules\Blade\Echoes\NoTripleEchoRule;
use Forte\Sheath\Rules\Blade\Echoes\NoUnquotedEchoAttributeRule;
use Forte\Sheath\Rules\Blade\Helpers\MethodFieldRule;
use Forte\Sheath\Rules\Blade\Helpers\PreferLangHelperRule;
use Forte\Sheath\Rules\Performance\LazyLoadImagesRule;
use Forte\Sheath\Rules\Performance\NoRenderBlockingResourcesRule;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Rules\Security\CsrfFieldRule;
use Forte\Sheath\Rules\Security\NoInlineJsRule;
use Forte\Sheath\Rules\Security\NoTargetBlankRule;
use Forte\Sheath\Rules\Security\PreferHttpsRule;
use Forte\Sheath\Testing\RuleTester;

function fixOutput(Rule $rule, string $code): string
{
    return (new RuleTester)->fix($rule, $code, dangerous: true);
}

/** @return array<string, array{Rule, string, string}> */
function autofixCases(): array
{
    return [
        'a11y-html-lang' => [
            new HtmlLangRule,
            '<html><head></head><body>x</body></html>',
            '<html lang="en"><head></head><body>x</body></html>',
        ],
        'a11y-no-abstract-roles' => [
            new NoAbstractRolesRule,
            '<div class="a" role="widget" data-x="a > b">x</div>',
            '<div class="a" data-x="a > b">x</div>',
        ],
        'a11y-no-accesskey' => [
            new NoAccesskeyRule,
            '<a href="{{ $user->url }}" accesskey="h">Home</a>',
            '<a href="{{ $user->url }}">Home</a>',
        ],
        'a11y-no-aria-hidden-on-focusable' => [
            new NoAriaHiddenOnFocusableRule,
            '<a href="{{ $user->url }}" aria-hidden="true">x</a>',
            '<a href="{{ $user->url }}">x</a>',
        ],
        'a11y-no-autofocus' => [
            new NoAutofocusRule,
            '<input type="text" autofocus value="{{ $user->name }}">',
            '<input type="text" value="{{ $user->name }}">',
        ],
        'a11y-no-invalid-role' => [
            new NoInvalidRoleRule,
            '<div role="totally-made-up" data-x="a > b">x</div>',
            '<div data-x="a > b">x</div>',
        ],
        'a11y-no-non-scalable-viewport' => [
            new NoNonScalableViewportRule,
            '<meta name="viewport" content="width=device-width, user-scalable=no">',
            '<meta name="viewport" content="width=device-width">',
        ],
        'a11y-no-positive-tabindex' => [
            new NoPositiveTabindexRule,
            '<a href="{{ $user->url }}" tabindex="5">x</a>',
            '<a href="{{ $user->url }}" tabindex="0">x</a>',
        ],
        'best-practices-button-type' => [
            new ButtonTypeRule,
            '<button @class([\'btn\' => $active])>Go</button>',
            '<button @class([\'btn\' => $active]) type="button">Go</button>',
        ],
        'best-practices-no-duplicate-attrs' => [
            new NoDuplicateAttrsRule,
            '<div id="a" data-x="a > b" id="b">x</div>',
            '<div id="a" data-x="a > b">x</div>',
        ],
        'best-practices-no-duplicate-class' => [
            new NoDuplicateClassRule,
            '<div class="btn btn primary">x</div>',
            '<div class="btn primary">x</div>',
        ],
        'best-practices-no-inline-styles' => [
            new NoInlineStylesRule,
            '<div class="a" style="color:red" id="b">x</div>',
            '<div class="a" id="b">x</div>',
        ],
        'best-practices-no-script-style-type' => [
            new NoScriptStyleTypeRule,
            '<script type="text/javascript" src="{{ $manifest->js }}"></script>',
            '<script src="{{ $manifest->js }}"></script>',
        ],
        'best-practices-require-doctype' => [
            new RequireDoctypeRule,
            '<html lang="en"><head></head><body>x</body></html>',
            "<!DOCTYPE html>\n<html lang=\"en\"><head></head><body>x</body></html>",
        ],
        'best-practices-self-closing-void-elements' => [
            new SelfClosingVoidElementsRule,
            '<img src="{{ $post->image }}" alt="A" />',
            '<img src="{{ $post->image }}" alt="A">',
        ],
        'blade-component-self-closing' => [
            new ComponentSelfClosingRule,
            '<x-alert :message="$e->getMessage()"></x-alert>',
            '<x-alert :message="$e->getMessage()" />',
        ],
        'blade-component-tag-integrity' => [
            new ComponentTagIntegrityRule,
            '<x-alert type="a > b" :message="{{ $user->fullName() }}" />',
            '<x-alert type="a > b" :message="$user->fullName()" />',
        ],
        'blade-forelse-empty-arguments' => [
            new ForelseEmptyArgumentsRule,
            '@forelse($users as $user)<li>{{ $user->name }}</li>@empty($users)<li>none</li>@endforelse',
            '@forelse($users as $user)<li>{{ $user->name }}</li>@empty<li>none</li>@endforelse',
        ],
        'blade-method-field' => [
            new MethodFieldRule,
            '<form method="DELETE" action="{{ route(\'u.destroy\', [\'id\' => $id]) }}"></form>',
            "<form method=\"POST\" action=\"{{ route('u.destroy', ['id' => \$id]) }}\">\n    @method('DELETE')</form>",
        ],
        'blade-no-directive-space' => [
            new NoDirectiveSpaceRule,
            '<x-input @checked ($user->active) />',
            '<x-input @checked($user->active) />',
        ],
        'blade-no-else-condition' => [
            new NoElseConditionRule,
            "@if(\$a > 1)\na\n@else(\$b > 2)\nb\n@endif",
            "@if(\$a > 1)\na\n@elseif(\$b > 2)\nb\n@endif",
        ],
        'blade-no-php-echo' => [
            new NoPhpEchoRule,
            '@php echo $user->name . " > " . $user->email; @endphp',
            '{!! $user->name . " > " . $user->email !!}',
        ],
        'blade-no-triple-echo' => [
            new NoTripleEchoRule,
            '{{{ $user->name }}}',
            '{{ $user->name }}',
        ],
        'blade-no-unquoted-echo-attribute' => [
            new NoUnquotedEchoAttributeRule,
            '<div class={{ $post->classes }} data-x="a > b">x</div>',
            '<div class="{{ $post->classes }}" data-x="a > b">x</div>',
        ],
        'blade-prefer-component-tags' => [
            new PreferComponentTagsRule,
            "@component('mail::button', ['url' => \$order->url])\nTrack it\n@endcomponent",
            "<x-mail::button :url=\"\$order->url\">\nTrack it\n</x-mail::button>",
        ],
        'blade-prefer-endsection' => [
            new PreferEndsectionRule,
            "@section('content')\n    <p>{{ \$post->title }}</p>\n@stop",
            "@section('content')\n    <p>{{ \$post->title }}</p>\n@endsection",
        ],
        'blade-prefer-forelse' => [
            new PreferForelseRule,
            "@if (count(\$posts) > 0)\n    @foreach (\$posts as \$post)\n        <li>{{ \$post->title }}</li>\n    @endforeach\n@else\n    <p>None.</p>\n@endif",
            "@forelse (\$posts as \$post)\n    <li>{{ \$post->title }}</li>\n@empty\n    <p>None.</p>\n@endforelse",
        ],
        'blade-prefer-lang-helper' => [
            new PreferLangHelperRule,
            "<a href=\"{{ \$user->url }}\">@lang('nav.profile')</a>",
            "<a href=\"{{ \$user->url }}\">{{ __('nav.profile') }}</a>",
        ],
        'perf-lazy-load-images' => [
            new LazyLoadImagesRule,
            '<div><img src="a.png" alt="a"><img src="{{ $post->image }}" alt="b"></div>',
            '<div><img src="a.png" alt="a"><img src="{{ $post->image }}" alt="b" loading="lazy"></div>',
        ],
        'perf-no-render-blocking' => [
            new NoRenderBlockingResourcesRule,
            '<html><head><script src="{{ $manifest->js }}"></script></head><body>x</body></html>',
            '<html><head><script src="{{ $manifest->js }}" defer></script></head><body>x</body></html>',
        ],
        'security-csrf-field' => [
            new CsrfFieldRule,
            '<form method="post" action="{{ route(\'u.store\', [\'id\' => $id]) }}"></form>',
            "<form method=\"post\" action=\"{{ route('u.store', ['id' => \$id]) }}\">\n    @csrf</form>",
        ],
        'security-no-inline-js' => [
            new NoInlineJsRule,
            '<button type="button" onclick="go()" data-x="a > b">Go</button>',
            '<button type="button" data-x="a > b">Go</button>',
        ],
        'security-no-target-blank' => [
            new NoTargetBlankRule,
            '<a href="{{ $post->url }}" target="_blank" rel="opener">Read</a>',
            '<a href="{{ $post->url }}" target="_blank" rel="opener noopener">Read</a>',
        ],
        'security-prefer-https' => [
            new PreferHttpsRule,
            '<a href="http://example.com/a?x=1">x</a>',
            '<a href="https://example.com/a?x=1">x</a>',
        ],
    ];
}

describe('autofix output', function (): void {
    it('produces the expected output', function (Rule $rule, string $input, string $expected): void {
        expect(fixOutput($rule, $input))->toBe($expected);
    })->with(autofixCases());

    it('is idempotent: a second pass changes nothing', function (Rule $rule, string $input, string $expected): void {
        expect(fixOutput($rule, $expected))->toBe($expected);
    })->with(autofixCases());

    it('never introduces a parse error', function (Rule $rule, string $input, string $expected): void {
        $before = count(Document::parse($input)->diagnostics()->errors());
        $after = count(Document::parse(fixOutput($rule, $input))->diagnostics()->errors());

        expect($after)->toBeLessThanOrEqual($before);
    })->with(autofixCases());

    it('covers every rule that can emit a fix', function (): void {
        $registry = new RuleRegistry;
        $registry->discoverRules(__DIR__.'/../../../src/Rules');

        $emitsFix = [];
        foreach ($registry->all() as $ruleId) {
            $source = (string) file_get_contents(
                (new ReflectionClass($registry->get($ruleId)))->getFileName() ?: ''
            );

            if (preg_match('/create(Add|Insert|Remove|Replace|SelfClosing|CollapseTo)\w*Fix|new Fix\(|Fix::(dangerous|fromNode)/', $source)) {
                $emitsFix[] = $ruleId;
            }
        }

        $covered = array_keys(autofixCases());

        expect(array_values(array_diff($emitsFix, $covered)))->toBe([]);
    });
});
