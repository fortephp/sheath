<?php

declare(strict_types=1);

use Forte\Sheath\Files\PathResolver;

it('uses project-relative paths for files inside the project', function (): void {
    $root = rtrim(PathResolver::normalizeSeparators(PathResolver::projectRoot()), '/');

    expect(PathResolver::toRelativePath($root.'/resources/views/home.blade.php'))
        ->toBe('resources/views/home.blade.php');
});

it('uses normalized absolute paths for files outside the project', function (): void {
    $root = rtrim(PathResolver::normalizeSeparators(PathResolver::projectRoot()), '/');
    $outside = PathResolver::normalizeSeparators(dirname($root).'/external/view.blade.php');

    expect(PathResolver::toRelativePath($outside))->toBe($outside);
});

it('normalizes dot segments before checking project containment', function (): void {
    $root = rtrim(PathResolver::normalizeSeparators(PathResolver::projectRoot()), '/');
    $parent = PathResolver::normalizeSeparators(dirname($root));

    expect(PathResolver::toRelativePath($root.'/resources/../views/home.blade.php'))
        ->toBe('views/home.blade.php')
        ->and(PathResolver::toRelativePath($root.'/../outside.blade.php'))
        ->toBe($parent.'/outside.blade.php');
});
