<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Configuration\DefaultConfigFactory;
use Forte\Sheath\Configuration\RulePreset;
use Forte\Sheath\Fixer;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\SheathManager;

function everyRuleConfig(): Config
{
    $registry = allRulesRegistry();

    return DefaultConfigFactory::resolve(
        ['preset' => [RulePreset::STRICT->value, RulePreset::STYLISTIC->value]],
        $registry
    );
}

function allRulesRegistry(): RuleRegistry
{
    $registry = new RuleRegistry;
    $registry->discoverRules(__DIR__.'/../../src/Rules');

    return $registry;
}

/** @return array<string> */
function bladeExpressions(string $template): array
{
    preg_match_all('/\{\{-?-?.*?-?-?\}\}|\{!!.*?!!\}|@\w+\s*\((?:[^()]|\([^()]*\))*\)/s', $template, $matches);

    return $matches[0];
}

/** @return array<string, string> */
function hostileTemplates(): array
{
    return [
        'echo with object accessor' => '<a href="{{ $post->url }}" target="_blank">{{ $post->title }}</a>',
        'echo with array arrow' => '<form method="post" action="{{ route(\'u.update\', [\'id\' => $user->id]) }}"><button>Save</button></form>',
        'blade directive attributes' => '<button @class([\'btn\' => $active, \'btn-lg\' => $large])>Go</button>',
        'attribute bag' => '<div {{ $attributes->merge([\'class\' => \'card\']) }}><h2>{{ $title }}</h2></div>',
        'greater-than in values' => '<div data-hint="a > b" title="x > y"><img src="a.png" alt="A"></div>',
        'component with expression' => '<x-alert :message="$e->getMessage()"></x-alert>',
        'nested quotes' => '<div title="it\'s here" data-json=\'{"a":">"}\'>z</div>',
        'loop with echoes' => '<ul>@foreach ($links as $link)<li><a href="{{ $link->url }}">{{ $link->label }}</a></li>@endforeach</ul>',
        'full page' => '<html lang="en"><head><meta charset="utf-8"><title>{{ $title }}</title><script src="{{ $m->js }}"></script></head><body><h1>{{ $h }}</h1></body></html>',
        'raw echo and php block' => "@php\n\$ready = 1 > 0;\n@endphp\n<div>{!! \$html !!}</div>",
        'verbatim' => '@verbatim<div x-data="{ open: false }">{{ notBlade }}</div>@endverbatim',
        'self-closed void with echo' => '<img src="{{ $post->image }}" alt="{{ $post->alt }}" />',
        'multiline tag' => "<a\n  href=\"{{ \$post->url }}\"\n  target=\"_blank\"\n>Read</a>",

        'duplicate attribute holding echoes' => '<div data-x="{{ $a }}" data-x="{{ $b }}">d</div>',
        'inline style holding an echo' => '<div style="width: {{ $w }}px">s</div>',
        'duplicate class beside an echo' => '<div class="card {{ $extra }} card">x</div>',
        'http url with an echo after it' => '<a href="http://cdn.example.com/{{ $slug }}">Download</a>',
        'http url with an echo in the middle' => '<img src="http://img.example.com/{{ $id }}/t.png" alt="T">',
        'two echoes in one attribute' => '<a href="http://x.example.com/{{ $a }}/{{ $b }}">L</a>',
        'viewport holding an echo' => '<meta name="viewport" content="width=device-width, user-scalable=no, initial-scale={{ $s }}">',
        'multi-line ternary in an attribute' => "<a href=\"{{ \$ok\n  ? 'https://a.example.com'\n  : 'https://b.example.com' }}\" target=\"_blank\">go</a>",
        'class directive beside a duplicate class' => '<button @class([\'btn\' => $on, \'btn-lg\' => $lg]) class="x x">Go</button>',
        'attribute bag beside an http url' => '<a {{ $attributes->merge([\'class\' => \'c\']) }} href="http://e.example.com/p">p</a>',
        'mixed quoting around an echo' => '<a href=\'http://e.example.com/{{ $p }}\' title="it\'s">m</a>',
        'dynamic role' => '<div role="{{ $r }}">r</div>',
        'dynamic tabindex' => '<div tabindex="{{ $i }}">t</div>',

        'accesskey holding an echo' => '<button accesskey="{{ $k }}" type="button">a</button>',
        'autofocus holding an echo' => '<input type="text" name="q" autofocus="{{ $a }}">',
        'inline handler holding an echo' => '<button onclick="doThing({{ $id }})" type="button">go</button>',
        'form handler holding an echo' => '<form method="post" onsubmit="track(\'{{ $slug }}\')"><button type="submit">s</button></form>',

        'alpine click shorthand' => '<button @click="open = !open" type="button">t</button>',
        'alpine modifier holding an echo' => '<a href="http://e.example.com/x" @click.prevent="go({{ $id }})">x</a>',
        'escaped echo for a js framework' => '<div v-text="msg">@{{ msg }}</div>',
        'escaped directive' => '<p>@@if is literal</p>',
        'push stack holding an echo' => "@push('scripts')<script src=\"{{ mix('app.js') }}\"></script>@endpush",
        'checked directive in an input' => '<input type="checkbox" name="a" @checked($user->active)>',
        'selected directive in an option' => '<select name="s"><option value="1" @selected($v == 1)>one</option></select>',
        'error directive wrapping an attribute' => '<input type="text" name="email" @error(\'email\') class="is-invalid" @enderror>',
        'condition wrapping a duplicate class' => '<div @if($b) class="b b" @endif>c</div>',
        'echo inside a script tag' => '<script>const cfg = {{ Illuminate\Support\Js::from($cfg) }};</script>',
        'json data attribute holding an echo' => '<div data-config=\'{"a":1,"b":[1,2],"c":"{{ $x }}"}\'>j</div>',
        'comment holding a dead link' => '{{-- <a href="{{ $u }}" target="_blank">old</a> --}}<p>k</p>',
        'nested component slots' => '<x-card><x-slot:header><h2>{{ $t }}</h2></x-slot:header><p>{{ $b }}</p></x-card>',
    ];
}

/** @return array<string, string> */
function migrationTemplates(): array
{
    return [
        'component beside a hostile echo' => "@component('mail::button', ['url' => \$order->url])\n<a href=\"{{ route('t', ['id' => \$order->id]) }}\">Track</a>\n@endcomponent",
        'component wrapping a loop' => "@component('components.list')\n@foreach (\$rows as \$row)\n<li>{{ \$row->label }}</li>\n@endforeach\n@endcomponent",
        'component with a slot and an attribute bag' => "@component('mail::message')\n@slot('title')\n{{ \$title }}\n@endslot\n<div {{ \$attributes->merge(['class' => 'x']) }}>y</div>\n@endcomponent",
        'lang inside an attribute' => '<input placeholder="@lang(\'form.name\')" value="{{ $old }}">',
        'lang beside a directive attribute' => "<button @class(['btn' => \$active])>@lang('actions.save')</button>",
        'section closed with stop' => "@section('content')\n<a href=\"{{ \$post->url }}\" target=\"_blank\">{{ \$post->title }}</a>\n@stop",
        'php echo beside a raw echo' => "@php echo \$header; @endphp\n<div>{!! \$body !!}</div>",
        'php block that only assigns' => "@php\n\$ready = 1 > 0;\n@endphp\n<p>{{ \$ready }}</p>",
        'everything at once' => "@section('body')\n@component('components.alert')\n<h1>@lang('a.b')</h1>\n@php echo \$note; @endphp\n@endcomponent\n@stop",
    ];
}

function fixToFixpoint(string $template, bool $includeDangerous, ?Config $config = null): string
{
    $linter = new Linter(allRulesRegistry());
    $fixer = new Fixer;
    $config ??= everyRuleConfig();
    $content = $template;

    for ($pass = 0; $pass < SheathManager::MAX_FIX_PASSES; $pass++) {
        $fixes = [];
        foreach ($linter->lint($content, 'test.blade.php', $config)->violations as $violation) {
            if ($violation->fix !== null) {
                $fixes[] = $violation->fix;
            }
        }

        if ($fixes === []) {
            break;
        }

        $result = $fixer->applyFixes($content, $fixes, $includeDangerous);

        if ($result->appliedCount === 0 || $result->content === $content) {
            break;
        }

        $content = $result->content;
    }

    return $content;
}

describe('fix safety across the whole ruleset', function (): void {
    it('preserves every Blade expression verbatim', function (string $template): void {
        expect(bladeExpressions(fixToFixpoint($template, includeDangerous: false)))
            ->toBe(bladeExpressions($template));
    })->with(hostileTemplates());

    it('preserves every Blade expression even with dangerous fixes', function (string $template): void {
        expect(bladeExpressions(fixToFixpoint($template, includeDangerous: true)))
            ->toBe(bladeExpressions($template));
    })->with(hostileTemplates());

    it('never introduces a parse error', function (string $template): void {
        $before = count(Document::parse($template)->diagnostics()->errors());
        $after = count(Document::parse(fixToFixpoint($template, includeDangerous: true))->diagnostics()->errors());

        expect($after)->toBeLessThanOrEqual($before);
    })->with(hostileTemplates());

    it('reaches a fixpoint rather than oscillating', function (string $template): void {
        $once = fixToFixpoint($template, includeDangerous: false);

        expect(fixToFixpoint($once, includeDangerous: false))->toBe($once);
    })->with(hostileTemplates());

    it('leaves element and directive counts intact', function (string $template): void {
        $before = Document::parse($template);
        $after = Document::parse(fixToFixpoint($template, includeDangerous: false));

        expect($after->getElements()->count())->toBe($before->getElements()->count())
            ->and($after->getBlockDirectives()->count())->toBe($before->getBlockDirectives()->count());
    })->with(hostileTemplates());

    it('does not corrupt a template no rule wants to change', function (): void {
        $clean = '<div class="card"><p>{{ $body }}</p></div>';

        expect(fixToFixpoint($clean, includeDangerous: false))->toBe($clean);
    });
});

function everyRuleWithMigrationConfig(): Config
{
    return DefaultConfigFactory::resolve(
        ['preset' => [RulePreset::STRICT->value, RulePreset::STYLISTIC->value, RulePreset::MIGRATION->value]],
        allRulesRegistry()
    );
}

describe('fix safety with the migration preset switched on', function (): void {
    it('never introduces a parse error', function (string $template): void {
        $before = count(Document::parse($template)->diagnostics()->errors());
        $after = count(Document::parse(
            fixToFixpoint($template, includeDangerous: true, config: everyRuleWithMigrationConfig())
        )->diagnostics()->errors());

        expect($after)->toBeLessThanOrEqual($before);
    })->with(migrationTemplates());

    it('reaches a fixpoint rather than oscillating', function (string $template): void {
        $config = everyRuleWithMigrationConfig();
        $once = fixToFixpoint($template, includeDangerous: true, config: $config);

        expect(fixToFixpoint($once, includeDangerous: true, config: $config))->toBe($once);
    })->with(migrationTemplates());

    it('carries every echo it started with through to the output', function (string $template): void {
        $echoes = static fn (string $blade): array => array_values(array_filter(
            bladeExpressions($blade),
            static fn (string $expression): bool => ! str_starts_with($expression, '@')
        ));

        $before = $echoes($template);
        $after = $echoes(fixToFixpoint($template, includeDangerous: true, config: everyRuleWithMigrationConfig()));

        expect(count($after))->toBeGreaterThanOrEqual(count($before));

        $cursor = 0;
        foreach ($before as $expression) {
            $found = array_search($expression, array_slice($after, $cursor), true);

            expect($found)->not->toBeFalse("Lost or altered: {$expression}\nGot: ".json_encode($after));

            $cursor += (int) $found + 1;
        }
    })->with(migrationTemplates());

    it('leaves a template with nothing to migrate exactly as it was', function (string $template): void {
        $config = everyRuleWithMigrationConfig();

        expect(fixToFixpoint($template, includeDangerous: false, config: $config))
            ->toBe(fixToFixpoint($template, includeDangerous: false));
    })->with(hostileTemplates());
});
