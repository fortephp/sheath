<?php

declare(strict_types=1);

namespace Forte\Sheath\Components;

use Forte\Ast\Document\Document;
use Forte\Parser\ParserOptions;

/** @internal */
final class ComponentSemanticRewriter
{
    /** @var array<string, true> */
    private const HTML_ELEMENTS = [
        'a' => true, 'abbr' => true, 'address' => true, 'area' => true, 'article' => true,
        'aside' => true, 'audio' => true, 'b' => true, 'base' => true, 'bdi' => true,
        'bdo' => true, 'blockquote' => true, 'body' => true, 'br' => true, 'button' => true,
        'canvas' => true, 'caption' => true, 'cite' => true, 'code' => true, 'col' => true,
        'colgroup' => true, 'data' => true, 'datalist' => true, 'dd' => true, 'del' => true,
        'details' => true, 'dfn' => true, 'dialog' => true, 'div' => true, 'dl' => true,
        'dt' => true, 'em' => true, 'embed' => true, 'fieldset' => true, 'figcaption' => true,
        'figure' => true, 'footer' => true, 'form' => true, 'h1' => true, 'h2' => true,
        'h3' => true, 'h4' => true, 'h5' => true, 'h6' => true, 'head' => true,
        'header' => true, 'hgroup' => true, 'hr' => true, 'html' => true, 'i' => true,
        'iframe' => true, 'img' => true, 'input' => true, 'ins' => true, 'kbd' => true,
        'label' => true, 'legend' => true, 'li' => true, 'link' => true, 'main' => true,
        'map' => true, 'mark' => true, 'menu' => true, 'meta' => true, 'meter' => true,
        'nav' => true, 'noscript' => true, 'object' => true, 'ol' => true, 'optgroup' => true,
        'option' => true, 'output' => true, 'p' => true, 'picture' => true, 'pre' => true,
        'progress' => true, 'q' => true, 'rp' => true, 'rt' => true, 'ruby' => true,
        's' => true, 'samp' => true, 'script' => true, 'search' => true, 'section' => true,
        'select' => true, 'slot' => true, 'small' => true, 'source' => true, 'span' => true,
        'strong' => true, 'style' => true, 'sub' => true, 'summary' => true, 'sup' => true,
        'table' => true, 'tbody' => true, 'td' => true, 'template' => true, 'textarea' => true,
        'tfoot' => true, 'th' => true, 'thead' => true, 'time' => true, 'title' => true,
        'tr' => true, 'track' => true, 'u' => true, 'ul' => true, 'var' => true,
        'video' => true, 'wbr' => true,
    ];

    /** @var array<string, true> */
    private const VOID_ELEMENTS = [
        'area' => true, 'base' => true, 'br' => true, 'col' => true, 'embed' => true,
        'hr' => true, 'img' => true, 'input' => true, 'link' => true, 'meta' => true,
        'source' => true, 'track' => true, 'wbr' => true,
    ];

    /** @param array<string, string> $mappings */
    public static function rewrite(
        Document $document,
        array $mappings,
        ParserOptions $parserOptions,
    ): SemanticDocumentMap {
        /** @var list<array{originalStart: int, originalEnd: int, replacement: string}> $edits */
        $edits = [];
        /** @var list<array{start: int, end: int}> $mappedOpeningOriginalRanges */
        $mappedOpeningOriginalRanges = [];

        foreach ($document->queryComponents() as $component) {
            $semanticTag = $mappings[strtolower($component->tagNameText())] ?? null;
            if ($semanticTag === null) {
                continue;
            }

            $name = $component->tagName();
            $edits[] = [
                'originalStart' => $name->startOffset(),
                'originalEnd' => $name->endOffset(),
                'replacement' => $semanticTag,
            ];
            $openingEnd = $document->findOpeningTagEndPosition($component->index());
            $mappedOpeningOriginalRanges[] = [
                'start' => $component->startOffset(),
                'end' => max($component->startOffset(), $openingEnd),
            ];

            $closing = $component->closingTag();
            if ($closing !== null) {
                $edits[] = [
                    'originalStart' => $closing->startOffset(),
                    'originalEnd' => $closing->endOffset(),
                    'replacement' => isset(self::VOID_ELEMENTS[$semanticTag]) ? '' : "</{$semanticTag}>",
                ];
            }
        }

        if ($edits === []) {
            return new SemanticDocumentMap($document, $document, [], []);
        }

        usort($edits, static fn (array $left, array $right): int => $left['originalStart'] <=> $right['originalStart']);

        $source = $document->source();
        $semanticSource = '';
        $cursor = 0;
        $offsetEdits = [];

        foreach ($edits as $edit) {
            $semanticSource .= substr($source, $cursor, $edit['originalStart'] - $cursor);
            $semanticStart = strlen($semanticSource);
            $semanticSource .= $edit['replacement'];
            $semanticEnd = strlen($semanticSource);
            $offsetEdits[] = [
                'originalStart' => $edit['originalStart'],
                'originalEnd' => $edit['originalEnd'],
                'semanticStart' => $semanticStart,
                'semanticEnd' => $semanticEnd,
            ];
            $cursor = $edit['originalEnd'];
        }

        $semanticSource .= substr($source, $cursor);
        $semantic = Document::parse($semanticSource, $parserOptions)->setFilePath($document->getFilePath());

        return new SemanticDocumentMap($document, $semantic, $offsetEdits, $mappedOpeningOriginalRanges);
    }

    public static function isValidComponentTag(string $tag): bool
    {
        $tag = strtolower(trim($tag));

        return $tag !== 'x-dynamic-component'
            && preg_match('/^x-[a-z0-9][a-z0-9_.:-]*$/D', $tag) === 1;
    }

    public static function isValidSemanticTag(string $tag): bool
    {
        return isset(self::HTML_ELEMENTS[strtolower(trim($tag))]);
    }
}
