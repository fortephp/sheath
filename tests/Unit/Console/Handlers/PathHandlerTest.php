<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Console\Handlers\PathHandler;
use Forte\Sheath\Files\FileFinder;

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/sheath-paths-'.bin2hex(random_bytes(6));

    foreach (['emails', 'components', 'legacy'] as $directory) {
        mkdir($this->root.'/resources/views/'.$directory, 0755, true);
        file_put_contents($this->root."/resources/views/{$directory}/page.blade.php", '<p>x</p>');
    }

    file_put_contents($this->root.'/resources/views/home.blade.php', '<p>x</p>');

    $this->app->setBasePath($this->root);

    $this->handler = new PathHandler(new FileFinder);
    $this->ignore = ['vendor/**', 'storage/**', 'resources/views/emails/**'];

    $this->relative = function (array $paths, bool $named): array {
        $files = array_map(
            fn (string $file): string => str_replace('\\', '/', substr($file, strlen(realpath($this->root) ?: $this->root) + 1)),
            $this->handler->findFiles($paths, $this->ignore, $named)
        );
        sort($files);

        return $files;
    };
});

afterEach(function (): void {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($this->root);
});

describe('resolving which paths to lint', function (): void {
    it('prefers the paths named on the command line', function (): void {
        $config = Config::make()->setPaths(['resources/views']);

        expect($this->handler->resolvePaths(['app/views'], [], $config))->toBe(['app/views']);
    });

    it('expands the Laravel shortcuts', function (): void {
        $config = Config::make()->setPaths(['resources/views']);

        expect($this->handler->resolvePaths([], ['components' => true, 'emails' => true], $config))
            ->toBe(['resources/views/components', 'resources/views/emails']);
    });

    it('falls back to the configured paths, then to resources/views', function (): void {
        expect($this->handler->resolvePaths([], [], Config::make()->setPaths(['app/views'])))->toBe(['app/views'])
            ->and($this->handler->resolvePaths([], [], Config::make()))->toBe(['resources/views']);
    });

    it('reports whether anything was named at all', function (): void {
        expect($this->handler->namedPaths([], []))->toBe([])
            ->and($this->handler->namedPaths([], ['emails' => true]))->toBe(['resources/views/emails'])
            ->and($this->handler->namedPaths(['a'], ['views' => true]))->toBe(['a', 'resources/views']);
    });
});

describe('ignore patterns against a path the command line named', function (): void {
    it('still applies them to a default run', function (): void {
        expect(($this->relative)(['resources/views'], false))
            ->toBe(['resources/views/components/page.blade.php', 'resources/views/home.blade.php', 'resources/views/legacy/page.blade.php']);
    });

    it('steps aside for an explicit argument too', function (): void {
        expect(($this->relative)(['resources/views/emails'], true))
            ->toBe(['resources/views/emails/page.blade.php']);
    });

    it('keeps applying patterns that exclude something inside the named path', function (): void {
        $this->ignore = ['resources/views/legacy/**'];

        expect(($this->relative)(['resources/views'], true))
            ->toBe(['resources/views/components/page.blade.php', 'resources/views/emails/page.blade.php', 'resources/views/home.blade.php']);
    });

    it('keeps a wildcard pattern whose literal prefix is only an ancestor of the named path', function (): void {
        $this->ignore = ['resources/*/emails/**'];

        expect(($this->relative)(['resources/views'], true))
            ->toBe(['resources/views/components/page.blade.php', 'resources/views/home.blade.php', 'resources/views/legacy/page.blade.php']);
    });

    it('does not let one named path relax the ignores of another', function (): void {
        $files = ($this->relative)(['resources/views/components', 'resources/views/emails'], true);

        expect($files)->toBe([
            'resources/views/components/page.blade.php',
            'resources/views/emails/page.blade.php',
        ]);
    });
});
