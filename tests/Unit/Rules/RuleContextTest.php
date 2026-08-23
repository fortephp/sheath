<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\ViolationCollector;
use Forte\Sheath\Rules\RuleContext;

it('reports paired elements over their opening tags without including descendants', function (): void {
    $source = '<section data-label="wide > narrow"><p>Child</p></section>';
    $document = Document::parse($source);
    $element = $document->findElementsByName('section')->first();
    $collector = new ViolationCollector;
    $context = new RuleContext(
        document: $document,
        filePath: 'test.blade.php',
        config: Config::make(),
        collector: $collector,
        ruleSeverity: Severity::WARNING,
        ruleId: 'test-element-range',
    );

    expect($element)->toBeInstanceOf(ElementNode::class);
    if (! $element instanceof ElementNode) {
        throw new RuntimeException('Fixture element was not parsed.');
    }
    $context->report($element, 'Opening-tag finding.');

    $violation = $collector->all()[0];
    $reported = substr(
        $source,
        $violation->start->offset,
        $violation->end->offset - $violation->start->offset,
    );

    expect($reported)->toBe('<section data-label="wide > narrow">');
});

it('reports modifier-style directive attributes through the opening tag', function (): void {
    $source = '<column @navigate.slideFromRigt(\'/detail\')><text>Child</text></column>';
    $document = Document::parse($source);
    $element = $document->findElementsByName('column')->first();
    $collector = new ViolationCollector;
    $context = new RuleContext(
        document: $document,
        filePath: 'test.blade.php',
        config: Config::make(),
        collector: $collector,
        ruleSeverity: Severity::WARNING,
        ruleId: 'test-modifier-attribute-range',
    );

    expect($element)->toBeInstanceOf(ElementNode::class);
    if (! $element instanceof ElementNode) {
        throw new RuntimeException('Fixture element was not parsed.');
    }
    $context->report($element, 'Opening-tag finding.');

    $violation = $collector->all()[0];
    $reported = substr(
        $source,
        $violation->start->offset,
        $violation->end->offset - $violation->start->offset,
    );

    expect($reported)->toBe('<column @navigate.slideFromRigt(\'/detail\')>');
});

it('preserves the exact node range for non-element reports', function (): void {
    $source = '<section data-label="value"><p>Child</p></section>';
    $document = Document::parse($source);
    $element = $document->findElementsByName('section')->first();
    $collector = new ViolationCollector;
    $context = new RuleContext(
        document: $document,
        filePath: 'test.blade.php',
        config: Config::make(),
        collector: $collector,
        ruleSeverity: Severity::WARNING,
        ruleId: 'test-attribute-range',
    );

    expect($element)->toBeInstanceOf(ElementNode::class);
    if (! $element instanceof ElementNode) {
        throw new RuntimeException('Fixture element was not parsed.');
    }
    $context->report($element->tagName(), 'Tag-name finding.');

    $violation = $collector->all()[0];
    $reported = substr(
        $source,
        $violation->start->offset,
        $violation->end->offset - $violation->start->offset,
    );

    expect($reported)->toBe('section');
});

it('keeps explicit reportAt ranges unchanged', function (): void {
    $source = '<section><p>Child</p></section>';
    $document = Document::parse($source);
    $element = $document->findElementsByName('section')->first();
    $collector = new ViolationCollector;
    $context = new RuleContext(
        document: $document,
        filePath: 'test.blade.php',
        config: Config::make(),
        collector: $collector,
        ruleSeverity: Severity::WARNING,
        ruleId: 'test-explicit-range',
    );

    expect($element)->toBeInstanceOf(ElementNode::class);
    if (! $element instanceof ElementNode) {
        throw new RuntimeException('Fixture element was not parsed.');
    }
    $context->reportAt(
        Position::fromOffset($document, $element->startOffset()),
        Position::fromOffset($document, $element->endOffset()),
        'Whole-element finding.',
    );

    $violation = $collector->all()[0];
    $reported = substr(
        $source,
        $violation->start->offset,
        $violation->end->offset - $violation->start->offset,
    );

    expect($reported)->toBe($source);
});
