<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Contracts\ProvidesCacheContext;
use Forte\Sheath\Linter;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Rules\Blade\Components\ComponentRequiredPropsRule;
use Forte\Sheath\Rules\RuleRegistry;
use Illuminate\Filesystem\Filesystem;

function lintRequiredComponentProps(string $source, string $viewPath): LintResult
{
    $rule = new ComponentRequiredPropsRule;
    $registry = new RuleRegistry;
    $registry->register($rule);

    return (new Linter($registry))->lint(
        $source,
        $viewPath,
        Config::make()->setRule($rule->getId(), 'error'),
    );
}

describe('ComponentRequiredPropsRule', function (): void {
    beforeEach(function (): void {
        $this->componentProject = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sheath-component-props-'.bin2hex(random_bytes(8));
        $this->views = $this->componentProject.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views';
        $this->components = $this->views.DIRECTORY_SEPARATOR.'components';
        (new Filesystem)->ensureDirectoryExists($this->components);
        $this->viewPath = $this->views.DIRECTORY_SEPARATOR.'page.blade.php';
    });

    afterEach(function (): void {
        (new Filesystem)->deleteDirectory($this->componentProject);
    });

    it('reports every definitely missing bare @props entry', function (): void {
        (new Filesystem)->put(
            $this->components.DIRECTORY_SEPARATOR.'card.blade.php',
            "@props(['title', 'userId', 'theme' => 'light'])\n<div>{{ \$title }}</div>",
        );

        $result = lintRequiredComponentProps('<x-card />', $this->viewPath);

        expect($result->violations)->toHaveCount(1);
    });

    it('treats every keyed prop as optional regardless of its default value', function (): void {
        (new Filesystem)->put(
            $this->components.DIRECTORY_SEPARATOR.'card.blade.php',
            "@props(['nullValue' => null, 'falseValue' => false, 'emptyValue' => '', 'arrayValue' => []])",
        );

        expect(lintRequiredComponentProps('<x-card />', $this->viewPath)->violations)->toBeEmpty();
    });

    it('treats integer and integer-string keys as required declarations', function (): void {
        (new Filesystem)->put(
            $this->components.DIRECTORY_SEPARATOR.'card.blade.php',
            "@props([0 => 'title', '1' => 'userId', 'theme' => 'light'])",
        );

        $result = lintRequiredComponentProps('<x-card />', $this->viewPath);

        expect($result->violations)->toHaveCount(1);
    });

    it('accepts explicit camel, kebab, and bound attributes', function (string $source): void {
        (new Filesystem)->put(
            $this->components.DIRECTORY_SEPARATOR.'card.blade.php',
            "@props(['userId'])",
        );

        expect(lintRequiredComponentProps($source, $this->viewPath)->violations)->toBeEmpty();
    })->with([
        'camel' => '<x-card userId="42" />',
        'kebab' => '<x-card user-id="42" />',
        'bound' => '<x-card :user-id="$userId" />',
    ]);

    it('accepts required values supplied through static named slots', function (string $source): void {
        (new Filesystem)->put(
            $this->components.DIRECTORY_SEPARATOR.'card.blade.php',
            "@props(['userId'])",
        );

        expect(lintRequiredComponentProps($source, $this->viewPath)->violations)->toBeEmpty();
    })->with([
        'kebab slot' => '<x-card><x-slot:user-id>42</x-slot:user-id></x-card>',
        'camel slot' => '<x-card><x-slot:userId>42</x-slot:userId></x-card>',
        'dynamic slot stands down' => '<x-card><x-slot:[$slotName]>42</x-slot></x-card>',
    ]);

    it('does not treat a conditional named slot as satisfying every render path', function (): void {
        (new Filesystem)->put(
            $this->components.DIRECTORY_SEPARATOR.'card.blade.php',
            "@props(['title'])",
        );

        $result = lintRequiredComponentProps(
            '<x-card>@if($show)<x-slot:title>Title</x-slot:title>@endif</x-card>',
            $this->viewPath,
        );

        expect($result->violations)->toHaveCount(1);
    });

    it('reports when a required prop is absent on any explicit render path', function (): void {
        (new Filesystem)->put($this->components.DIRECTORY_SEPARATOR.'card.blade.php', "@props(['title'])");

        $result = lintRequiredComponentProps(
            '<x-card @if($show) title="Hello" @endif />',
            $this->viewPath,
        );

        expect($result->violations)->toHaveCount(1);
    });

    it('stands down for opaque attribute bags and spreads', function (string $source): void {
        (new Filesystem)->put($this->components.DIRECTORY_SEPARATOR.'card.blade.php', "@props(['title'])");

        expect(lintRequiredComponentProps($source, $this->viewPath)->violations)->toBeEmpty();
    })->with([
        'Blade attribute bag' => '<x-card {{ $attributes }} />',
        'spread attribute' => '<x-card ...$attributes />',
    ]);

    it('resolves nested local anonymous components', function (): void {
        $nested = $this->components.DIRECTORY_SEPARATOR.'forms';
        (new Filesystem)->ensureDirectoryExists($nested);
        (new Filesystem)->put($nested.DIRECTORY_SEPARATOR.'field.blade.php', "@props(['name'])");

        $missing = lintRequiredComponentProps('<x-forms.field />', $this->viewPath);
        $present = lintRequiredComponentProps('<x-forms.field name="email" />', $this->viewPath);

        expect($missing->violations)->toHaveCount(1)
            ->and($present->violations)->toBeEmpty();
    });

    it('resolves Laravel anonymous component directory fallbacks', function (string $relativeDefinition): void {
        $definition = $this->components.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeDefinition);
        (new Filesystem)->ensureDirectoryExists(dirname($definition));
        (new Filesystem)->put($definition, "@props(['title'])");

        expect(lintRequiredComponentProps('<x-card />', $this->viewPath)->violations)->toHaveCount(1);
    })->with([
        'index fallback' => 'card/index.blade.php',
        'same-name fallback' => 'card/card.blade.php',
    ]);

    it('uses Laravel precedence for direct, index, and same-name anonymous views', function (): void {
        $directory = $this->components.DIRECTORY_SEPARATOR.'card';
        (new Filesystem)->ensureDirectoryExists($directory);
        (new Filesystem)->put($this->components.DIRECTORY_SEPARATOR.'card.blade.php', "@props(['direct'])");
        (new Filesystem)->put($directory.DIRECTORY_SEPARATOR.'index.blade.php', "@props(['fromIndex'])");
        (new Filesystem)->put($directory.DIRECTORY_SEPARATOR.'card.blade.php', "@props(['fromSameName'])");

        expect(lintRequiredComponentProps('<x-card direct="yes" />', $this->viewPath)->violations)->toBeEmpty();

        (new Filesystem)->delete($this->components.DIRECTORY_SEPARATOR.'card.blade.php');

        expect(lintRequiredComponentProps('<x-card from-index="yes" />', $this->viewPath)->violations)->toBeEmpty();
    });

    it('stands down for dynamic, package, unresolved, and ambiguous definitions', function (string $source): void {
        (new Filesystem)->put(
            $this->components.DIRECTORY_SEPARATOR.'ambiguous.blade.php',
            "@props(['first']) @props(['second'])",
        );

        expect(lintRequiredComponentProps($source, $this->viewPath)->violations)->toBeEmpty();
    })->with([
        'dynamic' => '<x-dynamic-component :component="$name" />',
        'package' => '<x-vendor::card />',
        'unresolved' => '<x-does-not-exist />',
        'ambiguous declarations' => '<x-ambiguous />',
    ]);

    it('stands down for both conventional class-component candidates', function (string $relativeClass): void {
        (new Filesystem)->put(
            $this->components.DIRECTORY_SEPARATOR.'class-backed.blade.php',
            "@props(['anonymousOnly'])",
        );
        $classPath = $this->componentProject.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'View'
            .DIRECTORY_SEPARATOR.'Components'.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relativeClass);
        (new Filesystem)->ensureDirectoryExists(dirname($classPath));
        (new Filesystem)->put($classPath, '<?php // The linter must not load or execute this file.');

        expect(lintRequiredComponentProps('<x-class-backed />', $this->viewPath)->violations)->toBeEmpty();
    })->with([
        'direct class' => 'ClassBacked.php',
        'Class/Class fallback' => 'ClassBacked/ClassBacked.php',
    ]);

    it('never invokes autoloaders while resolving class-component ambiguity', function (): void {
        (new Filesystem)->put(
            $this->components.DIRECTORY_SEPARATOR.'autoload-trap.blade.php',
            "@props(['title'])",
        );
        $autoloaded = false;
        $loader = static function (string $class) use (&$autoloaded): void {
            if ($class === 'App\\View\\Components\\AutoloadTrap') {
                $autoloaded = true;
            }
        };
        spl_autoload_register($loader);

        try {
            $result = lintRequiredComponentProps('<x-autoload-trap />', $this->viewPath);
        } finally {
            spl_autoload_unregister($loader);
        }

        expect($autoloaded)->toBeFalse()
            ->and($result->violations)->toHaveCount(1);
    });

    it('stands down when the props declaration is not a fully static array shape', function (string $definition): void {
        (new Filesystem)->put($this->components.DIRECTORY_SEPARATOR.'card.blade.php', $definition);

        expect(lintRequiredComponentProps('<x-card />', $this->viewPath)->violations)->toBeEmpty();
    })->with([
        'variable declaration' => '@props($props)',
        'spread declaration' => "@props(['title', ...\$otherProps])",
        'dynamic bare entry' => '@props([$propName])',
        'dynamic default key' => "@props(['title', \$propName => null])",
        'conditional declaration' => "@if(\$enabled) @props(['title']) @endif",
        'invalid default' => "@props(['title' => ])",
    ]);

    it('recognizes array() declarations and a later keyed duplicate as a default', function (): void {
        (new Filesystem)->put(
            $this->components.DIRECTORY_SEPARATOR.'card.blade.php',
            "@props(array('title', 'title' => null, 'body'))",
        );

        $result = lintRequiredComponentProps('<x-card />', $this->viewPath);
        expect($result->violations)->toHaveCount(1);
    });

    it('does not guess a project root outside resources/views', function (): void {
        (new Filesystem)->put($this->components.DIRECTORY_SEPARATOR.'card.blade.php', "@props(['title'])");

        expect(lintRequiredComponentProps('<x-card />', 'templates/page.blade.php')->violations)->toBeEmpty();
    });

    it('preserves a UNC share prefix while resolving the views root', function (): void {
        $method = new ReflectionMethod(ComponentRequiredPropsRule::class, 'resolveViewsRoot');
        $root = $method->invoke(
            new ComponentRequiredPropsRule,
            '\\\\server\\share\\resources\\views\\pages\\home.blade.php',
        );
        $expected = DIRECTORY_SEPARATOR.DIRECTORY_SEPARATOR.'server'.DIRECTORY_SEPARATOR.'share'
            .DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views';

        expect($root)->toBe($expected);
    });

    it('changes its cache context when a local component definition changes', function (): void {
        $definition = $this->components.DIRECTORY_SEPARATOR.'card.blade.php';
        (new Filesystem)->put($definition, "@props(['title'])");
        $rule = new ComponentRequiredPropsRule;
        $originalBasePath = base_path();

        expect($rule)->toBeInstanceOf(ProvidesCacheContext::class);

        try {
            app()->setBasePath($this->componentProject);
            $before = $rule->cacheContext([]);
            (new Filesystem)->put($definition, "@props(['title' => null])");
            $after = $rule->cacheContext([]);
        } finally {
            app()->setBasePath($originalBasePath);
        }

        expect($before)->not->toBe($after)
            ->and($before)->toMatchArray(['schema' => 1, 'files' => 1])
            ->and($after)->toMatchArray(['schema' => 1, 'files' => 1]);
    });
});
