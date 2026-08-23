<?php

declare(strict_types=1);

namespace Forte\Sheath\Configuration;

use Forte\Ast\BladeCommentNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Elements\CommentNode;
use Forte\Ast\Node;
use Forte\Sheath\Results\Violation;

/**
 * @internal
 *
 * @phpstan-type BlockChange array{line: int, offset: int, order: int, suppressed: bool}
 */
final class Suppressions
{
    private const FILE = 'file';

    private const LINE = 'line';

    private const NEXT_LINE = 'next-line';

    private const BLOCK = 'block';

    /**
     * @var array<string, true>
     */
    private array $file = [];

    /**
     * @var array<int, array<string, true>>
     */
    private array $lines = [];

    /** @var array<string, list<BlockChange>> */
    private array $blockChanges = [];

    /**
     * @var array<array{line: int, scope: string, rules: array<string>}>
     */
    private array $directives = [];

    public static function fromDocument(Document $document): self
    {
        $instance = new self;
        $events = [];

        $document->getComments()->each(function (Node $node) use ($instance, &$events): void {
            $text = $instance->commentText($node);

            if ($text === null) {
                return;
            }

            foreach ($instance->parse($text) as [$action, $scope, $rules]) {
                $line = $node->startLine();
                $instance->directives[] = ['line' => $line, 'scope' => $scope, 'rules' => array_keys($rules)];

                if ($action === 'enable') {
                    $events[] = [$line, $node->startOffset(), 'enable', $rules];

                    continue;
                }

                match ($scope) {
                    self::FILE => $instance->file += $rules,
                    self::LINE => $instance->lines[$line] = ($instance->lines[$line] ?? []) + $rules,
                    self::NEXT_LINE => $instance->lines[$line + 1] = ($instance->lines[$line + 1] ?? []) + $rules,
                    self::BLOCK => $events[] = [$line, $node->startOffset(), 'disable', $rules],
                    default => null,
                };
            }
        });

        usort($events, fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $instance->indexBlockChanges($events);

        return $instance;
    }

    public function suppresses(string $ruleId, int $line, ?int $offset = null): bool
    {
        if ($this->matches($this->file, $ruleId)) {
            return true;
        }

        if ($this->matches($this->lines[$line] ?? [], $ruleId)) {
            return true;
        }

        $wildcard = $this->latestBlockChange('*', $line, $offset);
        $specific = $this->latestBlockChange($ruleId, $line, $offset);
        $latest = $wildcard;

        if ($specific !== null && ($latest === null || $specific['order'] > $latest['order'])) {
            $latest = $specific;
        }

        return $latest['suppressed'] ?? false;
    }

    /**
     * @param  array<Violation>  $violations
     * @return array<Violation>
     */
    public function filter(array $violations): array
    {
        return array_values(array_filter(
            $violations,
            fn (Violation $violation): bool => ! $this->suppresses(
                $violation->ruleId,
                $violation->getLine(),
                $violation->start->offset,
            )
        ));
    }

    public function isEmpty(): bool
    {
        return $this->directives === [];
    }

    /**
     * @return array<array{line: int, scope: string, rules: array<string>}>
     */
    public function directives(): array
    {
        return $this->directives;
    }

    /**
     * @param  array<string, true>  $rules
     */
    private function matches(array $rules, string $ruleId): bool
    {
        return isset($rules['*']) || isset($rules[$ruleId]);
    }

    /**
     * @param  list<array{int, int, string, array<string, true>}>  $events
     */
    private function indexBlockChanges(array $events): void
    {
        foreach ($events as $order => [$line, $offset, $action, $rules]) {
            foreach (array_keys($rules) as $ruleId) {
                $this->blockChanges[$ruleId][] = [
                    'line' => $line,
                    'offset' => $offset,
                    'order' => $order,
                    'suppressed' => $action === 'disable',
                ];
            }
        }
    }

    /** @return BlockChange|null */
    private function latestBlockChange(string $ruleId, int $line, ?int $offset): ?array
    {
        $changes = $this->blockChanges[$ruleId] ?? [];
        $lowest = 0;
        $highest = count($changes) - 1;
        $latest = null;

        while ($lowest <= $highest) {
            $middle = intdiv($lowest + $highest, 2);
            $candidate = $changes[$middle];

            if ($candidate['line'] < $line
                || ($candidate['line'] === $line && ($offset === null || $candidate['offset'] <= $offset))) {
                $latest = $candidate;
                $lowest = $middle + 1;
            } else {
                $highest = $middle - 1;
            }
        }

        return $latest;
    }

    private function commentText(Node $node): ?string
    {
        return match (true) {
            $node instanceof BladeCommentNode => $node->text(),
            $node instanceof CommentNode => $node->content(),
            default => null,
        };
    }

    /**
     * @return array<array{string, string, array<string, true>}> action, scope, rules
     */
    private function parse(string $text): array
    {
        $pattern = '/(?<![a-z0-9_-])sheath-(disable|enable)(-next-line|-line|-file)?\b[ \t]*([^\r\n]*)/i';

        if (! preg_match_all($pattern, $text, $matches, PREG_SET_ORDER)) {
            return [];
        }

        $directives = [];

        foreach ($matches as $match) {
            $action = strtolower($match[1]);
            $scope = match (strtolower($match[2])) {
                '-file' => self::FILE,
                '-line' => self::LINE,
                '-next-line' => self::NEXT_LINE,
                default => self::BLOCK,
            };

            $directives[] = [$action, $scope, $this->parseRules($match[3], widenWhenGarbled: $action === 'enable')];
        }

        return $directives;
    }

    /**
     * @param  bool  $widenWhenGarbled  Whether a non-empty list that parses to
     *                                  no rules should mean every rule; true
     *                                  only for `enable`, where failing narrow
     *                                  would leave a blanket disable open
     * @return array<string, true>
     */
    private function parseRules(string $list, bool $widenWhenGarbled = false): array
    {
        $list = (string) preg_replace('/--}}|-->|\*\/$/', '', $list);
        $parts = preg_split('/(?:^|\s+)-{1,2}\s+/', trim($list));
        $list = trim($parts === false ? '' : $parts[0]);

        if ($list === '') {
            return ['*' => true];
        }

        $rules = [];

        foreach (preg_split('/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $rule) {
            if (preg_match('/^[a-z0-9][a-z0-9-]*$/i', $rule)) {
                $rules[$rule] = true;
            }
        }

        if ($rules === [] && $widenWhenGarbled) {
            return ['*' => true];
        }

        return $rules;
    }
}
