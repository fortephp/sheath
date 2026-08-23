<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Blade\Directives\ViewReferenceExistsRule;
use Forte\Sheath\Rules\RuleRegistry;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\FileViewFinder;
use Illuminate\View\ViewException;

beforeEach(function (): void {
    $this->viewReferenceSandbox = TestViewSandbox::make('view-reference-rule-');
    $this->originalViewFinder = app('view.finder');
    $this->viewReferenceFinder = new FileViewFinder(new Filesystem, [$this->viewReferenceSandbox->root]);
    app()->instance('view.finder', $this->viewReferenceFinder);
});

afterEach(function (): void {
    app()->instance('view.finder', $this->originalViewFinder);
    $this->viewReferenceSandbox->cleanup();
});

function lintViewReferences(string $source): array
{
    $rule = new ViewReferenceExistsRule;
    $registry = new RuleRegistry;
    $registry->register($rule);
    $config = Config::make()->setRule($rule->getId(), 'error');

    return (new Linter($registry))->lint($source, 'test.blade.php', $config)->violations;
}

it('reports missing literal targets in supported single-view directives', function (string $source): void {
    $violations = lintViewReferences($source);

    expect($violations)->toHaveCount(1);
})->with([
    'include' => ["@include('missing.view')"],
    'include when' => ["@includeWhen(\$show, 'missing.view')"],
    'include unless' => ["@includeUnless(\$hide, 'missing.view')"],
    'include isolated' => ["@includeIsolated('missing.view', ['name' => 'Ada'])"],
    'extends' => ["@extends('missing.view')"],
    'component directive' => ["@component('missing.view')\n@endcomponent"],
]);

it('accepts literal targets resolved by the configured finder without executing view source', function (): void {
    $this->viewReferenceSandbox->file('partials/nav.blade.php', '<?php throw new RuntimeException("must not execute"); ?>');

    $this->getRuleTester()->run(new ViewReferenceExistsRule, [
        'valid' => [
            "@include('partials.nav')",
            "@includeWhen(\$show, 'partials.nav')",
            "@includeUnless(\$hide, 'partials.nav')",
            "@includeIsolated('partials.nav')",
            "@extends('partials.nav')",
            "@component('partials.nav')\n@endcomponent",
        ],
    ]);
});

it('always stands down for includeIf and for computed view names', function (): void {
    $this->getRuleTester()->run(new ViewReferenceExistsRule, [
        'valid' => [
            "@includeIf('missing.view')",
            '@include($view)',
            "@include('missing.'.\$suffix)",
            '@includeWhen($show, resolve_view())',
            '@extends(config(\'layout\'))',
            '@component($component) @endcomponent',
        ],
    ]);
});

it('skips conditionals whose simple literal condition makes rendering unreachable', function (string $source): void {
    expect(lintViewReferences($source))->toHaveCount(0);
})->with([
    'includeWhen false' => ["@includeWhen(false, 'missing.view')"],
    'includeWhen zero' => ["@includeWhen(0, 'missing.view')"],
    'includeWhen null' => ["@includeWhen(null, 'missing.view')"],
    'includeWhen empty string' => ["@includeWhen('', 'missing.view')"],
    'includeWhen string zero' => ["@includeWhen('0', 'missing.view')"],
    'includeWhen empty array' => ["@includeWhen([], 'missing.view')"],
    'includeUnless true' => ["@includeUnless(true, 'missing.view')"],
    'includeUnless one' => ["@includeUnless(1, 'missing.view')"],
    'includeUnless string' => ["@includeUnless('x', 'missing.view')"],
    'includeUnless non-empty array' => ["@includeUnless([1], 'missing.view')"],
    'includeUnless keyed literal array' => ["@includeUnless(['enabled' => true], 'missing.view')"],
    'includeUnless trailing-comma array' => ["@includeUnless([1,], 'missing.view')"],
]);

it('still reports conditionals whose static value reaches the view and whose condition is dynamic', function (string $source): void {
    expect(lintViewReferences($source))->toHaveCount(1);
})->with([
    'includeWhen true' => ["@includeWhen(true, 'missing.view')"],
    'includeWhen one' => ["@includeWhen(1, 'missing.view')"],
    'includeWhen string' => ["@includeWhen('x', 'missing.view')"],
    'includeWhen non-empty array' => ["@includeWhen([1], 'missing.view')"],
    'includeUnless false' => ["@includeUnless(false, 'missing.view')"],
    'includeUnless zero' => ["@includeUnless(0, 'missing.view')"],
    'includeUnless null' => ["@includeUnless(null, 'missing.view')"],
    'includeUnless empty string' => ["@includeUnless('', 'missing.view')"],
    'includeUnless empty array' => ["@includeUnless([], 'missing.view')"],
    'dynamic variable' => ["@includeWhen(\$condition, 'missing.view')"],
    'constant' => ["@includeWhen(FEATURE_ENABLED, 'missing.view')"],
    'function call' => ["@includeUnless(feature_enabled(), 'missing.view')"],
    'complex array' => ["@includeUnless([feature_enabled()], 'missing.view')"],
]);

it('matches Blade runtime reachability for static conditional includes', function (): void {
    expect(Blade::render("@includeWhen(false, 'missing.view')"))->toBe('')
        ->and(Blade::render("@includeUnless(true, 'missing.view')"))->toBe('')
        ->and(fn (): string => Blade::render("@includeWhen(true, 'missing.view')"))
        ->toThrow(ViewException::class)
        ->and(fn (): string => Blade::render("@includeUnless(false, 'missing.view')"))
        ->toThrow(ViewException::class);
});

it('reports first directives only when every static candidate is missing', function (string $source): void {
    $violations = lintViewReferences($source);

    expect($violations)->toHaveCount(1);
})->with([
    'includeFirst short array' => ["@includeFirst(['missing.one', 'missing.two'])"],
    'extendsFirst long array' => ["@extendsFirst(array('missing.one', 'missing.two'))"],
    'keyed candidates use values' => ["@includeFirst(['first' => 'missing.one', 'second' => 'missing.two'])"],
    'includeFirst trailing comma' => ["@includeFirst(['missing.one', 'missing.two',])"],
    'extendsFirst long-array trailing comma and trivia' => ["@extendsFirst(array('missing.one', 'missing.two', /* fallback */))"],
]);

it('accepts first directives when one candidate exists or any candidate is dynamic', function (): void {
    $this->viewReferenceSandbox->file('existing.blade.php', 'ok');

    $this->getRuleTester()->run(new ViewReferenceExistsRule, [
        'valid' => [
            "@includeFirst(['missing', 'existing'])",
            "@extendsFirst(['missing', \$fallback])",
            "@includeFirst([\$primary, 'missing'])",
            '@includeFirst($views)',
            '@includeFirst([])',
        ],
    ]);
});

it('checks each primary and non-raw empty view positions independently', function (): void {
    $violations = lintViewReferences("@each('missing.row', \$items, 'item', 'missing.empty')");

    expect($violations)->toHaveCount(2);

    $this->getRuleTester()->run(new ViewReferenceExistsRule, [
        'valid' => [
            "@each(\$rowView, \$items, 'item', 'raw|Nothing here')",
            "@each(\$rowView, \$items, 'item', \$emptyView)",
        ],
    ]);
});

it('resolves namespaced views through finder hint paths', function (): void {
    $namespace = TestViewSandbox::make('view-reference-namespace-');
    $namespace->file('cards/item.blade.php', 'ok');
    $this->viewReferenceFinder->addNamespace('admin', $namespace->root);

    try {
        $this->getRuleTester()->run(new ViewReferenceExistsRule, [
            'valid' => ["@include('admin::cards.item')"],
            'invalid' => [[
                'code' => "@include('admin::cards.missing')",
                'errors' => 1,
            ]],
        ]);
    } finally {
        $namespace->cleanup();
    }
});

it('reports exactly the quoted literal token range', function (): void {
    $source = "<main>\n  @includeWhen(\$ready, /* view */ 'missing.view', ['x' => 1])\n</main>";
    $violations = lintViewReferences($source);
    $literal = "'missing.view'";
    $start = strpos($source, $literal);

    expect($violations)->toHaveCount(1)
        ->and($start)->toBeInt()
        ->and($violations[0]->start->offset)->toBe($start)
        ->and($violations[0]->end->offset)->toBe($start + strlen($literal))
        ->and(substr($source, $violations[0]->start->offset, $violations[0]->end->offset - $violations[0]->start->offset))
        ->toBe($literal);
});

it('flushes a cloned finder so repeated lints see target creation and removal', function (): void {
    $source = "@include('changing')";

    expect(lintViewReferences($source))->toHaveCount(1);

    $path = $this->viewReferenceSandbox->file('changing.blade.php', 'v1');
    expect(lintViewReferences($source))->toHaveCount(0);

    unlink($path);
    expect(lintViewReferences($source))->toHaveCount(1);
});

it('fingerprints finder configuration and view identities without content-only cache churn', function (): void {
    $rule = new ViewReferenceExistsRule;
    $initial = $rule->cacheContext([]);

    $path = $this->viewReferenceSandbox->file('changing.blade.php', 'v1');
    $created = $rule->cacheContext([]);
    file_put_contents($path, 'v2');
    $contentChanged = $rule->cacheContext([]);
    rename($path, $this->viewReferenceSandbox->path('renamed.blade.php'));
    $renamed = $rule->cacheContext([]);

    $extra = TestViewSandbox::make('view-reference-extra-path-');
    $this->viewReferenceFinder->addLocation($extra->root);
    $withPath = $rule->cacheContext([]);
    $this->viewReferenceFinder->addNamespace('mail', $extra->root);
    $withNamespace = $rule->cacheContext([]);

    try {
        expect($initial)->not->toBe($created)
            ->and($created)->toBe($contentChanged)
            ->and($contentChanged)->not->toBe($renamed)
            ->and($renamed)->not->toBe($withPath)
            ->and($withPath)->not->toBe($withNamespace);
    } finally {
        $extra->cleanup();
    }
});

it('invalidates command result cache when a referenced view is created and removed', function (): void {
    $this->viewReferenceSandbox->file('index.blade.php', "@include('changing')");

    withTempFile(function (string $cacheFile): void {
        $arguments = [
            'paths' => [$this->viewReferenceSandbox->root],
            '--only' => 'blade-view-reference-exists',
            '--cache' => true,
            '--cache-location' => $cacheFile,
        ];

        $this->artisan('sheath:lint', $arguments)->assertFailed();

        $path = $this->viewReferenceSandbox->file('changing.blade.php', 'now present');
        $this->artisan('sheath:lint', $arguments)
            ->assertSuccessful();

        unlink($path);
        $this->artisan('sheath:lint', $arguments)
            ->assertFailed();
    }, prefix: '.view-reference-cache-', inBasePath: true);
});

it('stands down when Laravel has no booted file view finder', function (): void {
    $application = Container::getInstance();
    Container::setInstance(new Container);

    try {
        expect(lintViewReferences("@include('missing')"))->toHaveCount(0)
            ->and((new ViewReferenceExistsRule)->cacheContext([]))->toBe('view-finder-unavailable');
    } finally {
        Container::setInstance($application);
    }
});
