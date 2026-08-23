<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Sheath\Rules\Concerns\TraversesRenderedTree;

final class RenderedTreeTraversalProbe
{
    use TraversesRenderedTree;

    /** @param array<string> $ignoredElementNames */
    public function hasContentAfter(Node $node, ElementNode $within, array $ignoredElementNames = []): bool
    {
        return $this->hasRenderedContentAfter($node, $within, $ignoredElementNames);
    }
}

/** @return array{ElementNode, ElementNode} */
function renderedTreeBodyAndFirstScript(string $source): array
{
    $body = null;
    $script = null;

    foreach (Document::parse($source)->getElements() as $element) {
        $tagName = strtolower($element->tagNameText());
        $body ??= $tagName === 'body' ? $element : null;
        $script ??= $tagName === 'script' ? $element : null;
    }

    expect($body)->toBeInstanceOf(ElementNode::class)
        ->and($script)->toBeInstanceOf(ElementNode::class);

    return [$body, $script];
}

it('finds rendered content after a node within a container', function (string $source): void {
    [$body, $script] = renderedTreeBodyAndFirstScript($source);

    expect((new RenderedTreeTraversalProbe)->hasContentAfter($script, $body))->toBeTrue();
})->with([
    'element' => '<body><script src="a.js"></script><main>Content</main></body>',
    'text' => '<body><script src="a.js"></script>Content</body>',
    'echo' => '<body><script src="a.js"></script>{{ $content }}</body>',
]);

it('can ignore whitespace, comments, and named trailing element subtrees', function (): void {
    [$body, $script] = renderedTreeBodyAndFirstScript(
        "<body><script src=\"a.js\"></script>\n<!-- note --><script>window.boot()</script></body>"
    );

    expect((new RenderedTreeTraversalProbe)->hasContentAfter($script, $body, ['script']))->toBeFalse();
});

it('ignores non-output PHP but detects PHP output', function (string $source, bool $expected): void {
    [$body, $script] = renderedTreeBodyAndFirstScript($source);

    expect((new RenderedTreeTraversalProbe)->hasContentAfter($script, $body))->toBe($expected);
})->with([
    'expression directive' => ['<body><script></script>@php($loaded = true)</body>', false],
    'block directive' => ['<body><script></script>@php $loaded = true; @endphp</body>', false],
    'php tag' => ['<body><script></script><?php $loaded = true; ?></body>', false],
    'php output' => ['<body><script></script><?php echo $content; ?></body>', true],
]);
