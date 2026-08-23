<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Results\Violation;
use Forte\Sheath\Tests\TestCase;

uses(TestCase::class)->in(__DIR__);

/** @return list<Violation> */
function lintReactiveRule(string $ruleId, string $source, string $severity = 'error'): array
{
    $config = Config::make([
        'preset' => 'empty',
        'rules' => [$ruleId => $severity],
    ]);

    return array_values(app(Linter::class)->lint(
        $source,
        'resources/views/reactive-probe.blade.php',
        $config,
    )->violations);
}

/** @return list<Violation> */
function lintReactiveTemplate(string $source): array
{
    return lintReactiveRule('blade-reactive-template-structure', $source);
}

/** @return list<Violation> */
function lintAlpineForKey(string $source): array
{
    return lintReactiveRule('blade-alpine-for-key-integrity', $source, 'warning');
}

/** @return list<Violation> */
function lintAlpineDirectiveIntegrity(string $source): array
{
    return lintReactiveRule('blade-alpine-directive-integrity', $source);
}

/** @return list<Violation> */
function lintLivewireLoopKey(string $source): array
{
    return lintReactiveRule('blade-livewire-loop-key-integrity', $source);
}

/** @return list<Violation> */
function lintReactiveDirectiveConflicts(string $source): array
{
    return lintReactiveRule('blade-reactive-directive-conflicts', $source);
}

/** @return list<Violation> */
function lintLivewireDirectiveIntegrity(string $source): array
{
    return lintReactiveRule('blade-livewire-directive-integrity', $source);
}

/** @return list<Violation> */
function lintCoreReactivePreset(string $source): array
{
    $config = Config::make([
        'preset' => 'empty',
        'rules' => [
            'blade-alpine-directive-integrity' => 'error',
            'blade-alpine-for-key-integrity' => 'warning',
            'blade-reactive-template-structure' => 'error',
            'blade-livewire-directive-integrity' => 'error',
            'blade-livewire-loop-key-integrity' => 'error',
            'blade-reactive-directive-conflicts' => 'error',
        ],
    ]);

    return array_values(app(Linter::class)->lint(
        $source,
        'resources/views/reactive-probe.blade.php',
        $config,
    )->violations);
}

final readonly class TestViewSandbox
{
    public function __construct(public string $root) {}

    public static function make(string $prefix = 'test-views-lint-'): self
    {
        $root = base_path($prefix.uniqid());
        if (! is_dir($root)) {
            mkdir($root, 0755, true);
        }

        return new self($root);
    }

    public static function makeInSystemTemp(string $prefix = 'sheath-test-'): self
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.$prefix.uniqid();
        if (! is_dir($root)) {
            mkdir($root, 0755, true);
        }

        return new self($root);
    }

    public static function makeWithEmails(): self
    {
        $sandbox = self::make('test-views-nested-');

        $emailsDirectory = $sandbox->root.'/emails';
        if (! is_dir($emailsDirectory)) {
            mkdir($emailsDirectory, 0755, true);
        }

        return $sandbox;
    }

    public function path(string $relativePath): string
    {
        return $this->root.'/'.ltrim($relativePath, '/');
    }

    public function file(string $relativePath, string $contents): string
    {
        $absolutePath = $this->path($relativePath);
        $directory = dirname($absolutePath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($absolutePath, $contents);

        return $absolutePath;
    }

    /** @return array<string> */
    public function bladeFiles(int $count, string $content): array
    {
        $files = [];
        for ($i = 0; $i < $count; $i++) {
            $files[] = $this->file("file-{$i}.blade.php", $content);
        }

        return $files;
    }

    public function cleanup(): void
    {
        if (! is_dir($this->root)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();

            if ($item->isDir()) {
                rmdir($path);

                continue;
            }

            unlink($path);
        }

        rmdir($this->root);
    }
}

/** @param callable(TestViewSandbox): void $callback */
function withSandbox(callable $callback, ?TestViewSandbox $sandbox = null): void
{
    $sandbox ??= TestViewSandbox::make();

    try {
        $callback($sandbox);
    } finally {
        $sandbox->cleanup();
    }
}

/** @param callable(string): mixed $callback */
function withTempFile(callable $callback, string $suffix = '', string $prefix = 'sheath-test-', bool $inBasePath = false): mixed
{
    $directory = $inBasePath ? base_path() : sys_get_temp_dir();
    $path = $directory.DIRECTORY_SEPARATOR.$prefix.uniqid().$suffix;

    try {
        return $callback($path);
    } finally {
        if (file_exists($path)) {
            unlink($path);
        }
    }
}

/** @param callable(string): mixed $callback */
function withTempDir(callable $callback, string $prefix = 'sheath-test-dir-'): mixed
{
    $sandbox = TestViewSandbox::makeInSystemTemp($prefix);

    try {
        return $callback($sandbox->root);
    } finally {
        $sandbox->cleanup();
    }
}
