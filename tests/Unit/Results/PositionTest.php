<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Sheath\Results\Position;

it('creates position from node start', function (): void {
    $document = Document::parse('<div class="test">content</div>');
    $node = $document->firstChild();

    $position = Position::fromNode($node, end: false);

    expect($position->offset)->toBe(0)
        ->and($position->line)->toBe(1)
        ->and($position->character)->toBe(1);
});

it('creates position from node end', function (): void {
    $source = '<div class="test">content</div>';
    $document = Document::parse($source);
    $node = $document->firstChild();

    $position = Position::fromNode($node, end: true);

    expect($position->offset)->toBe(strlen($source))
        ->and($position->line)->toBe(1)
        ->and($position->character)->toBe(32)
        ->and(substr($source, $node->startOffset(), $position->offset - $node->startOffset()))->toBe($source);
});

it('handles multiline nodes correctly', function (): void {
    $document = Document::parse("<div>\n    <span>text</span>\n</div>");
    $node = $document->firstChild();

    expect(Position::fromNode($node, end: false)->line)->toBe(1)
        ->and(Position::fromNode($node, end: true)->line)->toBe(3);
});

it('reports Unicode code-point columns while preserving byte offsets', function (string $prefix): void {
    $source = "<p>{$prefix}<span>text</span></p>";
    $document = Document::parse($source);
    $span = $document->getElements()->first(
        fn ($element): bool => strtolower($element->tagNameText()) === 'span'
    );

    $position = Position::fromNode($span);

    expect($position->offset)->toBe(strlen("<p>{$prefix}"))
        ->and($position->line)->toBe(1)
        ->and($position->character)->toBe(5);
})->with([
    'ASCII' => 'x',
    'accented code point' => 'é',
    'supplementary code point' => '🙂',
]);

it('reports Unicode columns after a newline', function (): void {
    $document = Document::parse("<div>\né<span>x</span></div>");
    $span = $document->getElements()->first(
        fn ($element): bool => strtolower($element->tagNameText()) === 'span'
    );

    expect(Position::fromNode($span)->line)->toBe(2)
        ->and(Position::fromNode($span)->character)->toBe(2);
});

it('falls back to a byte column after invalid UTF-8 without throwing', function (): void {
    $document = Document::parse("<p>\xFF<span>x</span></p>");
    $span = $document->getElements()->first(
        fn ($element): bool => strtolower($element->tagNameText()) === 'span'
    );

    expect(Position::fromNode($span)->offset)->toBe(4)
        ->and(Position::fromNode($span)->line)->toBe(1)
        ->and(Position::fromNode($span)->character)->toBe(5);
});
