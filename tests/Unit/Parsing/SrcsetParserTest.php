<?php

declare(strict_types=1);

use Forte\Sheath\Parsing\SrcsetParser;

it('extracts candidate URLs and ignores their descriptors', function (string $srcset, array $urls): void {
    expect(SrcsetParser::urls($srcset))->toBe($urls);
})->with([
    'density descriptors' => [
        'small.png 1x, large.png 2x',
        ['small.png', 'large.png'],
    ],
    'width descriptors' => [
        " small.png\t400w,\nlarge.png 800w ",
        ['small.png', 'large.png'],
    ],
    'no descriptors' => [
        'small.png, large.png',
        ['small.png', 'large.png'],
    ],
]);

it('keeps commas inside a URL token', function (): void {
    expect(SrcsetParser::urls(
        'data:image/svg+xml,http://www.w3.org/2000/svg 1x, large.png 2x'
    ))->toBe([
        'data:image/svg+xml,http://www.w3.org/2000/svg',
        'large.png',
    ]);
});

it('does not split on commas inside descriptor parentheses', function (): void {
    expect(SrcsetParser::urls('a.png type(foo,bar), b.png 2x'))
        ->toBe(['a.png', 'b.png']);
});

it('tolerates empty candidates and trailing commas', function (): void {
    expect(SrcsetParser::urls(',,, a.png,, , b.png 2x,,'))
        ->toBe(['a.png', 'b.png']);
});

it('distinguishes valid responsive candidates from malformed descriptors', function (string $srcset, bool $valid): void {
    expect(SrcsetParser::hasValidCandidate($srcset))->toBe($valid);
})->with([
    ['small.jpg 320w, large.jpg 640w', true],
    ['photo.jpg', true],
    ['small.jpg .5x', true],
    ['photo.jpg 800w 600h', true],
    ['small.jpg 0w', false],
    ['small.jpg 0x', false],
    ['small.jpg -1x', false],
    ['small.jpg 320w 2x', false],
    ['small.jpg 320w 640w', false],
    [',,,', false],
    ['broken.jpg 0w, valid.jpg 2x', true],
]);
