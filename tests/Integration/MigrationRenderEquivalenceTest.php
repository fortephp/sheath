<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Blade\Components\PreferComponentTagsRule;
use Forte\Sheath\Rules\Blade\Directives\PreferEndsectionRule;
use Forte\Sheath\Rules\Blade\Echoes\NoPhpEchoRule;
use Forte\Sheath\Rules\Blade\Helpers\PreferLangHelperRule;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Testing\RuleTester;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\View;

function componentSandbox(): TestViewSandbox
{
    $sandbox = TestViewSandbox::make('render-equivalence-');

    $sandbox->file('components/alert.blade.php', <<<'BLADE'
    @props(['level' => 'info', 'dismissible' => false])
    <div class="alert alert-{{ $level }}" data-dismissible="{{ var_export($dismissible, true) }}">{{ $slot }}</div>
    BLADE);

    $sandbox->file('components/classy.blade.php', <<<'BLADE'
    @props(['level' => 'info'])
    <div class="alert alert-{{ $level }}">{{ $slot }}</div>
    BLADE);

    $sandbox->file('components/declared.blade.php', <<<'BLADE'
    @props(['level' => null])
    <div {{ $attributes }}>level={{ $level ?? 'UNDEFINED' }}</div>
    BLADE);

    $sandbox->file('components/undeclared.blade.php', <<<'BLADE'
    <div {{ $attributes ?? '' }}>level={{ $level ?? 'UNDEFINED' }}</div>
    BLADE);

    $sandbox->file('components/panel.blade.php', <<<'BLADE'
    @props(['title'])
    <section><h2>{{ $title }}</h2>{{ $slot }}</section>
    BLADE);

    $sandbox->file('components/forms/input.blade.php', <<<'BLADE'
    @props(['name'])
    <input name="{{ $name }}">
    BLADE);

    $sandbox->file('mail/message.blade.php', <<<'BLADE'
    @props(['color' => 'blue'])
    <table class="{{ $color }}">{{ $slot }}</table>
    BLADE);

    return $sandbox;
}

/** @param array<string, mixed> $data */
function renderBlade(string $template, string $root, array $data = []): string
{
    $name = 'render_'.md5($template.microtime(true));
    file_put_contents($root."/{$name}.blade.php", $template);

    try {
        return trim((string) View::make($name, $data)->render());
    } finally {
        @unlink($root."/{$name}.blade.php");
    }
}

function migrationRewrite(Rule $rule, string $template): ?string
{
    $fixed = (new RuleTester)->fix($rule, $template, dangerous: true);

    return $fixed === $template ? null : $fixed;
}

function componentTagFix(string $template): ?string
{
    return migrationRewrite(new PreferComponentTagsRule, $template);
}

/** @param array<string, mixed> $data */
function expectSameRender(Rule $rule, string $template, string $root, array $data = []): void
{
    $rewritten = migrationRewrite($rule, $template);

    expect($rewritten)->not->toBeNull("The rule offered no fix for:\n{$template}");
    expect($rewritten)->not->toBe($template, 'The rule changed nothing');

    expect(renderBlade((string) $rewritten, $root, $data))->toBe(renderBlade($template, $root, $data));
}

beforeEach(function (): void {
    $this->viewSandbox = componentSandbox();
    $this->compiledSandbox = TestViewSandbox::make('render-equivalence-cache-');

    $this->sandbox = $this->viewSandbox->root;

    config()->set('view.paths', [$this->sandbox]);
    config()->set('view.compiled', $this->compiledSandbox->root);

    View::addNamespace('mail', $this->sandbox.'/mail');
});

afterEach(function (): void {
    $this->viewSandbox->cleanup();
    $this->compiledSandbox->cleanup();
});

describe('the rewrite renders what it replaced', function (): void {
    it('renders identically for every template the rule will fix', function (string $template): void {
        $fixed = componentTagFix($template);

        expect($fixed)->not->toBeNull('The rule offered no fix for this template')
            ->and(renderBlade((string) $fixed, $this->sandbox))
            ->toBe(renderBlade($template, $this->sandbox));
    })->with([
        'no data' => ["@component('components.alert')\nBody\n@endcomponent"],
        'a string value' => ["@component('components.alert', ['level' => 'error'])\nBody\n@endcomponent"],
        'a bound value' => ["@php \$l = 'warn'; @endphp\n@component('components.alert', ['level' => \$l])\nBody\n@endcomponent"],
        'a boolean value' => ["@component('components.alert', ['dismissible' => true])\nBody\n@endcomponent"],
        'a nested directory' => ["@component('components.forms.input', ['name' => 'email'])\n@endcomponent"],
        'a slot' => ["@component('components.panel')\n@slot('title')\nHeading\n@endslot\nBody\n@endcomponent"],
        'a namespaced mail component' => ["@component('mail::message', ['color' => 'red'])\nBody\n@endcomponent"],
        'nested components' => ["@component('components.panel')\n@slot('title')\nT\n@endslot\n@component('components.alert', ['level' => 'error'])\nInner\n@endcomponent\n@endcomponent"],
        'an expression with quotes' => ["@component('components.alert', ['level' => strtolower(\"ERROR\")])\nBody\n@endcomponent"],
    ]);
});

describe('what the rule refuses to rewrite', function (): void {
    it('leaves a bare view name alone, because the two forms resolve differently', function (): void {
        expect(componentTagFix("@component('alert')\nBody\n@endcomponent"))->toBeNull();
    });

    it('leaves a namespace other than mail alone, because the tag form needs a component namespace', function (): void {
        expect(componentTagFix("@component('admin::alert')\nBody\n@endcomponent"))->toBeNull();
    });

    it('would in fact fail to render, which is why that one is left alone', function (): void {
        View::addNamespace('admin', $this->sandbox.'/components');

        expect(renderBlade("@component('admin::alert')\nBody\n@endcomponent", $this->sandbox))
            ->toContain('alert-info');

        expect(fn () => renderBlade("<x-admin::alert>\nBody\n</x-admin::alert>", $this->sandbox))
            ->toThrow(InvalidArgumentException::class);
    });
});

describe('why every component-tag rewrite is a dangerous fix', function (): void {
    it('still defines the variable when the component declares the prop', function (): void {
        expect(renderBlade('<x-declared level="error"></x-declared>', $this->sandbox))
            ->toBe(renderBlade("@component('components.declared', ['level' => 'error'])@endcomponent", $this->sandbox));
    });

    it('still defines the variable even when the component does not declare it', function (): void {
        expect(renderBlade('<x-undeclared level="error"></x-undeclared>', $this->sandbox))
            ->toContain('level=error');
    });

    it('leaks the undeclared value into the attribute bag, which the page renders', function (): void {
        $directive = renderBlade("@component('components.undeclared', ['level' => 'error'])@endcomponent", $this->sandbox);
        $tag = renderBlade('<x-undeclared level="error"></x-undeclared>', $this->sandbox);

        expect($directive)->not->toContain('level="error"')
            ->and($tag)->toContain('level="error"')
            ->and($tag)->not->toBe($directive);
    });

    it('resolves to a class-based component where the directive rendered the view', function (): void {
        $namespace = app()->getNamespace();
        $class = $namespace.'View\\Components\\Classy';

        if (! class_exists($class)) {
            eval('namespace '.trim($namespace, '\\').'\\View\\Components; class Classy extends \\Illuminate\\View\\Component {
                public function __construct(public string $level = "from-class") {}
                public function render() { return "<b>class:{$this->level}</b>"; }
            }');
        }

        expect(class_exists($class))->toBeTrue();

        $directiveWithoutData = renderBlade("@component('components.classy')\nBody\n@endcomponent", $this->sandbox);
        $tagWithoutData = renderBlade('<x-classy>Body</x-classy>', $this->sandbox);
        $directiveWithData = renderBlade("@component('components.classy', ['level' => 'error'])\nBody\n@endcomponent", $this->sandbox);
        $tagWithData = renderBlade('<x-classy level="error">Body</x-classy>', $this->sandbox);

        expect($directiveWithoutData)->toContain('alert-info')
            ->and($tagWithoutData)->toContain('class:from-class')
            ->and($tagWithoutData)->not->toBe($directiveWithoutData)
            ->and($directiveWithData)->toContain('alert-error')
            ->and($tagWithData)->toContain('class:error')
            ->and($tagWithData)->not->toBe($directiveWithData);
    });

    it('marks every fix dangerous because class resolution is application-dependent', function (): void {
        $registry = new RuleRegistry;
        $registry->register($rule = new PreferComponentTagsRule);

        $config = Config::make();
        $config->setRule($rule->getId(), ['severity' => 'info', 'options' => []]);

        $dangerousFor = function (string $template) use ($registry, $config): ?bool {
            foreach ((new Linter($registry))->lint($template, 't.blade.php', $config)->violations as $violation) {
                if ($violation->fix !== null) {
                    return $violation->fix->dangerous;
                }
            }

            return null;
        };

        expect($dangerousFor("@component('components.alert')\nx\n@endcomponent"))->toBeTrue()
            ->and($dangerousFor("@component('components.alert', [])\nx\n@endcomponent"))->toBeTrue()
            ->and($dangerousFor("@component('components.alert', ['level' => 'x'])\nx\n@endcomponent"))->toBeTrue();
    });
});

describe('blade-no-php-echo renders what it replaced', function (): void {
    it('produces the same bytes for every rewrite it makes', function (string $template, array $data): void {
        expectSameRender(new NoPhpEchoRule, $template, $this->sandbox, $data);
    })->with([
        'a plain variable' => ['@php echo $v; @endphp', ['v' => 'plain']],
        'markup in the value' => ['@php echo $v; @endphp', ['v' => '<b>bold</b>']],
        'characters that would be escaped' => ['@php echo $v; @endphp', ['v' => '<>&"\'']],
        'a property access' => ['@php echo $o->name; @endphp', ['o' => (object) ['name' => '<i>x</i>']]],
        'a call' => ['@php echo strtoupper($v); @endphp', ['v' => 'abc']],
        'a concatenation' => ["@php echo \$v . '-' . \$v; @endphp", ['v' => 'a']],
        'across lines' => ["@php\n    echo \$v;\n@endphp", ['v' => 'x']],
    ]);

    it('keeps the output raw, which is what @php echo already did', function (): void {
        $before = '@php echo $v; @endphp';
        $data = ['v' => '<b>hi</b>'];

        expect(renderBlade($before, $this->sandbox, $data))->toContain('<b>hi</b>');
        expect(renderBlade((string) migrationRewrite(new NoPhpEchoRule, $before), $this->sandbox, $data))
            ->toContain('<b>hi</b>')
            ->not->toContain('&lt;b&gt;');
    });

    it('would have changed the page had it rewritten to the escaped form', function (): void {
        expect(renderBlade('{{ $v }}', $this->sandbox, ['v' => '<b>hi</b>']))
            ->not->toBe(renderBlade('@php echo $v; @endphp', $this->sandbox, ['v' => '<b>hi</b>']));
    });
});

describe('blade-prefer-endsection renders what it replaced', function (): void {
    beforeEach(function (): void {
        file_put_contents($this->sandbox.'/layout.blade.php', '<main>@yield("content")</main>');
    });

    it('renders the same page through a layout', function (): void {
        expectSameRender(
            new PreferEndsectionRule,
            "@extends('layout')\n@section('content')\n<p>Body</p>\n@stop",
            $this->sandbox
        );
    });

    it('is not confused into rewriting @show, which renders in place', function (): void {
        $shown = renderBlade("@section('a')\n<p>Visible</p>\n@show", $this->sandbox);

        expect($shown)->toContain('Visible')
            ->and(migrationRewrite(new PreferEndsectionRule, "@section('a')\n<p>Visible</p>\n@show"))->toBeNull();

        expect(renderBlade("@section('a')\n<p>Visible</p>\n@endsection", $this->sandbox))
            ->not->toContain('Visible');
    });
});

describe('blade-prefer-lang-helper changes escaping, on purpose', function (): void {
    beforeEach(function (): void {
        Lang::addLines([
            'test.plain' => 'Welcome back',
            'test.markup' => 'Read the <a href="/terms">terms</a>',
            'test.replace' => 'Hello :name',
        ], 'en');
    });

    it('renders identically when the translation holds no markup', function (string $template): void {
        expectSameRender(new PreferLangHelperRule, $template, $this->sandbox);
    })->with([
        'a key' => ["@lang('test.plain')"],
        'a key with replacements' => ["@lang('test.replace', ['name' => 'Ada'])"],
    ]);

    it('escapes a translation that used to reach the page as markup', function (): void {
        $before = "@lang('test.markup')";
        $after = (string) migrationRewrite(new PreferLangHelperRule, $before);

        expect(renderBlade($before, $this->sandbox))->toContain('<a href="/terms">');
        expect(renderBlade($after, $this->sandbox))
            ->toContain('&lt;a href=')
            ->not->toContain('<a href="/terms">');
    });

    it('keeps the original output when the raw form is chosen instead', function (): void {
        expect(renderBlade("{!! __('test.markup') !!}", $this->sandbox))
            ->toBe(renderBlade("@lang('test.markup')", $this->sandbox));
    });
});
