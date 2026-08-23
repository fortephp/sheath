<?php

declare(strict_types=1);

use Forte\Sheath\Files\FileFinder;

beforeEach(function (): void {
    $this->fixtures = realpath(__DIR__.'/../../Fixtures/finder');

    $this->keptFrom = function (string $directory, array $patterns): array {
        $kept = array_map(basename(...), (new FileFinder)->find([$directory], $patterns));
        sort($kept);

        return $kept;
    };
});

describe('FileFinder', function (): void {
    it('finds Blade files recursively and deduplicates exact paths', function (): void {
        $finder = new FileFinder;
        $file = $this->fixtures.'/base/file.blade.php';

        expect($finder->find([$this->fixtures.'/base']))
            ->toHaveCount(3)
            ->each->toEndWith('.blade.php')
            ->and($finder->find([$file, $file]))->toBe([realpath($file)]);
    });

    it('evaluates explicit glob paths recursively with shared glob semantics', function (): void {
        $sandbox = TestViewSandbox::makeInSystemTemp('sheath-explicit-glob-');
        $sandbox->file('root.blade.php', '');
        $sandbox->file('root.html', '');
        $sandbox->file('nested/one.blade.php', '');
        $sandbox->file('nested/template.html', '');
        $sandbox->file('nested/deep/two.blade.php', '');
        $sandbox->file('other/three.blade.php', '');
        $sandbox->file('nested/ignored.php', '');

        try {
            $finder = new FileFinder;
            $relative = static function (array $files) use ($sandbox): array {
                $paths = array_map(
                    static fn (string $file): string => str_replace('\\', '/', substr($file, strlen($sandbox->root) + 1)),
                    $files
                );
                sort($paths);

                return $paths;
            };

            expect($relative($finder->find([$sandbox->path('**/*.blade.php')])))->toBe([
                'nested/deep/two.blade.php',
                'nested/one.blade.php',
                'other/three.blade.php',
                'root.blade.php',
            ])->and($relative($finder->find([$sandbox->path('*.blade.php')])))->toBe([
                'root.blade.php',
            ])->and($relative($finder->find([$sandbox->path('**/*.html')])))->toBe([
                'nested/template.html',
                'root.html',
            ])->and($relative($finder->find([$sandbox->path('{nested,other}/**/*.blade.php')])))->toBe([
                'nested/deep/two.blade.php',
                'nested/one.blade.php',
                'other/three.blade.php',
            ]);
        } finally {
            $sandbox->cleanup();
        }
    });

    it('evaluates relative explicit glob paths from the working directory', function (): void {
        $finder = new FileFinder;
        $files = $finder->find(['tests/Fixtures/finder/base/**/*.blade.php']);

        expect(array_map(basename(...), $files))->toContain(
            'file.blade.php',
            'home.blade.php',
            'layout.blade.php',
        );
    });

    it('does not use an existing directory that only prefixes the glob segment as its search root', function (): void {
        $sandbox = TestViewSandbox::makeInSystemTemp('sheath-glob-prefix-');
        $sandbox->file('views/inside.blade.php', '');
        $sibling = $sandbox->file('views-home.blade.php', '');

        try {
            expect((new FileFinder)->find([$sandbox->path('views*.blade.php')]))->toBe([
                realpath($sibling),
            ]);
        } finally {
            $sandbox->cleanup();
        }
    });

    it('evaluates globs whose search root needs canonicalizing', function (): void {
        $finder = new FileFinder;
        $pattern = $this->fixtures.'/base/views/../**/*.blade.php';

        expect(array_map(basename(...), $finder->find([$pattern])))->toContain(
            'file.blade.php',
            'home.blade.php',
            'layout.blade.php',
        );
    });

    it('handles empty and invalid paths', function (): void {
        $finder = new FileFinder;

        expect($finder->find([]))->toBe([])
            ->and($finder->find(['/path/that/does/not/exist']))->toBe([]);
    });

    it('lets ** match zero directories as well as several', function (): void {
        $views = $this->fixtures.'/glob-semantics/resources/views';

        expect(($this->keptFrom)($views, ['**/*.generated.blade.php']))->toBe([
            'nested.blade.php',
            'notice.blade.php',
            'old.blade.php',
            'root.blade.php',
            'welcome.blade.php',
        ]);
    });

    it('keeps a single wildcard inside one path segment', function (): void {
        $root = $this->fixtures.'/glob-semantics';

        expect(($this->keptFrom)($root, ['resources/*/legacy/**']))
            ->not->toContain('old.blade.php')
            ->and(($this->keptFrom)($root, ['resources/*/old.blade.php']))
            ->toContain('old.blade.php');
    });

    it('normalizes project-relative ignore prefixes containing a single-segment wildcard', function (): void {
        $views = $this->fixtures.'/glob-semantics/resources/views';
        $pattern = 'tests/Fixtures/finder/glob-semantics/resources/*/emails/**';

        expect(($this->keptFrom)($views, [$pattern]))
            ->not->toContain('welcome.blade.php')
            ->toContain('notice.blade.php')
            ->toContain('root.blade.php');
    });

    it('expands brace alternatives in directories and filenames', function (): void {
        $views = $this->fixtures.'/glob-semantics/resources/views';
        $kept = ($this->keptFrom)($views, ['{emails,mail}/**']);

        expect($kept)->not->toContain('welcome.blade.php')
            ->not->toContain('notice.blade.php')
            ->toContain('root.blade.php')
            ->and(($this->keptFrom)($views, ['**/*.{generated,compiled}.blade.php']))
            ->not->toContain('root.generated.blade.php');
    });

    it('treats unbalanced braces as literals', function (): void {
        $views = $this->fixtures.'/glob-semantics/resources/views';

        expect(($this->keptFrom)($views, ['legacy{/**']))
            ->toContain('old.blade.php')
            ->toContain('root.blade.php');
    });

    it('does not let ignore patterns cross segment boundaries', function (): void {
        $views = $this->fixtures.'/segment-boundaries/views';
        $kept = ($this->keptFrom)($views, ['emails/**', 'ignored/**']);

        expect($kept)->toContain('leak.blade.php')
            ->toContain('deep-leak.blade.php')
            ->toContain('boundary-keep.blade.php')
            ->not->toContain('welcome.blade.php')
            ->not->toContain('deep.blade.php')
            ->not->toContain('ignored.blade.php');
    });

    it('anchors leading-slash patterns to the search root', function (): void {
        $views = $this->fixtures.'/segment-boundaries/views';
        $kept = ($this->keptFrom)($views, ['/emails/**']);

        expect($kept)->not->toContain('welcome.blade.php')
            ->toContain('deep.blade.php')
            ->toContain('leak.blade.php')
            ->and(($this->keptFrom)($this->fixtures.'/dual-emails', ['/emails/**']))
            ->not->toContain('root-email.blade.php')
            ->toContain('nested-email.blade.php');
    });

    it('matches basename patterns without matching partial basenames', function (): void {
        $views = $this->fixtures.'/segment-boundaries/views';
        $kept = ($this->keptFrom)($views, ['*.generated.blade.php']);

        expect($kept)->not->toContain('page.generated.blade.php')
            ->not->toContain('inner.generated.blade.php')
            ->toContain('inner.generated.blade.php.src.blade.php');
    });
});
