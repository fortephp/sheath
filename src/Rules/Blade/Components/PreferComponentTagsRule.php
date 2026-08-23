<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Components;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Support\AttributeQuoting;

/** @internal */
class PreferComponentTagsRule extends AbstractRule
{
    private const STABLE_NAME = '/^[a-z][a-zA-Z0-9]*$/';

    private const TAG_NAME = '/^(?:'.self::SEGMENT.'::)?'.self::SEGMENT.'(?:\.'.self::SEGMENT.')*$/';

    private const SEGMENT = '[a-zA-Z0-9_]+(?:-[a-zA-Z0-9_]+)*';

    public function getId(): string
    {
        return 'blade-prefer-component-tags';
    }

    public function getDescription(): string
    {
        return 'Prefer <x-component> tag syntax over the @component directive.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::INFO;
    }

    public function check(Document $document, RuleContext $context): void
    {
        [$components, $owners, $slots] = $this->componentStructure($document);

        /** @var array<int, array{tag: string, attributes: string, slots: list<array{start: DirectiveNode, end: DirectiveNode, name: string}>}|null> $conversions */
        $conversions = [];

        foreach ($components as $offset => $block) {
            $conversions[$offset] = $this->conversion($block, $slots[$offset] ?? []);
        }

        /** @var array<int, int|null> $containingRewrite */
        $containingRewrite = [];
        /** @var array<int, list<int>> $rewriteMembers */
        $rewriteMembers = [];

        foreach ($components as $offset => $block) {
            $owner = $owners[$offset];
            $ancestorRewrite = $owner === null ? null : ($containingRewrite[$owner] ?? null);
            $conversion = $conversions[$offset];
            $rewriteRoot = $conversion === null ? $ancestorRewrite : ($ancestorRewrite ?? $offset);

            $containingRewrite[$offset] = $rewriteRoot;

            if ($conversion !== null && $rewriteRoot !== null) {
                $rewriteMembers[$rewriteRoot][] = $offset;
            }
        }

        foreach ($components as $offset => $block) {
            $conversion = $conversions[$offset];
            $fix = $conversion !== null && ($containingRewrite[$offset] ?? null) === $offset
                ? $this->createRewriteFix($block, $rewriteMembers[$offset], $components, $conversions)
                : null;

            $context->report(
                $block->startDirective() ?? $block,
                'Component uses @component directive syntax.',
                $fix
            );
        }
    }

    /**
     * @return array{
     *     0: array<int, DirectiveBlockNode>,
     *     1: array<int, int|null>,
     *     2: array<int, list<DirectiveBlockNode>>
     * }
     */
    private function componentStructure(Document $document): array
    {
        /** @var list<DirectiveBlockNode> $relevantBlocks */
        $relevantBlocks = [];

        $document->queryBlockDirectives()->each(function (DirectiveBlockNode $block) use (&$relevantBlocks): void {
            if (in_array(strtolower($block->nameText()), ['component', 'slot'], true)) {
                $relevantBlocks[] = $block;
            }
        });

        usort($relevantBlocks, static fn (DirectiveBlockNode $a, DirectiveBlockNode $b): int => $a->startOffset() <=> $b->startOffset());

        /** @var array<int, DirectiveBlockNode> $components */
        $components = [];
        /** @var array<int, int|null> $owners */
        $owners = [];
        /** @var array<int, list<DirectiveBlockNode>> $slots */
        $slots = [];
        /** @var list<DirectiveBlockNode> $componentStack */
        $componentStack = [];

        foreach ($relevantBlocks as $block) {
            while ($componentStack !== []) {
                $ancestor = $componentStack[array_key_last($componentStack)];

                if ($ancestor->startOffset() < $block->startOffset()
                    && $block->endOffset() <= $ancestor->endOffset()) {
                    break;
                }

                array_pop($componentStack);
            }

            $owner = $componentStack === []
                ? null
                : $componentStack[array_key_last($componentStack)]->startOffset();

            if (strtolower($block->nameText()) === 'slot') {
                if ($owner !== null) {
                    $slots[$owner][] = $block;
                }

                continue;
            }

            $offset = $block->startOffset();
            $components[$offset] = $block;
            $owners[$offset] = $owner;

            if ($offset >= 0 && $block->endOffset() > $offset) {
                $componentStack[] = $block;
            }
        }

        return [$components, $owners, $slots];
    }

    /**
     * @param  list<DirectiveBlockNode>  $slots
     * @return array{tag: string, attributes: string, slots: list<array{start: DirectiveNode, end: DirectiveNode, name: string}>}|null
     */
    private function conversion(DirectiveBlockNode $block, array $slots): ?array
    {
        $start = $block->startDirective();
        $end = $block->endDirective();

        if ($start === null || $end === null || strtolower($end->nameText()) !== 'endcomponent') {
            return null;
        }

        $inner = PhpSource::innerArguments($start->arguments());

        if ($inner === null) {
            return null;
        }

        $parts = PhpSource::splitTopLevel($inner);

        if ($parts === null || $parts === [] || count($parts) > 2) {
            return null;
        }

        $tag = $this->tagName($parts[0]);

        if ($tag === null) {
            return null;
        }

        $attributes = isset($parts[1]) ? $this->attributes($parts[1]) : '';

        if ($attributes === null) {
            return null;
        }

        $convertedSlots = [];

        foreach ($slots as $slot) {
            $convertedSlot = $this->slotConversion($slot);

            if ($convertedSlot === null) {
                return null;
            }

            $convertedSlots[] = $convertedSlot;
        }

        return [
            'tag' => $tag,
            'attributes' => $attributes,
            'slots' => $convertedSlots,
        ];
    }

    private function tagName(string $expression): ?string
    {
        $view = PhpSource::literalString($expression);

        if ($view === null) {
            return null;
        }

        if (str_contains($view, '::')) {
            return str_starts_with($view, 'mail::') && preg_match(self::TAG_NAME, $view) === 1
                ? $view
                : null;
        }

        if (! str_starts_with($view, 'components.')) {
            return null;
        }

        $name = substr($view, strlen('components.'));

        return $name !== '' && preg_match(self::TAG_NAME, $name) === 1 ? $name : null;
    }

    private function attributes(string $expression): ?string
    {
        $entries = $this->arrayEntries($expression);

        if ($entries === null) {
            return null;
        }

        $rendered = '';
        $seen = [];

        foreach ($entries as [$key, $value]) {
            if (isset($seen[$key])) {
                return null;
            }

            $seen[$key] = true;

            $attribute = $this->attribute($key, $value);

            if ($attribute === null) {
                return null;
            }

            $rendered .= ' '.$attribute;
        }

        return $rendered;
    }

    /**
     * @return array<int, array{string, string}>|null
     */
    private function arrayEntries(string $expression): ?array
    {
        $expression = trim($expression);

        if (str_starts_with($expression, '[') && str_ends_with($expression, ']')) {
            $body = substr($expression, 1, -1);
        } elseif (preg_match('/^array\s*\((.*)\)$/is', $expression, $matches) === 1) {
            $body = $matches[1];
        } else {
            return null;
        }

        $parts = PhpSource::splitTopLevel($body);

        if ($parts === null) {
            return null;
        }

        $entries = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $pair = $this->splitPair($part);

            if ($pair === null) {
                return null;
            }

            [$keyExpression, $value] = $pair;

            $key = PhpSource::literalString($keyExpression);

            if ($key === null || preg_match(self::STABLE_NAME, $key) !== 1) {
                return null;
            }

            $entries[] = [$key, $value];
        }

        return $entries;
    }

    /**
     * @return array{string, string}|null
     */
    private function splitPair(string $entry): ?array
    {
        $tokens = PhpSource::tokenize($entry);

        if ($tokens === null) {
            return null;
        }

        $depth = 0;

        foreach ($tokens as $index => $token) {
            if ($depth === 0 && is_array($token) && $token[0] === T_DOUBLE_ARROW) {
                $key = '';
                $value = '';

                foreach ($tokens as $position => $piece) {
                    $text = is_array($piece) ? $piece[1] : $piece;

                    if ($position < $index) {
                        $key .= $text;
                    } elseif ($position > $index) {
                        $value .= $text;
                    }
                }

                $key = trim($key);
                $value = trim($value);

                return $key === '' || $value === '' ? null : [$key, $value];
            }

            $depth += PhpSource::nestingDelta($token);

            if ($depth < 0) {
                return null;
            }
        }

        return null;
    }

    private function attribute(string $key, string $value): ?string
    {
        $literal = PhpSource::literalString($value);

        if ($literal !== null && preg_match('/[<>"\'{}@\\\\]|\R/', $literal) !== 1) {
            return $key.'="'.$literal.'"';
        }

        $quote = AttributeQuoting::preferredQuote($value);

        if ($quote === null) {
            return null;
        }

        return AttributeQuoting::render(':'.$key, $value, $quote);
    }

    /**
     * @param  list<int>  $members
     * @param  array<int, DirectiveBlockNode>  $components
     * @param  array<int, array{tag: string, attributes: string, slots: list<array{start: DirectiveNode, end: DirectiveNode, name: string}>}|null>  $conversions
     */
    private function createRewriteFix(DirectiveBlockNode $root, array $members, array $components, array $conversions): ?Fix
    {
        /** @var array<int, array{int, int, string}> $edits */
        $edits = [];

        foreach ($members as $offset) {
            $block = $components[$offset];
            $conversion = $conversions[$offset];
            $start = $block->startDirective();
            $end = $block->endDirective();

            if ($conversion === null || $start === null || $end === null) {
                return null;
            }

            $tag = $conversion['tag'];
            $edits[] = [$start->startOffset(), $start->endOffset(), '<x-'.$tag.$conversion['attributes'].'>'];
            $edits[] = [$end->startOffset(), $end->endOffset(), '</x-'.$tag.'>'];

            foreach ($conversion['slots'] as $slot) {
                $slotStart = $slot['start'];
                $slotEnd = $slot['end'];
                $slotName = $slot['name'];
                $edits[] = [$slotStart->startOffset(), $slotStart->endOffset(), '<x-slot:'.$slotName.'>'];
                $edits[] = [$slotEnd->startOffset(), $slotEnd->endOffset(), '</x-slot>'];
            }
        }

        usort($edits, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $source = $root->getDocument()->source();
        $rewriteStart = $root->startOffset();
        $rewriteEnd = $root->endOffset();
        $rewritten = '';
        $cursor = $rewriteStart;

        foreach ($edits as [$editStart, $editEnd, $editReplacement]) {
            if ($editStart < $cursor || $editEnd > $rewriteEnd) {
                return null;
            }

            $rewritten .= substr($source, $cursor, $editStart - $cursor).$editReplacement;
            $cursor = $editEnd;
        }

        $rewritten .= substr($source, $cursor, $rewriteEnd - $cursor);

        return Fix::dangerous($rewriteStart, $rewriteEnd, $rewritten);
    }

    /** @return array{start: DirectiveNode, end: DirectiveNode, name: string}|null */
    private function slotConversion(DirectiveBlockNode $slot): ?array
    {
        $start = $slot->startDirective();
        $end = $slot->endDirective();

        if ($start === null || $end === null || strtolower($end->nameText()) !== 'endslot') {
            return null;
        }

        $inner = PhpSource::innerArguments($start->arguments());

        if ($inner === null) {
            return null;
        }

        $parts = PhpSource::splitTopLevel($inner);

        if ($parts === null || count($parts) !== 1) {
            return null;
        }

        $name = PhpSource::literalString($parts[0]);

        if ($name === null || preg_match(self::STABLE_NAME, $name) !== 1) {
            return null;
        }

        return ['start' => $start, 'end' => $end, 'name' => $name];
    }
}
