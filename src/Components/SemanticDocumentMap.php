<?php

declare(strict_types=1);

namespace Forte\Sheath\Components;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Position;

/** @internal */
final class SemanticDocumentMap
{
    /** @var array<int, true> */
    private array $mappedElementSemanticStarts = [];

    /** @var list<array{start: int, end: int}> */
    private array $mappedOpeningSemanticRanges = [];

    /** @var list<int> */
    private readonly array $mappedOpeningSemanticStarts;

    /** @var list<int> */
    private array $mappedOpeningSemanticEnds;

    /** @var list<int> */
    private array $originalEditStarts = [];

    /** @var list<int> */
    private array $semanticEditStarts = [];

    /** @var list<int> */
    private array $editDeltasAfter = [];

    /**
     * @param  list<array{originalStart: int, originalEnd: int, semanticStart: int, semanticEnd: int}>  $edits
     * @param  list<array{start: int, end: int}>  $mappedOpeningOriginalRanges
     */
    public function __construct(
        private readonly Document $original,
        private readonly Document $semantic,
        private readonly array $edits,
        array $mappedOpeningOriginalRanges,
    ) {
        $delta = 0;
        foreach ($this->edits as $edit) {
            $this->originalEditStarts[] = $edit['originalStart'];
            $this->semanticEditStarts[] = $edit['semanticStart'];
            $delta += ($edit['semanticEnd'] - $edit['semanticStart'])
                - ($edit['originalEnd'] - $edit['originalStart']);
            $this->editDeltasAfter[] = $delta;
        }

        foreach ($mappedOpeningOriginalRanges as $range) {
            $semanticStart = $this->semanticOffset($range['start']);
            $semanticEnd = $this->semanticOffset($range['end']);
            $this->mappedElementSemanticStarts[$semanticStart] = true;
            $this->mappedOpeningSemanticRanges[] = [
                'start' => $semanticStart,
                'end' => $semanticEnd,
            ];
        }

        usort(
            $this->mappedOpeningSemanticRanges,
            static fn (array $left, array $right): int => $left['start'] <=> $right['start'],
        );
        $this->mappedOpeningSemanticStarts = array_column($this->mappedOpeningSemanticRanges, 'start');
        $this->mappedOpeningSemanticEnds = array_column($this->mappedOpeningSemanticRanges, 'end');
    }

    public function semanticDocument(): Document
    {
        return $this->semantic;
    }

    public function originalPosition(int $semanticOffset): Position
    {
        return Position::fromOffset($this->original, $this->originalOffset($semanticOffset));
    }

    public function originalFix(Fix $fix): Fix
    {
        return new Fix(
            $this->originalOffset($fix->startOffset),
            $this->originalOffset($fix->endOffset),
            $fix->replacement,
            $fix->dangerous,
            $fix->priority,
        );
    }

    public function isMappedComponent(ElementNode $element): bool
    {
        return isset($this->mappedElementSemanticStarts[$element->startOffset()]);
    }

    public function fixTouchesMappedComponentOpening(Fix $fix): bool
    {
        if ($this->mappedOpeningSemanticRanges === []) {
            return false;
        }

        if ($fix->startOffset === $fix->endOffset) {
            $index = $this->upperBound($this->mappedOpeningSemanticStarts, $fix->startOffset) - 1;

            return $index >= 0
                && $fix->startOffset < $this->mappedOpeningSemanticEnds[$index];
        }

        $index = $this->upperBound($this->mappedOpeningSemanticEnds, $fix->startOffset);

        return isset($this->mappedOpeningSemanticRanges[$index])
            && $this->mappedOpeningSemanticRanges[$index]['start'] < $fix->endOffset;
    }

    private function originalOffset(int $semanticOffset): int
    {
        $insertion = $this->lowerBound($this->semanticEditStarts, $semanticOffset);
        $previous = $insertion - 1;

        if ($previous >= 0 && $semanticOffset <= $this->edits[$previous]['semanticEnd']) {
            return $this->originalOffsetWithinEdit($semanticOffset, $this->edits[$previous]);
        }

        if (isset($this->semanticEditStarts[$insertion])
            && $this->semanticEditStarts[$insertion] === $semanticOffset) {
            return $this->originalOffsetWithinEdit($semanticOffset, $this->edits[$insertion]);
        }

        return $semanticOffset - ($previous >= 0 ? $this->editDeltasAfter[$previous] : 0);
    }

    private function semanticOffset(int $originalOffset): int
    {
        $insertion = $this->lowerBound($this->originalEditStarts, $originalOffset);
        $previous = $insertion - 1;

        if ($previous >= 0 && $originalOffset <= $this->edits[$previous]['originalEnd']) {
            return $this->semanticOffsetWithinEdit($originalOffset, $this->edits[$previous]);
        }

        if (isset($this->originalEditStarts[$insertion])
            && $this->originalEditStarts[$insertion] === $originalOffset) {
            return $this->semanticOffsetWithinEdit($originalOffset, $this->edits[$insertion]);
        }

        return $originalOffset + ($previous >= 0 ? $this->editDeltasAfter[$previous] : 0);
    }

    /** @param array{originalStart: int, originalEnd: int, semanticStart: int, semanticEnd: int} $edit */
    private function originalOffsetWithinEdit(int $semanticOffset, array $edit): int
    {
        if ($semanticOffset === $edit['semanticEnd']) {
            return $edit['originalEnd'];
        }

        $relative = $semanticOffset - $edit['semanticStart'];

        return $edit['originalStart'] + min($relative, $edit['originalEnd'] - $edit['originalStart']);
    }

    /** @param array{originalStart: int, originalEnd: int, semanticStart: int, semanticEnd: int} $edit */
    private function semanticOffsetWithinEdit(int $originalOffset, array $edit): int
    {
        if ($originalOffset === $edit['originalEnd']) {
            return $edit['semanticEnd'];
        }

        $relative = $originalOffset - $edit['originalStart'];

        return $edit['semanticStart'] + min($relative, $edit['semanticEnd'] - $edit['semanticStart']);
    }

    /** @param list<int> $values */
    private function lowerBound(array $values, int $needle): int
    {
        $low = 0;
        $high = count($values);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($values[$middle] < $needle) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    /** @param list<int> $values */
    private function upperBound(array $values, int $needle): int
    {
        $low = 0;
        $high = count($values);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($values[$middle] <= $needle) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }
}
