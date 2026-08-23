<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Components\ComponentSemanticRewriter;
use Forte\Sheath\Components\SemanticDocumentMap;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Exceptions\ConfigurationException;
use Forte\Sheath\Fixer;
use Forte\Sheath\Linter;
use Forte\Sheath\Parsing\BladeParserOptions;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Rules\RuleRegistry;

final class SemanticOpeningFixProbeRule extends AbstractRule
{
    public function getId(): string
    {
        return 'semantic-opening-fix-probe';
    }

    public function getDescription(): string
    {
        return 'Exercises reportAt and child-node fixes under semantic component mappings.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BEST_PRACTICES;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $element = $document->queryElements()->first();
        if (! $element instanceof ElementNode) {
            return;
        }

        $opening = $element->renderOpeningTag();
        $closingLength = str_ends_with($opening, '/>') ? 2 : 1;
        $insertOffset = $element->startOffset() + strlen($opening) - $closingLength;
        $context->reportAt(
            Position::fromOffset($document, $element->startOffset()),
            Position::fromOffset($document, $element->endOffset()),
            'reportAt opening fix',
            new Fix($insertOffset, $insertOffset, ' data-report-at="fixed"'),
        );

        $attribute = $element->attribute('data-probe');
        if ($attribute !== null) {
            $context->report(
                $element->tagName(),
                'attribute-node opening fix',
                new Fix($attribute->startOffset(), $attribute->endOffset(), 'data-probe="fixed"'),
            );
        }
    }
}

function lintWithComponentMapping(
    string $source,
    array $rules,
    array $mappings = ['x-button' => 'button'],
): LintResult {
    $config = Config::make([
        'rules' => $rules,
        'componentMappings' => $mappings,
    ]);

    return (new Linter(RuleRegistry::withBuiltInRules()))
        ->lint($source, 'resources/views/page.blade.php', $config);
}

/** @param array<string, string> $mappings */
function semanticDocumentWithMapping(string $source, array $mappings): Document
{
    $parserOptions = BladeParserOptions::normalize(null);
    $original = Document::parse($source, $parserOptions);

    return ComponentSemanticRewriter::rewrite($original, $mappings, $parserOptions)->semanticDocument();
}

describe('component semantic mappings', function (): void {
    it('runs html semantics and Blade component checks against isolated documents', function (): void {
        $result = lintWithComponentMapping('<x-button></x-button>', [
            'best-practices-button-type' => 'error',
            'blade-component-self-closing' => 'error',
        ]);

        expect(array_column($result->violations, 'ruleId'))->toEqualCanonicalizing([
            'best-practices-button-type',
            'blade-component-self-closing',
        ]);

        foreach ($result->violations as $violation) {
            expect($violation->start->offset)->toBe(0)
                ->and($violation->getLine())->toBe(1)
                ->and($violation->getColumn())->toBe(1);
        }

        $semantic = collect($result->violations)->firstWhere('ruleId', 'best-practices-button-type');
        expect($semantic?->hasFixAvailable())->toBeFalse();
    });

    it('maps opening-tag diagnostic endpoints back to the original component', function (): void {
        $source = '<x-button><span>Child</span></x-button>';
        $result = lintWithComponentMapping($source, [
            'best-practices-button-type' => 'error',
        ]);

        expect($result->violations)->toHaveCount(1);

        $violation = $result->violations[0];

        expect($violation->start->offset)->toBe(0)
            ->and($violation->end->offset)->toBe(strlen('<x-button>'))
            ->and(substr(
                $source,
                $violation->start->offset,
                $violation->end->offset - $violation->start->offset,
            ))->toBe('<x-button>');
    });

    it('preserves original offsets and safe fixes for native elements after a differently sized mapping', function (): void {
        $source = '<html><body><x-button type="button"></x-button><button></button></body></html>';
        $result = lintWithComponentMapping($source, [
            'best-practices-button-type' => 'error',
        ]);

        expect($result->violations)->toHaveCount(1);
        $violation = $result->violations[0];
        $expectedOffset = strpos($source, '<button>');

        expect($violation->start->offset)->toBe($expectedOffset)
            ->and($violation->getLine())->toBe(1)
            ->and($violation->getColumn())->toBe($expectedOffset + 1)
            ->and($violation->hasFixAvailable())->toBeTrue();

        $fixed = (new Fixer)->applyFixes($source, [$violation->fix])->content;
        expect($fixed)->toBe('<html><body><x-button type="button"></x-button><button type="button"></button></body></html>');
    });

    it('keeps a native sibling separate after a self-closing component maps to a non-void element', function (): void {
        $source = '<x-button type="button" /><button></button>';
        $semantic = semanticDocumentWithMapping($source, ['x-button' => 'button']);
        $buttons = $semantic->queryElements('button')->values()->all();
        $semanticSiblingOffset = strpos($semantic->source(), '<button>', 1);

        expect($semantic->source())->toBe('<button type="button" /><button></button>')
            ->and($buttons)->toHaveCount(2)
            ->and($buttons[0])->toBeInstanceOf(ElementNode::class)
            ->and($buttons[0]->isSelfClosing())->toBeTrue()
            ->and($buttons[0]->startOffset())->toBe(0)
            ->and($buttons[1]->getParent())->toBeNull()
            ->and($buttons[1]->startOffset())->toBe($semanticSiblingOffset);

        $result = lintWithComponentMapping($source, ['best-practices-button-type' => 'error']);
        $originalSiblingOffset = strpos($source, '<button>');

        expect($result->violations)->toHaveCount(1)
            ->and($result->violations[0]->start->offset)->toBe($originalSiblingOffset)
            ->and($result->violations[0]->fix?->startOffset)->toBeGreaterThanOrEqual($originalSiblingOffset);

        $fixed = (new Fixer)->applyFixes(
            $source,
            array_filter([$result->violations[0]->fix]),
            includeDangerous: true,
        )->content;
        expect($fixed)->toBe('<x-button type="button" /><button type="button"></button>');
    });

    it('applies inline suppressions using original component positions', function (): void {
        $source = "{{-- sheath-disable-next-line best-practices-button-type --}}\n<x-button />";
        $result = lintWithComponentMapping($source, [
            'best-practices-button-type' => 'error',
        ]);

        expect($result->violations)->toBeEmpty();
    });

    it('retains ordinary reportAt fixes when the configured mapping is unused', function (): void {
        $registry = new RuleRegistry;
        $registry->register(new SemanticOpeningFixProbeRule);
        $source = '<div data-probe="old"></div>';
        $result = (new Linter($registry))->lint(
            $source,
            'resources/views/page.blade.php',
            Config::make([
                'componentMappings' => ['x-button' => 'button'],
                'rules' => ['semantic-opening-fix-probe' => 'warning'],
            ]),
        );
        $reportAt = collect($result->violations)->firstWhere('message', 'reportAt opening fix');

        expect($result->violations)->toHaveCount(2)
            ->and($reportAt?->fix)->not->toBeNull();

        $fixed = (new Fixer)->applyFixes($source, array_filter([$reportAt?->fix]))->content;
        expect($fixed)->toBe('<div data-probe="old" data-report-at="fixed"></div>');
    });

    it('reuses the authored document when configured mappings do not match', function (): void {
        $parserOptions = BladeParserOptions::normalize(null);
        $document = Document::parse('<div>Unmapped</div>', $parserOptions);

        $map = ComponentSemanticRewriter::rewrite(
            $document,
            ['x-button' => 'button'],
            $parserOptions,
        );

        expect($map->semanticDocument())->toBe($document);
    });

    it('maps growing, shrinking, and boundary offsets exactly', function (): void {
        $original = Document::parse('AAabcBBwxyzCC');
        $semantic = Document::parse('AAQBBRSTUVWCC');
        $map = new SemanticDocumentMap($original, $semantic, [
            ['originalStart' => 2, 'originalEnd' => 5, 'semanticStart' => 2, 'semanticEnd' => 3],
            ['originalStart' => 7, 'originalEnd' => 11, 'semanticStart' => 5, 'semanticEnd' => 11],
        ], []);

        expect($map->originalPosition(0)->offset)->toBe(0)
            ->and($map->originalPosition(2)->offset)->toBe(2)
            ->and($map->originalPosition(3)->offset)->toBe(5)
            ->and($map->originalPosition(4)->offset)->toBe(6)
            ->and($map->originalPosition(5)->offset)->toBe(7)
            ->and($map->originalPosition(6)->offset)->toBe(8)
            ->and($map->originalPosition(9)->offset)->toBe(11)
            ->and($map->originalPosition(11)->offset)->toBe(11)
            ->and($map->originalPosition(12)->offset)->toBe(12)
            ->and($map->originalFix(new Fix(3, 11, ''))->startOffset)->toBe(5)
            ->and($map->originalFix(new Fix(3, 11, ''))->endOffset)->toBe(11);
    });

    it('preserves deletion and adjacent-edit boundary semantics', function (): void {
        $original = Document::parse('abcdef');
        $semantic = Document::parse('aXYZf');
        $map = new SemanticDocumentMap($original, $semantic, [
            ['originalStart' => 1, 'originalEnd' => 3, 'semanticStart' => 1, 'semanticEnd' => 1],
            ['originalStart' => 3, 'originalEnd' => 5, 'semanticStart' => 1, 'semanticEnd' => 4],
        ], []);

        expect($map->originalPosition(1)->offset)->toBe(3)
            ->and($map->originalPosition(2)->offset)->toBe(4)
            ->and($map->originalPosition(4)->offset)->toBe(5)
            ->and($map->originalPosition(5)->offset)->toBe(6);
    });

    it('uses half-open mapped-opening ranges for insertions and replacements', function (): void {
        $document = Document::parse('0123456789AB');
        $map = new SemanticDocumentMap($document, $document, [], [
            ['start' => 2, 'end' => 5],
            ['start' => 7, 'end' => 10],
        ]);

        expect($map->fixTouchesMappedComponentOpening(new Fix(2, 2, 'x')))->toBeTrue()
            ->and($map->fixTouchesMappedComponentOpening(new Fix(4, 4, 'x')))->toBeTrue()
            ->and($map->fixTouchesMappedComponentOpening(new Fix(5, 5, 'x')))->toBeFalse()
            ->and($map->fixTouchesMappedComponentOpening(new Fix(7, 7, 'x')))->toBeTrue()
            ->and($map->fixTouchesMappedComponentOpening(new Fix(10, 10, 'x')))->toBeFalse()
            ->and($map->fixTouchesMappedComponentOpening(new Fix(0, 2, 'x')))->toBeFalse()
            ->and($map->fixTouchesMappedComponentOpening(new Fix(1, 3, 'x')))->toBeTrue()
            ->and($map->fixTouchesMappedComponentOpening(new Fix(5, 7, 'x')))->toBeFalse()
            ->and($map->fixTouchesMappedComponentOpening(new Fix(6, 8, 'x')))->toBeTrue()
            ->and($map->fixTouchesMappedComponentOpening(new Fix(10, 11, 'x')))->toBeFalse();
    });

    it('indexes many semantic edits without changing endpoint mappings', function (): void {
        $count = 5000;
        $originalSource = str_repeat('ab', $count);
        $semanticSource = str_repeat('x', $count);
        $edits = [];

        for ($index = 0; $index < $count; $index++) {
            $edits[] = [
                'originalStart' => $index * 2,
                'originalEnd' => ($index * 2) + 2,
                'semanticStart' => $index,
                'semanticEnd' => $index + 1,
            ];
        }

        $map = new SemanticDocumentMap(
            Document::parse($originalSource),
            Document::parse($semanticSource),
            $edits,
            [],
        );

        foreach ([0, 1, 100, 999, 2500, 4999, 5000] as $semanticOffset) {
            expect($map->originalPosition($semanticOffset)->offset)->toBe($semanticOffset * 2);
        }
    });

    it('withholds reportAt and child-node fixes that touch a mapped component opening tag', function (): void {
        $registry = new RuleRegistry;
        $registry->register(new SemanticOpeningFixProbeRule);
        $result = (new Linter($registry))->lint(
            '<x-button data-probe="old"></x-button>',
            'resources/views/page.blade.php',
            Config::make([
                'componentMappings' => ['x-button' => 'button'],
                'rules' => ['semantic-opening-fix-probe' => 'warning'],
            ]),
        );

        expect($result->violations)->toHaveCount(2)
            ->and($result->violations[0]->fix)->toBeNull()
            ->and($result->violations[1]->fix)->toBeNull();
    });

    it('keeps a native sibling separate after deleting a paired void-component closer', function (): void {
        $source = '<x-image loading="lazy"></x-image><img src="missing.jpg">';
        $semantic = semanticDocumentWithMapping($source, ['x-image' => 'img']);
        $images = $semantic->queryElements('img')->values()->all();
        $semanticSiblingOffset = strpos($semantic->source(), '<img', 1);

        expect($semantic->source())->toBe('<img loading="lazy"><img src="missing.jpg">')
            ->and($images)->toHaveCount(2)
            ->and($images[0]->isPaired())->toBeFalse()
            ->and($images[0]->startOffset())->toBe(0)
            ->and($images[1]->getParent())->toBeNull()
            ->and($images[1]->startOffset())->toBe($semanticSiblingOffset);

        $result = lintWithComponentMapping(
            $source,
            ['perf-lazy-load-images' => ['error', ['skipAboveFold' => false]]],
            ['x-image' => 'img'],
        );
        $expectedOffset = strpos($source, '<img');

        expect($result->violations)->toHaveCount(1)
            ->and($result->violations[0]->start->offset)->toBe($expectedOffset)
            ->and($result->violations[0]->getColumn())->toBe($expectedOffset + 1)
            ->and($result->violations[0]->fix?->startOffset)->toBeGreaterThanOrEqual($expectedOffset)
            ->and($result->violations[0]->fix?->replacement)->not->toContain('x-image');

        $fixed = (new Fixer)->applyFixes(
            $source,
            array_filter([$result->violations[0]->fix]),
            includeDangerous: true,
        )->content;
        expect($fixed)->toBe('<x-image loading="lazy"></x-image><img src="missing.jpg" loading="lazy">');
    });

    it('preserves nested mapped tree semantics and a later native fix', function (): void {
        $source = '<x-panel><x-button type="button">Go</x-button></x-panel><button></button>';
        $mappings = ['x-panel' => 'section', 'x-button' => 'button'];
        $semantic = semanticDocumentWithMapping($source, $mappings);
        $section = $semantic->queryElements('section')->first();
        $buttons = $semantic->queryElements('button')->values()->all();
        $semanticSiblingOffset = strrpos($semantic->source(), '<button>');

        expect($semantic->source())->toBe('<section><button type="button">Go</button></section><button></button>')
            ->and($section)->toBeInstanceOf(ElementNode::class)
            ->and($buttons)->toHaveCount(2)
            ->and($buttons[0]->getParent())->toBe($section)
            ->and($buttons[1]->getParent())->toBeNull()
            ->and($buttons[1]->startOffset())->toBe($semanticSiblingOffset);

        $result = lintWithComponentMapping($source, ['best-practices-button-type' => 'error'], $mappings);
        $originalSiblingOffset = strrpos($source, '<button>');

        expect($result->violations)->toHaveCount(1)
            ->and($result->violations[0]->start->offset)->toBe($originalSiblingOffset)
            ->and($result->violations[0]->fix?->startOffset)->toBeGreaterThanOrEqual($originalSiblingOffset)
            ->and($result->violations[0]->fix?->replacement)->not->toContain('x-button');

        $fixed = (new Fixer)->applyFixes(
            $source,
            array_filter([$result->violations[0]->fix]),
            includeDangerous: true,
        )->content;
        expect($fixed)->toBe(
            '<x-panel><x-button type="button">Go</x-button></x-panel><button type="button"></button>'
        );
    });

    it('does not infer semantics without an explicit mapping', function (): void {
        $config = Config::make(['rules' => ['best-practices-button-type' => 'error']]);
        $result = (new Linter(RuleRegistry::withBuiltInRules()))
            ->lint('<x-button />', 'resources/views/page.blade.php', $config);

        expect($result->violations)->toBeEmpty();
    });

    it('validates, normalizes, merges, and serializes mappings', function (): void {
        $config = Config::make(['componentMappings' => ['X-Button' => 'BUTTON']]);
        expect($config->getComponentMappings())->toBe(['x-button' => 'button'])
            ->and($config->has('componentMappings'))->toBeTrue()
            ->and($config->toArray()['componentMappings'])->toBe(['x-button' => 'button'])
            ->and($config->toResolvedArray()['componentMappings'])->toBe(['x-button' => 'button']);

        $base = Config::make(['componentMappings' => ['x-link' => 'a']]);
        $base->merge($config);
        expect($base->getComponentMappings())->toBe(['x-button' => 'button']);
    });

    it('rejects ambiguous or non-native mappings', function (array $mapping): void {
        expect(fn (): Config => Config::make(['componentMappings' => $mapping]))
            ->toThrow(ConfigurationException::class);
    })->with([
        'list' => [[['x-button', 'button']]],
        'dynamic component' => [['x-dynamic-component' => 'button']],
        'ordinary source tag' => [['button' => 'button']],
        'unknown semantic target' => [['x-button' => 'buton']],
        'non-string target' => [['x-button' => true]],
        'case-insensitive duplicate' => [['x-button' => 'button', 'X-Button' => 'a']],
    ]);
});
