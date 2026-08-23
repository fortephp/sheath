<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Blade\Directives\PreferForelseRule;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Testing\RuleTester;

function forelseRewrite(string $code): string
{
    return (new RuleTester)->fix(new PreferForelseRule, $code, dangerous: true);
}

describe('rewriting @if/@foreach/@else as @forelse', function (): void {
    it('classifies every rewrite as dangerous', function (): void {
        $code = <<<'BLADE'
@if (count($posts) > 0)
    @foreach ($posts as $post)
        {{ $post }}
    @endforeach
@else
    Empty
@endif
BLADE;

        $tester = new RuleTester;

        expect($tester->fix(new PreferForelseRule, $code))->toBe($code)
            ->and($tester->fix(new PreferForelseRule, $code, dangerous: true))->not->toBe($code);
    });

    it('collapses the block and lifts the loop body one level', function (): void {
        $before = <<<'BLADE'
@if (count($posts) > 0)
    @foreach ($posts as $post)
        <li>{{ $post->title }}</li>
    @endforeach
@else
    <p>Nothing yet.</p>
@endif
BLADE;

        $after = <<<'BLADE'
@forelse ($posts as $post)
    <li>{{ $post->title }}</li>
@empty
    <p>Nothing yet.</p>
@endforelse
BLADE;

        expect(forelseRewrite($before))->toBe($after);
    });

    it('keeps the surrounding markup and its indentation', function (): void {
        $before = <<<'BLADE'
<div>
    <ul>
        @if ($posts->isNotEmpty())
            @foreach ($posts as $post)
                <li>{{ $post->title }}</li>
            @endforeach
        @else
            <li>Nothing yet.</li>
        @endif
    </ul>
</div>
BLADE;

        $after = <<<'BLADE'
<div>
    <ul>
        @forelse ($posts as $post)
            <li>{{ $post->title }}</li>
        @empty
            <li>Nothing yet.</li>
        @endforelse
    </ul>
</div>
BLADE;

        expect(forelseRewrite($before))->toBe($after);
    });

    it('takes the indent unit from the file', function (string $before, string $after): void {
        expect(forelseRewrite($before))->toBe($after);
    })->with([
        'tabs' => [
            "@if (count(\$posts) > 0)\n\t@foreach (\$posts as \$post)\n\t\t<li>{{ \$post }}</li>\n\t@endforeach\n@else\n\t<p>None.</p>\n@endif",
            "@forelse (\$posts as \$post)\n\t<li>{{ \$post }}</li>\n@empty\n\t<p>None.</p>\n@endforelse",
        ],
        'two spaces' => [
            "@if (count(\$rows) > 0)\n  @foreach (\$rows as \$row)\n    <td>{{ \$row }}</td>\n  @endforeach\n@else\n  <td>None</td>\n@endif",
            "@forelse (\$rows as \$row)\n  <td>{{ \$row }}</td>\n@empty\n  <td>None</td>\n@endforelse",
        ],
        'no indentation at all' => [
            "@if (count(\$x) > 0)\n@foreach (\$x as \$y)\n{{ \$y }}\n@endforeach\n@else\nnone\n@endif",
            "@forelse (\$x as \$y)\n{{ \$y }}\n@empty\nnone\n@endforelse",
        ],
        'all on one line' => [
            '@if (count($x) > 0) @foreach ($x as $y){{ $y }} @endforeach @else none @endif',
            '@forelse ($x as $y){{ $y }} @empty none @endforelse',
        ],
    ]);

    it('dedents a nested loop body as a whole', function (): void {
        $before = <<<'BLADE'
@if ($items->isNotEmpty())
    @foreach ($items as $item)
        <article>
            <h3>{{ $item->title }}</h3>
        </article>
    @endforeach
@else
    <p>Empty.</p>
@endif
BLADE;

        $after = <<<'BLADE'
@forelse ($items as $item)
    <article>
        <h3>{{ $item->title }}</h3>
    </article>
@empty
    <p>Empty.</p>
@endforelse
BLADE;

        expect(forelseRewrite($before))->toBe($after);
    });

    it('recognises the same collection written different ways', function (string $condition): void {
        $before = "@if ({$condition})\n    @foreach (\$posts as \$post)\n        <li>{{ \$post }}</li>\n    @endforeach\n@else\n    <p>None.</p>\n@endif";

        expect(forelseRewrite($before))->toStartWith('@forelse ($posts as $post)');
    })->with([
        'count > 0' => 'count($posts) > 0',
        'count >= 1' => 'count($posts) >= 1',
        'isNotEmpty' => '$posts->isNotEmpty()',
    ]);
});

describe('the rewrite declines when the shape is not exact', function (): void {
    it('leaves the template untouched', function (string $code): void {
        expect(forelseRewrite($code))->toBe($code);
    })->with([
        'extra content in the if branch' => <<<'BLADE'
@if (count($posts) > 0)
    <h2>Posts</h2>
    @foreach ($posts as $post)
        <li>{{ $post }}</li>
    @endforeach
@else
    <p>None.</p>
@endif
BLADE,
        'an elseif branch' => <<<'BLADE'
@if (count($posts) > 0)
    @foreach ($posts as $post) <li>{{ $post }}</li> @endforeach
@elseif ($loading)
    <p>Loading</p>
@else
    <p>None.</p>
@endif
BLADE,
        'a bare @empty in the loop body' => <<<'BLADE'
@if (count($posts) > 0)
    @foreach ($posts as $post)
        @empty
    @endforeach
@else
    <p>None.</p>
@endif
BLADE,
        'no else branch' => <<<'BLADE'
@if (count($posts) > 0)
    @foreach ($posts as $post)
        <li>{{ $post }}</li>
    @endforeach
@endif
BLADE,
        'a condition about something else' => <<<'BLADE'
@if (count($comments) > 0)
    @foreach ($posts as $post)
        <li>{{ $post }}</li>
    @endforeach
@else
    <p>None.</p>
@endif
BLADE,
        'a truthy iterable whose object truthiness does not describe its contents' => <<<'BLADE'
@if ($posts)
    @foreach ($posts as $post)
        <li>{{ $post }}</li>
    @endforeach
@else
    <p>None.</p>
@endif
BLADE,
        'not empty on an iterable object' => <<<'BLADE'
@if (! empty($posts))
    @foreach ($posts as $post)
        <li>{{ $post }}</li>
    @endforeach
@else
    <p>None.</p>
@endif
BLADE,
        'nullsafe collection check' => <<<'BLADE'
@if ($posts?->isNotEmpty())
    @foreach ($posts as $post)
        <li>{{ $post }}</li>
    @endforeach
@else
    <p>None.</p>
@endif
BLADE,
    ]);

    it('still reports the violation even when it will not rewrite', function (): void {
        $registry = new RuleRegistry;
        $registry->register(PreferForelseRule::class);
        $config = Config::make()->setRule('blade-prefer-forelse', 'info');

        $code = "@if (count(\$posts) > 0)\n    @foreach (\$posts as \$post)\n        <li>x</li>\n    @endforeach\n@endif";
        $result = (new Linter($registry))->lint($code, 'test.blade.php', $config);

        expect($result->violations)->toHaveCount(1)
            ->and($result->violations[0]->hasFixAvailable())->toBeFalse();
    });
});

describe('the rewrite is safe to run repeatedly', function (): void {
    it('produces Blade that parses', function (): void {
        $after = forelseRewrite("@if (count(\$posts) > 0)\n    @foreach (\$posts as \$post)\n        <li>{{ \$post }}</li>\n    @endforeach\n@else\n    <p>None.</p>\n@endif");

        expect(Document::parse($after)->hasErrors())->toBeFalse();
    });

    it('is idempotent', function (): void {
        $once = forelseRewrite("@if (count(\$posts) > 0)\n    @foreach (\$posts as \$post)\n        <li>{{ \$post }}</li>\n    @endforeach\n@else\n    <p>None.</p>\n@endif");

        expect(forelseRewrite($once))->toBe($once);
    });

    it('preserves every Blade expression', function (): void {
        $before = <<<'BLADE'
@if ($posts->isNotEmpty())
    @foreach ($posts as $post)
        <a href="{{ route('posts.show', ['post' => $post->id]) }}">{{ $post->title }}</a>
        {!! $post->excerpt !!}
    @endforeach
@else
    <p>{{ __('posts.empty') }}</p>
@endif
BLADE;

        $pattern = '/\{\{.*?\}\}|\{!!.*?!!\}/s';
        preg_match_all($pattern, $before, $expressionsBefore);
        preg_match_all($pattern, forelseRewrite($before), $expressionsAfter);

        expect($expressionsAfter[0])->toBe($expressionsBefore[0]);
    });

    it('rewrites several blocks in one file', function (): void {
        $before = "@if (count(\$a) > 0)\n    @foreach (\$a as \$x)\n        <li>{{ \$x }}</li>\n    @endforeach\n@else\n    <p>A</p>\n@endif\n<hr>\n@if (count(\$b) > 0)\n    @foreach (\$b as \$y)\n        <li>{{ \$y }}</li>\n    @endforeach\n@else\n    <p>B</p>\n@endif";

        $after = forelseRewrite($before);

        expect(substr_count($after, '@forelse'))->toBe(2)
            ->and(substr_count($after, '@endforelse'))->toBe(2)
            ->and($after)->not->toContain('@endif')
            ->and(Document::parse($after)->hasErrors())->toBeFalse();
    });
});
