<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Configuration\RulePreset;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Fixer;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Blade\Components\PreferComponentTagsRule;
use Forte\Sheath\Rules\Blade\Directives\PreferEndsectionRule;
use Forte\Sheath\Rules\Blade\Echoes\NoPhpEchoRule;
use Forte\Sheath\Rules\Blade\Helpers\PreferLangHelperRule;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Testing\RuleTester;

function migrationFixes(Rule $rule, string $code): array
{
    $registry = new RuleRegistry;
    $registry->register($rule);

    $config = Config::make();
    $config->setRule($rule->getId(), [
        'severity' => $rule->getDefaultSeverity()->value,
        'options' => $rule->getOptions(),
    ]);

    $result = (new Linter($registry))->lint($code, 'test.blade.php', $config);

    $fixes = [];
    foreach ($result->violations as $violation) {
        if ($violation->fix !== null) {
            $fixes[] = $violation->fix;
        }
    }

    return [$result->violations, $fixes];
}

function migrationFixed(Rule $rule, string $code): string
{
    return (new RuleTester)->fix($rule, $code, dangerous: true);
}

function expectRewrite(Rule $rule, string $before, string $after): void
{
    expect(migrationFixed($rule, $before))->toBe($after);
    expect(migrationFixed($rule, $after))->toBe($after);

    expect(count(Document::parse($after)->diagnostics()->errors()))
        ->toBeLessThanOrEqual(count(Document::parse($before)->diagnostics()->errors()));
}

function expectUntouched(Rule $rule, string $code): void
{
    expect(migrationFixed($rule, $code))->toBe($code);
}

/** @return array{string, int} The content and the number of passes that changed it */
function migrationPreset(string $code, int $maxPasses = 10): array
{
    $registry = new RuleRegistry;
    $registry->discoverRules(__DIR__.'/../../../src/Rules');

    $config = Config::make();
    $config->setRules(RulePreset::MIGRATION->rules($registry));

    $linter = new Linter($registry);
    $fixer = new Fixer;
    $passes = 0;

    for ($pass = 0; $pass < $maxPasses; $pass++) {
        $fixes = [];
        foreach ($linter->lint($code, 'test.blade.php', $config)->violations as $violation) {
            if ($violation->fix !== null) {
                $fixes[] = $violation->fix;
            }
        }

        if ($fixes === []) {
            break;
        }

        $result = $fixer->applyFixes($code, $fixes, includeDangerous: true);

        if ($result->appliedCount === 0 || $result->content === $code) {
            break;
        }

        $code = $result->content;
        $passes++;
    }

    return [$code, $passes];
}

describe('blade-no-php-echo', function (): void {
    it('rewrites a block that only echoes', function (string $before, string $after): void {
        expectRewrite(new NoPhpEchoRule, $before, $after);
    })->with([
        'one line' => ['@php echo $title; @endphp', '{!! $title !!}'],
        'no trailing semicolon' => ['@php echo $title @endphp', '{!! $title !!}'],
        'across lines' => ["@php\n    echo \$title;\n@endphp", '{!! $title !!}'],
        'uppercase directive' => ['@PHP echo $title; @ENDPHP', '{!! $title !!}'],
        'commas inside a call' => ['@php echo implode(", ", $tags); @endphp', '{!! implode(", ", $tags) !!}'],
        'commas inside an array' => ['@php echo $rows[0]["a, b"]; @endphp', '{!! $rows[0]["a, b"] !!}'],
        'braces of a match' => [
            '@php echo match($a) { 1 => "one", default => "other" }; @endphp',
            '{!! match($a) { 1 => "one", default => "other" } !!}',
        ],
        'inside an element' => ['<div>@php echo $body; @endphp</div>', '<div>{!! $body !!}</div>'],
        'inside a directive body' => [
            "@if (\$x)\n    @php echo \$body; @endphp\n@endif",
            "@if (\$x)\n    {!! \$body !!}\n@endif",
        ],
    ]);

    it('keeps the expression exactly as it was written, bytes included', function (): void {
        expectRewrite(
            new NoPhpEchoRule,
            "@php echo \$a  .  ' — '  .  \$b; @endphp",
            "{!! \$a  .  ' — '  .  \$b !!}"
        );
    });

    it('rewrites to the raw form, never the escaped one', function (): void {
        expect(migrationFixed(new NoPhpEchoRule, '@php echo $body; @endphp'))
            ->not->toContain('{{');
    });

    it('leaves alone anything a Blade echo cannot carry', function (string $code): void {
        expectUntouched(new NoPhpEchoRule, $code);
    })->with([
        'a second statement' => ['@php $a = 1; echo $a; @endphp'],
        'a statement after the echo' => ['@php echo $a; $b = 2; @endphp'],
        'no echo at all' => ['@php $a = 1; @endphp'],
        'an empty block' => ['@php @endphp'],
        'multiple echo arguments' => ['@php echo $a, $b; @endphp'],
        'print rather than echo' => ['@php print $a; @endphp'],
        'a line comment' => ["@php // why\necho \$a;\n@endphp"],
        'a block comment' => ['@php echo $a; /* keep me */ @endphp'],
        'a heredoc' => ["@php echo <<<'TXT'\nhi\nTXT; @endphp"],
        'the inline assignment form' => ['@php($total = 0)'],
        'a stray extra semicolon' => ['@php echo $a;; @endphp'],
    ]);

    it('leaves a block that an @endphp inside a string cut short', function (): void {
        expectUntouched(new NoPhpEchoRule, '@php echo "@endphp"; @endphp');
    });

    it('leaves an expression that would close the echo early', function (): void {
        expectUntouched(new NoPhpEchoRule, '@php echo $a . \'!!}\'; @endphp');
    });
});

describe('blade-prefer-endsection', function (): void {
    it('replaces @stop and nothing else', function (): void {
        expectRewrite(
            new PreferEndsectionRule,
            "@section('content')\n    <p>Hello.</p>\n@stop",
            "@section('content')\n    <p>Hello.</p>\n@endsection"
        );
    });

    it('replaces each @stop in a nested pair', function (): void {
        expectRewrite(
            new PreferEndsectionRule,
            "@section('outer')\n@section('inner')\n@stop\n@stop",
            "@section('outer')\n@section('inner')\n@endsection\n@endsection"
        );
    });

    it('rewrites every spelling Blade accepts for @stop', function (string $terminator): void {
        expectRewrite(
            new PreferEndsectionRule,
            "@section('content')\n    <p>x</p>\n{$terminator}",
            "@section('content')\n    <p>x</p>\n@endsection"
        );
    })->with(['@stop', '@Stop', '@STOP', '@stop()']);

    it('leaves a terminator that is not a directive', function (): void {
        expectUntouched(new PreferEndsectionRule, "@section('content')x@stop");
    });

    it('leaves the terminators that are not aliases', function (string $terminator): void {
        expectUntouched(new PreferEndsectionRule, "@section('a')\n    <p>x</p>\n{$terminator}");
    })->with(['@endsection', '@show', '@append', '@overwrite']);

    it('leaves the inline two-argument form, which has no terminator', function (): void {
        expectUntouched(new PreferEndsectionRule, "@section('title', 'Home')");
    });

    it('leaves other block directives that look similar', function (string $code): void {
        expectUntouched(new PreferEndsectionRule, $code);
    })->with([
        'push' => ["@push('js')\n<p>x</p>\n@endpush"],
        'prepend' => ["@prepend('js')\n<p>x</p>\n@endprepend"],
        'once' => ["@once\n<p>x</p>\n@endonce"],
    ]);
});

describe('blade-prefer-lang-helper', function (): void {
    it('rewrites a standalone @lang', function (string $before, string $after): void {
        expectRewrite(new PreferLangHelperRule, $before, $after);
    })->with([
        'a key' => ["@lang('messages.welcome')", "{{ __('messages.welcome') }}"],
        'a key and replacements' => [
            "@lang('m.greet', ['name' => \$user->name])",
            "{{ __('m.greet', ['name' => \$user->name]) }}",
        ],
        'a computed key' => ['@lang($key)', '{{ __($key) }}'],
        'inside an element' => ["<h1>@lang('a.b')</h1>", '<h1>{{ __(\'a.b\') }}</h1>'],
        'inside a directive body' => [
            "@if (\$x)\n    @lang('a.b')\n@endif",
            "@if (\$x)\n    {{ __('a.b') }}\n@endif",
        ],
    ]);

    it('marks the fix dangerous, because it adds escaping', function (): void {
        [$violations, $fixes] = migrationFixes(new PreferLangHelperRule, "@lang('a.b')");

        expect($violations)->toHaveCount(1)
            ->and($fixes)->toHaveCount(1)
            ->and($fixes[0]->dangerous)->toBeTrue();
    });

    it('is skipped by a plain fix run', function (): void {
        [, $fixes] = migrationFixes(new PreferLangHelperRule, "@lang('a.b')");

        expect((new Fixer)->applyFixes("@lang('a.b')", $fixes)->content)->toBe("@lang('a.b')");
    });

    it('leaves the block form, which is a different directive', function (string $code): void {
        expectUntouched(new PreferLangHelperRule, $code);
    })->with([
        'with a replacement array' => ["@lang(['name' => \$name])\n    Hello :name.\n@endlang"],
        'with no arguments' => ["@lang\n    Hello.\n@endlang"],
        'an unclosed array form' => ["@lang(['name' => \$name])"],
        'bare, with no parentheses' => ['<p>@lang</p>'],
    ]);

    it('leaves arguments that would close the echo early', function (): void {
        expectUntouched(new PreferLangHelperRule, "@lang('a', ['x' => \$m['y']}}])");
    });

    it('still rewrites inside a script tag, where the escaping change matters most', function (): void {
        expectRewrite(
            new PreferLangHelperRule,
            "<script>var t = \"@lang('a.b')\";</script>",
            '<script>var t = "{{ __(\'a.b\') }}";</script>'
        );
    });
});

describe('blade-prefer-component-tags', function (): void {
    it('marks every directive-to-tag migration dangerous and skips it in a plain fix run', function (string $code): void {
        [, $fixes] = migrationFixes(new PreferComponentTagsRule, $code);

        expect($fixes)->toHaveCount(1)
            ->and($fixes[0]->dangerous)->toBeTrue()
            ->and((new Fixer)->applyFixes($code, $fixes)->content)->toBe($code);
    })->with([
        'without data' => ["@component('components.alert')\nx\n@endcomponent"],
        'with an empty data array' => ["@component('components.alert', [])\nx\n@endcomponent"],
        'with data' => ["@component('components.alert', ['level' => 'x'])\nx\n@endcomponent"],
    ]);

    it('rewrites a component whose name resolves the same way', function (string $before, string $after): void {
        expectRewrite(new PreferComponentTagsRule, $before, $after);
    })->with([
        'a namespaced view' => [
            "@component('mail::message')\nBody\n@endcomponent",
            "<x-mail::message>\nBody\n</x-mail::message>",
        ],
        'a components-prefixed view' => [
            "@component('components.alert')\nBody\n@endcomponent",
            "<x-alert>\nBody\n</x-alert>",
        ],
        'a nested components directory' => [
            "@component('components.forms.input')\nBody\n@endcomponent",
            "<x-forms.input>\nBody\n</x-forms.input>",
        ],
        'an empty body' => [
            "@component('mail::message')@endcomponent",
            '<x-mail::message></x-mail::message>',
        ],
        'the array() array syntax' => [
            "@component('mail::button', array('url' => \$url))\nGo\n@endcomponent",
            "<x-mail::button :url=\"\$url\">\nGo\n</x-mail::button>",
        ],
        'a trailing comma' => [
            "@component('mail::button', ['url' => \$url,])\nGo\n@endcomponent",
            "<x-mail::button :url=\"\$url\">\nGo\n</x-mail::button>",
        ],
    ]);

    it('converts each data value in the form that preserves its type', function (string $entry, string $attribute): void {
        expectRewrite(
            new PreferComponentTagsRule,
            "@component('mail::button', [{$entry}])\nGo\n@endcomponent",
            "<x-mail::button {$attribute}>\nGo\n</x-mail::button>",
        );
    })->with([
        'a plain string stays plain' => ["'color' => 'green'", 'color="green"'],
        'a string with a space' => ["'label' => 'Click me'", 'label="Click me"'],
        'an integer stays bound' => ["'count' => 3", ':count="3"'],
        'a boolean stays bound' => ["'flag' => true", ':flag="true"'],
        'null stays bound' => ["'value' => null", ':value="null"'],
        'an expression' => ["'url' => \$order->url", ':url="$order->url"'],
        'a call with single quotes' => ["'url' => route('show')", ':url="route(\'show\')"'],
        'a call with double quotes' => ["'url' => route(\"show\")", ':url=\'route("show")\''],
        'a string holding markup' => ["'label' => '<b>hi</b>'", ':label="\'<b>hi</b>\'"'],
        'a string holding Blade' => ["'label' => '{{ x }}'", ':label="\'{{ x }}\'"'],
        'a camelCase key' => ["'buttonColor' => 'red'", 'buttonColor="red"'],
    ]);

    it('converts a slot to the tag form', function (): void {
        expectRewrite(
            new PreferComponentTagsRule,
            "@component('mail::message')\n@slot('title')\nShipped\n@endslot\nBody\n@endcomponent",
            "<x-mail::message>\n<x-slot:title>\nShipped\n</x-slot>\nBody\n</x-mail::message>"
        );
    });

    it('converts a slot wherever it sits inside the component', function (): void {
        expectRewrite(
            new PreferComponentTagsRule,
            "@component('mail::message')\n@if (\$x)\n@slot('title')\nT\n@endslot\n@endif\n@endcomponent",
            "<x-mail::message>\n@if (\$x)\n<x-slot:title>\nT\n</x-slot>\n@endif\n</x-mail::message>"
        );
    });

    it('converts a nested component along with its parent, in one edit', function (): void {
        [$violations, $fixes] = migrationFixes(
            new PreferComponentTagsRule,
            "@component('mail::message')\n@component('mail::button', ['url' => \$url])\nGo\n@endcomponent\n@endcomponent"
        );

        expect($violations)->toHaveCount(2)
            ->and($fixes)->toHaveCount(1);

        expectRewrite(
            new PreferComponentTagsRule,
            "@component('mail::message')\n@component('mail::button', ['url' => \$url])\nGo\n@endcomponent\n@endcomponent",
            "<x-mail::message>\n<x-mail::button :url=\"\$url\">\nGo\n</x-mail::button>\n</x-mail::message>"
        );
    });

    it('converts an inner component when the outer one cannot be converted', function (): void {
        expectRewrite(
            new PreferComponentTagsRule,
            "@component('alert')\n@component('mail::button', ['url' => \$url])\nGo\n@endcomponent\n@endcomponent",
            "@component('alert')\n<x-mail::button :url=\"\$url\">\nGo\n</x-mail::button>\n@endcomponent"
        );
    });

    it('converts a descendant through an unconvertible intermediate component in one convergent edit', function (): void {
        $before = <<<'BLADE'
        @component('mail::message')
        @component('alert')
        @component('mail::button')
        Go
        @endcomponent
        @endcomponent
        @endcomponent
        BLADE;
        $after = <<<'BLADE'
        <x-mail::message>
        @component('alert')
        <x-mail::button>
        Go
        </x-mail::button>
        @endcomponent
        </x-mail::message>
        BLADE;

        [$violations, $fixes] = migrationFixes(new PreferComponentTagsRule, $before);

        expect($violations)->toHaveCount(3)
            ->and($fixes)->toHaveCount(1);

        expectRewrite(new PreferComponentTagsRule, $before, $after);
    });

    it('handles deeply nested migration work', function (): void {
        $depth = 200;
        $code = str_repeat("@component('components.alert')\n", $depth)
            .'Body'
            .str_repeat("\n@endcomponent", $depth);

        [$violations, $fixes] = migrationFixes(new PreferComponentTagsRule, $code);

        expect($violations)->toHaveCount($depth)
            ->and($fixes)->toHaveCount(1);

        $fixed = (new Fixer)->applyFixes($code, $fixes, includeDangerous: true)->content;

        expect($fixed)->not->toContain('@component')
            ->and(migrationFixed(new PreferComponentTagsRule, $fixed))->toBe($fixed);
    });

    it('carries a whole markdown mailable across in one pass', function (): void {
        expectRewrite(
            new PreferComponentTagsRule,
            <<<'BLADE'
            @component('mail::message')
            # Order shipped

            @component('mail::button', ['url' => $order->url, 'color' => 'green'])
            Track it
            @endcomponent

            Thanks,<br>
            {{ config('app.name') }}
            @endcomponent
            BLADE,
            <<<'BLADE'
            <x-mail::message>
            # Order shipped

            <x-mail::button :url="$order->url" color="green">
            Track it
            </x-mail::button>

            Thanks,<br>
            {{ config('app.name') }}
            </x-mail::message>
            BLADE,
        );
    });

    it('reports but does not rewrite a name that resolves elsewhere', function (string $code): void {
        [$violations, $fixes] = migrationFixes(new PreferComponentTagsRule, $code);

        expect($violations)->not->toBeEmpty()
            ->and($fixes)->toBe([]);
    })->with([
        'a bare view name' => ["@component('alert')\nx\n@endcomponent"],
        'a dotted view name' => ["@component('partials.alert')\nx\n@endcomponent"],
        'a computed name' => ["@component(\$name)\nx\n@endcomponent"],
        'a concatenated name' => ["@component('mail::' . \$name)\nx\n@endcomponent"],

        'another namespace' => ["@component('admin::alert')\nx\n@endcomponent"],
        'a namespace that looks like mail' => ["@component('mailer::alert')\nx\n@endcomponent"],
    ]);

    it('leaves data it cannot express as attributes', function (string $code): void {
        expectUntouched(new PreferComponentTagsRule, $code);
    })->with([
        'a snake_case key' => ["@component('mail::button', ['foo_bar' => 1])\nx\n@endcomponent"],
        'a kebab-case key' => ["@component('mail::button', ['foo-bar' => 1])\nx\n@endcomponent"],
        'a capitalised key' => ["@component('mail::button', ['Url' => 1])\nx\n@endcomponent"],
        'a computed key' => ["@component('mail::button', [\$k => 1])\nx\n@endcomponent"],
        'a spread' => ["@component('mail::button', [...\$rest])\nx\n@endcomponent"],
        'a positional entry' => ["@component('mail::button', ['solo'])\nx\n@endcomponent"],
        'a variable instead of an array' => ["@component('mail::button', \$data)\nx\n@endcomponent"],
        'the same key twice' => ["@component('mail::button', ['a' => 1, 'a' => 2])\nx\n@endcomponent"],
        'a value holding both quote characters' => ["@component('mail::b', ['u' => \"a\" . 'b'])\nx\n@endcomponent"],
        'a third argument' => ["@component('mail::b', ['a' => 1], 'extra')\nx\n@endcomponent"],
    ]);

    it('leaves the whole component when a slot inside it cannot be converted', function (string $code): void {
        expectUntouched(new PreferComponentTagsRule, $code);
    })->with([
        'the two-argument slot form' => ["@component('mail::message')\n@slot('title', \$t)\n@endslot\n@endcomponent"],
        'a slot name that would be renamed' => ["@component('mail::message')\n@slot('foo_bar')\nT\n@endslot\n@endcomponent"],
        'a computed slot name' => ["@component('mail::message')\n@slot(\$name)\nT\n@endslot\n@endcomponent"],
    ]);

    it('leaves an unclosed component alone', function (): void {
        expectUntouched(new PreferComponentTagsRule, "@component('mail::message')\nBody");
    });

    it('does not confuse a similarly named directive for @component', function (): void {
        expectUntouched(new PreferComponentTagsRule, "@componentFirst(['a', 'b'])\nx\n@endcomponentFirst");
    });
});

describe('what none of these rules may touch', function (): void {
    it('leaves @verbatim content alone', function (Rule $rule, string $inner): void {
        expectUntouched($rule, "@verbatim\n{$inner}\n@endverbatim");
    })->with([
        [new NoPhpEchoRule, '@php echo $x; @endphp'],
        [new PreferLangHelperRule, "@lang('a.b')"],
        [new PreferComponentTagsRule, "@component('mail::message')\nx\n@endcomponent"],
        [new PreferEndsectionRule, "@section('a')\nx\n@stop"],
    ]);

    it('leaves a Blade comment alone', function (Rule $rule, string $inner): void {
        expectUntouched($rule, "{{-- {$inner} --}}");
    })->with([
        [new NoPhpEchoRule, '@php echo $x; @endphp'],
        [new PreferLangHelperRule, "@lang('a.b')"],
        [new PreferComponentTagsRule, "@component('mail::message') x @endcomponent"],
        [new PreferEndsectionRule, "@section('a') x @stop"],
    ]);

    it('leaves an HTML comment alone', function (Rule $rule, string $inner): void {
        expectUntouched($rule, "<!-- {$inner} -->");
    })->with([
        [new PreferLangHelperRule, "@lang('a.b')"],
        [new PreferComponentTagsRule, "@component('mail::message') x @endcomponent"],
    ]);

    it('honours an inline suppression, even where the fix spans many lines', function (): void {
        expectUntouched(
            new PreferComponentTagsRule,
            "{{-- sheath-disable-next-line blade-prefer-component-tags --}}\n@component('mail::message')\nx\n@endcomponent"
        );
    });
});

describe('the migration preset', function (): void {
    it('holds exactly the four rewrite rules', function (): void {
        expect(array_keys(RulePreset::MIGRATION->declaredRules()))->toBe([
            'blade-no-php-echo',
            'blade-prefer-component-tags',
            'blade-prefer-endsection',
            'blade-prefer-lang-helper',
        ]);
    });

    it('carries a whole legacy template across in one run', function (): void {
        $before = <<<'BLADE'
        @section('content')
            <h1>@lang('orders.title')</h1>
            @component('components.alert')
                @php echo $order->summaryHtml; @endphp
            @endcomponent
        @stop
        BLADE;

        $after = <<<'BLADE'
        @section('content')
            <h1>{{ __('orders.title') }}</h1>
            <x-alert>
                {!! $order->summaryHtml !!}
            </x-alert>
        @endsection
        BLADE;

        [$content, $passes] = migrationPreset($before);

        expect($content)->toBe($after);

        expect($passes)->toBe(2);
    });

    it('reaches a fixed point, with nothing left to say', function (): void {
        $before = <<<'BLADE'
        @section('content')
            <h1>@lang('orders.title')</h1>
            @component('components.alert')
                @php echo $order->summaryHtml; @endphp
            @endcomponent
        @stop
        BLADE;

        [$content] = migrationPreset($before);
        [$again, $passes] = migrationPreset($content);

        expect($again)->toBe($content)
            ->and($passes)->toBe(0);
    });
});
