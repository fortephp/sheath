<?php

declare(strict_types=1);

use Forte\Sheath\Files\FileSystem;

it('atomically replaces an existing file without leaving temporary files', function (): void {
    $sandbox = TestViewSandbox::makeInSystemTemp('sheath-atomic-write-');
    $path = $sandbox->file('view.blade.php', 'before');

    try {
        expect(FileSystem::writeFileAtomically($path, 'after'))->toBeTrue()
            ->and(file_get_contents($path))->toBe('after')
            ->and(glob($sandbox->path('.sheath-*')) ?: [])->toBe([]);
    } finally {
        $sandbox->cleanup();
    }
});

it('does not create a file when the destination directory is missing', function (): void {
    $sandbox = TestViewSandbox::makeInSystemTemp('sheath-atomic-write-');
    $path = $sandbox->path('missing/view.blade.php');

    try {
        expect(FileSystem::writeFileAtomically($path, 'content'))->toBeFalse()
            ->and(file_exists($path))->toBeFalse();
    } finally {
        $sandbox->cleanup();
    }
});

it('does not replace a read-only file', function (): void {
    $sandbox = TestViewSandbox::makeInSystemTemp('sheath-atomic-write-');
    $path = $sandbox->file('view.blade.php', 'before');
    chmod($path, 0444);

    try {
        expect(FileSystem::writeFileAtomically($path, 'after'))->toBeFalse()
            ->and(file_get_contents($path))->toBe('before')
            ->and(glob($sandbox->path('.sheath-*')) ?: [])->toBe([]);
    } finally {
        chmod($path, 0666);
        $sandbox->cleanup();
    }
});

it('does not replace a file whose content changed before the atomic swap', function (): void {
    $sandbox = TestViewSandbox::makeInSystemTemp('sheath-atomic-write-');
    $path = $sandbox->file('view.blade.php', 'original');

    try {
        file_put_contents($path, 'external save');

        expect(FileSystem::writeFileAtomicallyIfUnchanged($path, 'original', 'fixed'))->toBeFalse()
            ->and(file_get_contents($path))->toBe('external save')
            ->and(glob($sandbox->path('.sheath-*')) ?: [])->toBe([]);
    } finally {
        $sandbox->cleanup();
    }
});
