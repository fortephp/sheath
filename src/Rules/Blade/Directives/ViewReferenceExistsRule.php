<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Directives;

use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Sheath\Contracts\ProvidesCacheContext;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Illuminate\Container\Container;
use Illuminate\View\FileViewFinder;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/** @internal */
class ViewReferenceExistsRule extends AbstractRule implements ProvidesCacheContext
{
    /** @var array<string, int> */
    private const VIEW_ARGUMENTS = [
        'include' => 0,
        'includewhen' => 1,
        'includeunless' => 1,
        'includeisolated' => 0,
        'extends' => 0,
        'component' => 0,
    ];

    public function getId(): string
    {
        return 'blade-view-reference-exists';
    }

    public function getDescription(): string
    {
        return 'Literal Blade view references must exist.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void
    {
        if (preg_match('/@(?:include|extends|component|each)/i', $document->source()) !== 1) {
            return;
        }

        $finder = $this->finder();
        if ($finder === null) {
            return;
        }

        $document->allOfType(DirectiveNode::class, true)->each(function (DirectiveNode $directive) use ($context, $document, $finder): void {
            $this->checkDirective($directive, $document, $context, $finder);
        });
    }

    /** @param array<string, mixed> $options */
    public function cacheContext(array $options): array|string
    {
        $finder = $this->finder();
        if ($finder === null) {
            return 'view-finder-unavailable';
        }

        $configuration = $this->finderConfiguration($finder);
        if ($configuration === null) {
            return 'view-finder-configuration-unavailable';
        }

        return [
            'schema' => 1,
            'paths' => $configuration['paths'],
            'hints' => $configuration['hints'],
            'extensions' => $configuration['extensions'],
            'views' => $this->viewIdentityFingerprint(
                array_values(array_unique(array_merge(
                    $configuration['paths'],
                    ...array_values($configuration['hints']),
                ))),
                $configuration['extensions'],
            ),
        ];
    }

    private function checkDirective(
        DirectiveNode $directive,
        Document $document,
        RuleContext $context,
        FileViewFinder $finder,
    ): void {
        $name = strtolower($directive->nameText());

        if ($name === 'includeif' || ! $directive->hasArguments()) {
            return;
        }

        $arguments = $this->argumentSpans($directive);
        if ($arguments === null) {
            return;
        }

        if (isset(self::VIEW_ARGUMENTS[$name])) {
            if ($this->hasStaticallyUnreachableConditionalView($name, $arguments)) {
                return;
            }

            $argument = $arguments[self::VIEW_ARGUMENTS[$name]] ?? null;
            if ($argument !== null) {
                $this->checkLiteral($argument, $name, $document, $context, $finder);
            }

            return;
        }

        if ($name === 'each') {
            if (isset($arguments[0])) {
                $this->checkLiteral($arguments[0], $name, $document, $context, $finder);
            }

            if (isset($arguments[3])) {
                $empty = $this->literal($arguments[3]);
                if ($empty !== null && ! str_starts_with($empty['value'], 'raw|')) {
                    $this->reportIfMissing($empty, $name, $document, $context, $finder);
                }
            }

            return;
        }

        if (($name === 'includefirst' || $name === 'extendsfirst') && isset($arguments[0])) {
            $this->checkFirst($arguments[0], $name, $document, $context, $finder);
        }
    }

    /** @param list<array{source: string, offset: int}> $arguments */
    private function hasStaticallyUnreachableConditionalView(string $directive, array $arguments): bool
    {
        if (($directive !== 'includewhen' && $directive !== 'includeunless') || ! isset($arguments[0])) {
            return false;
        }

        $condition = $this->simpleLiteralTruthiness($arguments[0]);
        if ($condition === null) {
            return false;
        }

        return ($directive === 'includewhen' && ! $condition['truthy'])
            || ($directive === 'includeunless' && $condition['truthy']);
    }

    /**
     * Resolve only PHP literals whose truthiness is intrinsic. Constants,
     * calls, operators, and arrays containing non-literal expressions remain
     * unknown rather than being evaluated by the linter.
     *
     * @param  array{source: string, offset: int}  $span
     * @return array{truthy: bool}|null
     */
    private function simpleLiteralTruthiness(array $span): ?array
    {
        $source = trim($span['source']);
        $tokens = PhpSource::tokenize($source);
        if ($tokens === null) {
            return null;
        }

        $tokens = array_values(array_filter(
            $tokens,
            static fn (array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        if (count($tokens) === 1 && is_array($tokens[0])) {
            [$kind, $text] = $tokens[0];

            if ($kind === T_CONSTANT_ENCAPSED_STRING) {
                $value = PhpSource::literalString($text);

                return $value === null ? null : ['truthy' => $value !== '' && $value !== '0'];
            }

            if ($kind === T_LNUMBER || $kind === T_DNUMBER) {
                return preg_match('/\A(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?\z/i', $text) === 1
                    ? ['truthy' => (float) $text != 0.0]
                    : null;
            }

            if ($kind === T_STRING) {
                return match (strtolower($text)) {
                    'true' => ['truthy' => true],
                    'false', 'null' => ['truthy' => false],
                    default => null,
                };
            }
        }

        $number = $this->signedNumericLiteral($tokens);
        if ($number !== null) {
            return preg_match('/\A[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:e[+-]?\d+)?\z/i', $number) === 1
                ? ['truthy' => (float) $number != 0.0]
                : null;
        }

        return $this->simpleLiteralArrayTruthiness($source);
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private function signedNumericLiteral(array $tokens): ?string
    {
        if (count($tokens) !== 2 || ! in_array($tokens[0], ['+', '-'], true)) {
            return null;
        }

        $number = $tokens[1];
        if (! is_array($number) || ! in_array($number[0], [T_LNUMBER, T_DNUMBER], true)) {
            return null;
        }

        return $tokens[0].$number[1];
    }

    /** @return array{truthy: bool}|null */
    private function simpleLiteralArrayTruthiness(string $source): ?array
    {
        if (str_starts_with($source, '[') && str_ends_with($source, ']')) {
            $inner = substr($source, 1, -1);
        } elseif (preg_match('/\Aarray\s*\(/i', $source, $match) === 1 && str_ends_with($source, ')')) {
            $inner = substr($source, strlen($match[0]), -1);
        } else {
            return null;
        }

        if (trim($inner) === '') {
            return ['truthy' => false];
        }

        $entries = $this->splitSpans($inner, 0);
        if ($entries === null || $entries === []) {
            return null;
        }

        if ($this->isTriviaSpan($entries[array_key_last($entries)])) {
            array_pop($entries);
        }

        foreach ($entries as $entry) {
            if (! $this->isSimpleLiteralArrayEntry($entry)) {
                return null;
            }
        }

        return ['truthy' => true];
    }

    /** @param array{source: string, offset: int} $span */
    private function isTriviaSpan(array $span): bool
    {
        if (trim($span['source']) === '') {
            return true;
        }

        $tokens = PhpSource::tokenize($span['source']);
        if ($tokens === null) {
            return false;
        }

        foreach ($tokens as $token) {
            if (! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array{source: string, offset: int} $entry */
    private function isSimpleLiteralArrayEntry(array $entry): bool
    {
        $tokens = PhpSource::tokenize($entry['source']);
        if ($tokens === null) {
            return false;
        }

        $depth = 0;
        $cursor = 0;
        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;

            if (is_array($token) && $token[0] === T_DOUBLE_ARROW && $depth === 0) {
                $key = ['source' => substr($entry['source'], 0, $cursor), 'offset' => 0];
                $value = ['source' => substr($entry['source'], $cursor + strlen($text)), 'offset' => 0];

                return $this->simpleLiteralTruthiness($key) !== null
                    && $this->simpleLiteralTruthiness($value) !== null;
            }

            $depth += PhpSource::nestingDelta($token);
            if ($depth < 0) {
                return false;
            }

            $cursor += strlen($text);
        }

        return $depth === 0 && $this->simpleLiteralTruthiness($entry) !== null;
    }

    /** @param array{source: string, offset: int} $argument */
    private function checkLiteral(
        array $argument,
        string $directive,
        Document $document,
        RuleContext $context,
        FileViewFinder $finder,
    ): void {
        $literal = $this->literal($argument);
        if ($literal === null) {
            return;
        }

        $this->reportIfMissing($literal, $directive, $document, $context, $finder);
    }

    /**
     * @param  array{value: string, start: int, end: int}  $literal
     */
    private function reportIfMissing(
        array $literal,
        string $directive,
        Document $document,
        RuleContext $context,
        FileViewFinder $finder,
    ): void {
        if ($this->viewExists($finder, $literal['value']) !== false) {
            return;
        }

        $context->reportAt(
            Position::fromOffset($document, $literal['start']),
            Position::fromOffset($document, $literal['end']),
            "View [{$literal['value']}] referenced by @{$directive} does not exist.",
        );
    }

    /** @param array{source: string, offset: int} $argument */
    private function checkFirst(
        array $argument,
        string $directive,
        Document $document,
        RuleContext $context,
        FileViewFinder $finder,
    ): void {
        $literals = $this->literalArray($argument);
        if ($literals === null || $literals === []) {
            return;
        }

        foreach ($literals as $literal) {
            $exists = $this->viewExists($finder, $literal['value']);
            if ($exists !== false) {
                return;
            }
        }

        $first = $literals[0];
        $names = implode(', ', array_column($literals, 'value'));

        $context->reportAt(
            Position::fromOffset($document, $first['start']),
            Position::fromOffset($document, $first['end']),
            "None of the views referenced by @{$directive} exist: {$names}.",
        );
    }

    private function finder(): ?FileViewFinder
    {
        try {
            $container = Container::getInstance();
            if (! $container->bound('view.finder')) {
                return null;
            }

            $finder = $container->make('view.finder');
            if (! $finder instanceof FileViewFinder) {
                return null;
            }

            $finder = clone $finder;
            $finder->flush();

            return $finder;
        } catch (Throwable) {
            return null;
        }
    }

    private function viewExists(FileViewFinder $finder, string $view): ?bool
    {
        try {
            $finder->find($view);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{paths: list<string>, hints: array<string, list<string>>, extensions: list<string>}|null
     */
    private function finderConfiguration(FileViewFinder $finder): ?array
    {
        $paths = $this->stringList($finder->getPaths());
        $extensions = $this->stringList($finder->getExtensions());
        $rawHints = $finder->getHints();

        if ($paths === null || $extensions === null || ! is_array($rawHints)) {
            return null;
        }

        $hints = [];
        foreach ($rawHints as $namespace => $rawPaths) {
            if (! is_string($namespace)) {
                return null;
            }

            $hintPaths = $this->stringList($rawPaths);
            if ($hintPaths === null) {
                return null;
            }

            $hints[$namespace] = $hintPaths;
        }

        ksort($hints);

        return [
            'paths' => array_map($this->normalizePath(...), $paths),
            'hints' => array_map(
                fn (array $hintPaths): array => array_map($this->normalizePath(...), $hintPaths),
                $hints,
            ),
            'extensions' => $extensions,
        ];
    }

    /** @return list<string>|null */
    private function stringList(mixed $values): ?array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            return null;
        }

        $strings = array_values(array_filter($values, is_string(...)));

        return count($strings) === count($values) ? $strings : null;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', realpath($path) ?: $path);
    }

    /**
     * @param  list<string>  $roots
     * @param  list<string>  $extensions
     * @return array<string, string>
     */
    private function viewIdentityFingerprint(array $roots, array $extensions): array
    {
        $fingerprints = [];

        foreach ($roots as $root) {
            $entries = [];
            if (! is_dir($root)) {
                $fingerprints[$root] = 'missing';

                continue;
            }

            try {
                $iterator = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                );

                foreach ($iterator as $file) {
                    if (! $file instanceof SplFileInfo
                        || ! $file->isFile()
                        || ! $this->hasViewExtension($file->getFilename(), $extensions)) {
                        continue;
                    }

                    $path = $this->normalizePath($file->getPathname());
                    $relative = ltrim(substr($path, strlen(rtrim($root, '/'))), '/');
                    $entries[] = $relative;
                }

                sort($entries);
                $encoded = json_encode($entries, JSON_THROW_ON_ERROR);
                $fingerprints[$root] = hash('xxh128', $encoded);
            } catch (Throwable $exception) {
                $fingerprints[$root] = 'unavailable:'.hash('xxh128', $exception::class.':'.$exception->getMessage());
            }
        }

        ksort($fingerprints);

        return $fingerprints;
    }

    /** @param list<string> $extensions */
    private function hasViewExtension(string $filename, array $extensions): bool
    {
        foreach ($extensions as $extension) {
            if (str_ends_with($filename, '.'.$extension)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{source: string, offset: int}>|null
     */
    private function argumentSpans(DirectiveNode $directive): ?array
    {
        $arguments = $directive->arguments();
        if ($arguments === null) {
            return null;
        }

        $leading = strlen($arguments) - strlen(ltrim($arguments));
        $trimmed = trim($arguments);
        if (! str_starts_with($trimmed, '(') || ! str_ends_with($trimmed, ')')) {
            return null;
        }

        $argumentOffset = $directive->startOffset()
            + 1
            + strlen($directive->name())
            + strlen($directive->whitespaceBetweenNameAndArgs() ?? '')
            + $leading;

        return $this->splitSpans(substr($trimmed, 1, -1), $argumentOffset + 1);
    }

    /**
     * @return list<array{source: string, offset: int}>|null
     */
    private function splitSpans(string $source, int $absoluteOffset): ?array
    {
        $tokens = PhpSource::tokenize($source);
        if ($tokens === null) {
            return null;
        }

        $parts = [];
        $depth = 0;
        $partStart = 0;
        $cursor = 0;

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $delta = PhpSource::nestingDelta($token);

            if ($token === ',' && $depth === 0) {
                $parts[] = [
                    'source' => substr($source, $partStart, $cursor - $partStart),
                    'offset' => $absoluteOffset + $partStart,
                ];
                $partStart = $cursor + 1;
                $cursor++;

                continue;
            }

            $depth += $delta;
            if ($depth < 0) {
                return null;
            }

            $cursor += strlen($text);
        }

        if ($depth !== 0) {
            return null;
        }

        $parts[] = [
            'source' => substr($source, $partStart),
            'offset' => $absoluteOffset + $partStart,
        ];

        return $parts;
    }

    /**
     * @param  array{source: string, offset: int}  $span
     * @return array{value: string, start: int, end: int}|null
     */
    private function literal(array $span): ?array
    {
        $tokens = PhpSource::tokenize($span['source']);
        if ($tokens === null) {
            return null;
        }

        $literal = null;
        $cursor = 0;
        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $ignored = is_array($token)
                && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true);

            if (! $ignored) {
                if ($literal !== null || ! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    return null;
                }

                $value = PhpSource::literalString($text);
                if ($value === null) {
                    return null;
                }

                $literal = [
                    'value' => $value,
                    'start' => $span['offset'] + $cursor,
                    'end' => $span['offset'] + $cursor + strlen($text),
                ];
            }

            $cursor += strlen($text);
        }

        return $literal;
    }

    /**
     * @param  array{source: string, offset: int}  $span
     * @return list<array{value: string, start: int, end: int}>|null
     */
    private function literalArray(array $span): ?array
    {
        $leading = strlen($span['source']) - strlen(ltrim($span['source']));
        $source = trim($span['source']);
        $offset = $span['offset'] + $leading;

        if (str_starts_with($source, '[') && str_ends_with($source, ']')) {
            $inner = substr($source, 1, -1);
            $innerOffset = $offset + 1;
        } elseif (preg_match('/\Aarray\s*\(/i', $source, $match) === 1 && str_ends_with($source, ')')) {
            $prefix = $match[0];
            $inner = substr($source, strlen($prefix), -1);
            $innerOffset = $offset + strlen($prefix);
        } else {
            return null;
        }

        if (trim($inner) === '') {
            return [];
        }

        $entries = $this->splitSpans($inner, $innerOffset);
        if ($entries === null) {
            return null;
        }

        if ($entries !== [] && $this->isTriviaSpan($entries[array_key_last($entries)])) {
            array_pop($entries);
        }

        $literals = [];
        foreach ($entries as $entry) {
            $value = $this->arrayEntryValue($entry);
            if ($value === null) {
                return null;
            }

            $literal = $this->literal($value);
            if ($literal === null) {
                return null;
            }

            $literals[] = $literal;
        }

        return $literals;
    }

    /**
     * @param  array{source: string, offset: int}  $entry
     * @return array{source: string, offset: int}|null
     */
    private function arrayEntryValue(array $entry): ?array
    {
        $tokens = PhpSource::tokenize($entry['source']);
        if ($tokens === null) {
            return null;
        }

        $depth = 0;
        $cursor = 0;
        $valueOffset = null;

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $delta = PhpSource::nestingDelta($token);

            if (is_array($token) && $token[0] === T_DOUBLE_ARROW && $depth === 0) {
                if ($valueOffset !== null) {
                    return null;
                }

                $valueOffset = $cursor + strlen($text);
            }

            $depth += $delta;
            if ($depth < 0) {
                return null;
            }

            $cursor += strlen($text);
        }

        if ($depth !== 0) {
            return null;
        }

        if ($valueOffset === null) {
            return $entry;
        }

        return [
            'source' => substr($entry['source'], $valueOffset),
            'offset' => $entry['offset'] + $valueOffset,
        ];
    }
}
