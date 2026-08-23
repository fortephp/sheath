<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Exceptions\ConfigurationException;
use Forte\Sheath\Files\PathMatcher;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Support\ReactiveAttributeSemantics;

abstract class AbstractRule implements Rule
{
    protected Severity $severity;

    /** @var array<string, mixed> */
    protected array $options = [];

    /**
     * Optional constraints that are stricter than the type inferred from the
     * declared default. Supported values are non-negative-integer,
     * positive-integer, and one-of:value,other-value.
     *
     * @var array<string, string>
     */
    protected array $optionRules = [];

    /**
     * @var array<string, mixed>
     */
    private readonly array $defaultOptions;

    /**
     * @var array<string, mixed>
     */
    private array $configuredOptions = [];

    private ?Document $elementIdIndexDocument = null;

    /** @var array<string, array<string, ElementNode>> */
    private array $elementsByTreeAndId = [];

    private ?Document $controlFlowExitIndexDocument = null;

    /** @var list<array{offset: int, targetStart: int, targetEnd: int}> */
    private array $controlFlowExits = [];

    public function __construct()
    {
        $this->severity = $this->getDefaultSeverity();
        $this->defaultOptions = $this->options;
    }

    abstract public function getId(): string;

    abstract public function getDescription(): string;

    abstract public function getCategory(): RuleCategory|string;

    abstract public function getDefaultSeverity(): Severity;

    abstract public function check(Document $document, RuleContext $context): void;

    public function getSeverity(): Severity
    {
        return $this->severity;
    }

    public function setSeverity(Severity $severity): void
    {
        $this->severity = $severity;
    }

    /**
     * @return array<string, mixed>
     */
    public function getOptions(): array
    {
        return $this->options;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function setOptions(array $options): void
    {
        $this->validateConfiguredOptions($options);

        $resolvedOptions = array_replace($this->defaultOptions, $options);
        $this->validateResolvedOptions($resolvedOptions);

        $this->configuredOptions = $options;
        $this->options = $resolvedOptions;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfiguredOptions(): array
    {
        return $this->configuredOptions;
    }

    public function isEnabled(): bool
    {
        return $this->severity !== Severity::OFF;
    }

    protected function getOption(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /** @param array<string, mixed> $options */
    protected function validateResolvedOptions(array $options): void {}

    /** @param array<string, mixed> $options */
    private function validateConfiguredOptions(array $options): void
    {
        foreach ($options as $key => $value) {
            if (! is_string($key)) {
                throw ConfigurationException::invalidRuleOption($this->getId(), (string) $key, 'a named option');
            }

            if ($key === 'exclude') {
                continue;
            }

            if (! array_key_exists($key, $this->defaultOptions)) {
                if ($this->defaultOptions === []
                    && ! str_starts_with(static::class, 'Forte\\Sheath\\Rules\\')) {
                    continue;
                }

                throw ConfigurationException::unknownRuleOption(
                    $this->getId(),
                    $key,
                    array_keys($this->defaultOptions),
                );
            }

            $constraint = $this->optionRules[$key] ?? null;
            if ($constraint !== null) {
                $this->assertConstraint($key, $value, $constraint);

                continue;
            }

            $default = $this->defaultOptions[$key];
            if (is_array($default)) {
                $this->assertStringList($key, $value);

                continue;
            }

            if (get_debug_type($value) !== get_debug_type($default)) {
                throw ConfigurationException::invalidRuleOption(
                    $this->getId(),
                    $key,
                    'a '.get_debug_type($default),
                );
            }
        }
    }

    private function assertConstraint(string $key, mixed $value, string $constraint): void
    {
        $valid = match (true) {
            $constraint === 'non-negative-integer' => is_int($value) && $value >= 0,
            $constraint === 'positive-integer' => is_int($value) && $value > 0,
            $constraint === 'nullable-string-list' => $value === null || $this->isStringList($value),
            str_starts_with($constraint, 'one-of:') => is_string($value)
                && in_array($value, explode(',', substr($constraint, 7)), true),
            default => false,
        };

        if (! $valid) {
            $expected = match (true) {
                $constraint === 'non-negative-integer' => 'a non-negative integer',
                $constraint === 'positive-integer' => 'a positive integer',
                $constraint === 'nullable-string-list' => 'null or a list of strings',
                str_starts_with($constraint, 'one-of:') => 'one of '.implode(', ', explode(',', substr($constraint, 7))),
                default => $constraint,
            };

            throw ConfigurationException::invalidRuleOption($this->getId(), $key, $expected);
        }
    }

    private function assertStringList(string $key, mixed $value): void
    {
        if (! $this->isStringList($value)) {
            throw ConfigurationException::invalidRuleOption($this->getId(), $key, 'a list of strings');
        }
    }

    private function isStringList(mixed $value): bool
    {
        return is_array($value)
            && array_is_list($value)
            && array_filter($value, is_string(...)) === $value;
    }

    protected function isNonOutputCaptureBlock(Node $node): bool
    {
        if (! $node instanceof DirectiveBlockNode) {
            return false;
        }

        if ($node->isAnyDirectiveNamed(['push', 'pushif', 'pushonce', 'prepend', 'prependonce'])) {
            return true;
        }

        return $node->isDirectiveNamed('section')
            && ! ($node->endDirective()?->isDirectiveNamed('show') ?? false);
    }

    protected function isInsideNonOutputCapture(Node $node): bool
    {
        foreach ($node->ancestors() as $ancestor) {
            if ($this->isNonOutputCaptureBlock($ancestor)) {
                return true;
            }
        }

        return false;
    }

    protected function elementByIdInTree(ElementNode $context, string $id): ?ElementNode
    {
        $this->buildTreeScopedElementIdIndex($context->getDocument());

        return $this->elementsByTreeAndId[$this->elementTreeKey($context)][$id] ?? null;
    }

    protected function elementsShareTree(ElementNode $a, ElementNode $b): bool
    {
        return $this->elementTreeKey($a) === $this->elementTreeKey($b);
    }

    protected function elementTreeKey(ElementNode $element): string
    {
        $ancestor = $element->getParent();

        while ($ancestor !== null) {
            if ($this->isRenderedTreeBoundary($ancestor)) {
                // Teleports sever DOM ancestry, but their content remains in
                // this document's global ID space. Native inert templates are
                // the only element boundary with an independent ID scope.
                if (($ancestor instanceof ElementNode
                        && ReactiveAttributeSemantics::isTeleportTemplateRenderer($ancestor))
                    || ($ancestor instanceof DirectiveBlockNode
                        && $ancestor->isDirectiveNamed('teleport'))) {
                    $ancestor = $ancestor->getParent();

                    continue;
                }

                $kind = $ancestor instanceof ElementNode ? 'template' : 'teleport';

                return $kind.':'.$ancestor->index();
            }

            $ancestor = $ancestor->getParent();
        }

        return 'document';
    }

    protected function isRenderedTreeBoundary(Node $node): bool
    {
        return ($node instanceof ElementNode
                && $node->isTag('template')
                && ! ReactiveAttributeSemantics::isLocalTemplateRenderer($node))
            || ($node instanceof DirectiveBlockNode && $node->isDirectiveNamed('teleport'));
    }

    protected function crossesRenderedTreeBoundary(Node $node, Node $boundary): bool
    {
        $ancestor = $node->getParent();

        while ($ancestor !== null && $ancestor !== $boundary) {
            if ($this->isRenderedTreeBoundary($ancestor)) {
                return true;
            }

            $ancestor = $ancestor->getParent();
        }

        return false;
    }

    /**
     * Whether every render that contains $subject also contains $required.
     *
     * A required node outside the subject's control-flow ancestry is always
     * available. A required node inside a conditional, loop, switch arm, or
     * captured block is available only when the subject shares that exact arm.
     */
    protected function nodeRendersWhenever(Node $required, Node $subject): bool
    {
        $subjectAncestors = [];
        foreach ($subject->ancestors() as $ancestor) {
            $subjectAncestors[$ancestor->index()] = true;
        }

        foreach ($required->ancestors() as $ancestor) {
            $block = $ancestor->getParent();

            if (! $ancestor instanceof DirectiveNode
                || ! $block instanceof DirectiveBlockNode
                || ! $this->directiveBlockControlsPresence($block)) {
                continue;
            }

            if (! isset($subjectAncestors[$ancestor->index()])) {
                return false;
            }
        }

        if ($required->startOffset() > $subject->endOffset()
            && $this->controlFlowCanExitBetween($subject, $required)) {
            return false;
        }

        return true;
    }

    private function controlFlowCanExitBetween(Node $subject, Node $required): bool
    {
        $document = $subject->getDocument();
        $this->buildControlFlowExitIndex($document);
        if ($this->controlFlowExits === []) {
            return false;
        }

        $index = $this->firstControlFlowExitAtOrAfter($subject->endOffset());
        $count = count($this->controlFlowExits);
        while ($index < $count) {
            $exit = $this->controlFlowExits[$index];
            if ($exit['offset'] >= $required->startOffset()) {
                break;
            }

            if ($exit['targetStart'] <= $subject->startOffset()
                && $exit['targetEnd'] >= $required->endOffset()) {
                return true;
            }

            $index++;
        }

        return false;
    }

    private function buildControlFlowExitIndex(Document $document): void
    {
        if ($this->controlFlowExitIndexDocument === $document) {
            return;
        }

        $this->controlFlowExitIndexDocument = $document;
        $this->controlFlowExits = [];

        foreach (array_keys($document->getNodes()) as $index) {
            $node = $document->getNode($index);
            if (! $node instanceof DirectiveNode
                || ! in_array(strtolower($node->nameText()), ['break', 'continue'], true)) {
                continue;
            }

            $target = $this->controlFlowExitTarget($node);
            if ($target !== null) {
                $this->controlFlowExits[] = [
                    'offset' => $node->startOffset(),
                    'targetStart' => $target->startOffset(),
                    'targetEnd' => $target->endOffset(),
                ];
            }
        }

        usort(
            $this->controlFlowExits,
            static fn (array $left, array $right): int => $left['offset'] <=> $right['offset'],
        );
    }

    private function firstControlFlowExitAtOrAfter(int $offset): int
    {
        $low = 0;
        $high = count($this->controlFlowExits);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->controlFlowExits[$middle]['offset'] < $offset) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low;
    }

    private function controlFlowExitTarget(DirectiveNode $exit): ?DirectiveBlockNode
    {
        $name = strtolower($exit->nameText());
        $arguments = trim($exit->arguments() ?? '');
        $level = ctype_digit($arguments) && (int) $arguments > 0 ? (int) $arguments : 1;
        $candidate = $exit->getParent();

        while ($candidate !== null) {
            if ($candidate instanceof DirectiveBlockNode) {
                $blockName = strtolower($candidate->nameText());
                $isTarget = in_array($blockName, ['foreach', 'forelse', 'for', 'while'], true)
                    || ($name === 'break' && $blockName === 'switch');
                if ($isTarget && --$level === 0) {
                    return $candidate;
                }
            }

            $candidate = $candidate->getParent();
        }

        return null;
    }

    private function directiveBlockControlsPresence(DirectiveBlockNode $block): bool
    {
        return ! ($block->isDirectiveNamed('section')
            && ($block->endDirective()?->isDirectiveNamed('show') ?? false));
    }

    private function buildTreeScopedElementIdIndex(Document $document): void
    {
        if ($this->elementIdIndexDocument === $document) {
            return;
        }

        $this->elementIdIndexDocument = $document;
        $this->elementsByTreeAndId = [];

        foreach ($document->queryElements() as $element) {
            $id = $element->staticAttributeValue('id');
            if ($id === null || $id === '') {
                continue;
            }

            $tree = $this->elementTreeKey($element);
            $this->elementsByTreeAndId[$tree][$id] ??= $element;
        }
    }

    protected function findWhitespaceStart(string $source, int $offset): int
    {
        $start = $offset;

        while ($start > 0 && ctype_space($source[$start - 1])) {
            $start--;
        }

        return $start;
    }

    protected function sourceNewline(string $source): string
    {
        $lineFeed = strpos($source, "\n");

        if ($lineFeed === false) {
            return "\n";
        }

        return $lineFeed > 0 && $source[$lineFeed - 1] === "\r"
            ? "\r\n"
            : "\n";
    }

    protected function hasPackage(RuleContext $context, string $package): bool
    {
        return $context->hasPackage($package);
    }

    protected function packageSatisfies(RuleContext $context, string $package, string $constraint): bool
    {
        return $context->packageSatisfies($package, $constraint);
    }

    protected function getPackageVersion(RuleContext $context, string $package): ?string
    {
        return $context->getDependencies()?->version($package);
    }

    protected function packageVersionAtLeast(RuleContext $context, string $package, string $minVersion): bool
    {
        return $context->getDependencies()?->compare($package, '>=', $minVersion) ?? false;
    }

    protected function configuredRuleShouldReport(
        RuleContext $context,
        string $ruleId,
        Severity $defaultSeverity,
    ): bool {
        $config = $context->getConfig();
        $exclusions = $config->getRuleExclusions($ruleId);

        return $config->hasRule($ruleId)
            && ($config->getRuleSeverity($ruleId) ?? $defaultSeverity)->shouldReport()
            && ($exclusions === [] || ! PathMatcher::matchesAny($exclusions, $context->getFilePath()));
    }
}
