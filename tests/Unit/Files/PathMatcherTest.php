<?php

declare(strict_types=1);

use Forte\Sheath\Files\PathMatcher;

describe('PathMatcher', function (): void {
    it('matches a plain fragment at any segment boundary', function (): void {
        expect(PathMatcher::matches('views/native', 'resources/views/native/home.blade.php'))->toBeTrue()
            ->and(PathMatcher::matches('views/native', '/var/www/app/resources/views/native/home.blade.php'))->toBeTrue()
            ->and(PathMatcher::matches('views/native', 'resources/views/web/home.blade.php'))->toBeFalse();
    });

    it('normalises Windows separators in the path before matching', function (): void {
        expect(PathMatcher::matches('views/native', 'C:\\app\\resources\\views\\native\\home.blade.php'))->toBeTrue();
    });

    it('tolerates a trailing slash on the pattern', function (): void {
        expect(PathMatcher::matches('views/native/', 'resources/views/native/home.blade.php'))->toBeTrue();
    });

    it('never matches inside a segment', function (): void {
        expect(PathMatcher::matches('emails', 'resources/views/emails/welcome.blade.php'))->toBeTrue()
            ->and(PathMatcher::matches('emails', 'resources/views/x-emails/welcome.blade.php'))->toBeFalse()
            ->and(PathMatcher::matches('emails', 'resources/views/emails-old/welcome.blade.php'))->toBeFalse();
    });

    it('keeps single-star within one segment', function (): void {
        expect(PathMatcher::matches('views/*.blade.php', 'views/home.blade.php'))->toBeTrue()
            ->and(PathMatcher::matches('views/*.blade.php', 'views/sub/home.blade.php'))->toBeFalse();
    });

    it('lets double-star cross segments', function (): void {
        expect(PathMatcher::matches('views/**', 'views/sub/deep/home.blade.php'))->toBeTrue();
    });

    it('lets a leading double-star match no directories at all', function (): void {
        expect(PathMatcher::matches('**/legacy/**', 'app/nested/legacy/x.blade.php'))->toBeTrue()
            ->and(PathMatcher::matches('**/legacy/**', 'legacy/x.blade.php'))->toBeTrue();
    });

    it('expands balanced brace groups', function (): void {
        expect(PathMatcher::matches('views/{emails,mail}/**', 'views/emails/a.blade.php'))->toBeTrue()
            ->and(PathMatcher::matches('views/{emails,mail}/**', 'views/mail/a.blade.php'))->toBeTrue()
            ->and(PathMatcher::matches('views/{emails,mail}/**', 'views/pages/a.blade.php'))->toBeFalse();
    });

    it('treats a stray brace as a literal character', function (): void {
        expect(PathMatcher::matches('weird{name', 'views/weird{name/a.blade.php'))->toBeTrue();
    });

    it('matches a question mark against one non-separator character', function (): void {
        expect(PathMatcher::matches('v?ews', 'resources/views/a.blade.php'))->toBeTrue()
            ->and(PathMatcher::matches('v?ews', 'resources/vs/a.blade.php'))->toBeFalse();
    });

    it('matches a question mark against one Unicode code point', function (): void {
        expect(PathMatcher::matches('views/?.blade.php', 'views/é.blade.php'))->toBeTrue()
            ->and(PathMatcher::matches('views/?.blade.php', 'views/🙂.blade.php'))->toBeTrue()
            ->and(PathMatcher::matches('views/?.blade.php', 'views/éx.blade.php'))->toBeFalse();
    });

    it('strips a leading slash instead of anchoring', function (): void {
        expect(PathMatcher::matches('/emails/**', 'C:/app/resources/views/emails/x.blade.php'))->toBeTrue();
    });

    it('matches nothing for an empty pattern', function (): void {
        expect(PathMatcher::matches('', 'resources/views/a.blade.php'))->toBeFalse()
            ->and(PathMatcher::matches('/', 'resources/views/a.blade.php'))->toBeFalse();
    });

    it('reports no match for an empty pattern list', function (): void {
        expect(PathMatcher::matchesAny([], 'resources/views/a.blade.php'))->toBeFalse();
    });

    it('reports a match when any pattern in the list matches', function (): void {
        expect(PathMatcher::matchesAny(['emails/**', 'views/native/'], 'resources/views/native/a.blade.php'))->toBeTrue();
    });
});
