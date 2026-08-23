<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Helpers;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\EchoNode;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Parsing\JsSourceScanner;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsExclusiveBranches;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Support\JavaScriptMimeType;

/** @internal */
class PreferJsonInScriptRule extends AbstractRule
{
    use DetectsExclusiveBranches;
    use DetectsOpaqueAttributes;

    /**
     * @var array<string>
     */
    private const EXECUTABLE_SCRIPT_TYPES = [
        '',
        'module',
    ];

    /**
     * @var array<string>
     */
    private const JSON_DATA_TYPES = [
        'application/json',
        'application/ld+json',
        'importmap',
        'speculationrules',
    ];

    public function getId(): string
    {
        return 'blade-prefer-json-in-script';
    }

    public function getDescription(): string
    {
        return 'JavaScript values must use @json(...) or Js::from(...).';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $context->elements()->each(function (ElementNode $element) use ($document, $context): void {
            if (! $element->isTag('script')) {
                return;
            }

            $contexts = $this->scriptContexts($element);
            if ($contexts === null) {
                return;
            }

            $this->checkScript($element, $document, $context, $contexts);
        });
    }

    /**
     * @return list<array{kind: 'json'|'executable'|'ambiguous', predicates: array<string, bool>}>|null
     */
    private function scriptContexts(ElementNode $element): ?array
    {
        if ($this->elementHasUnmodelledAttributes($element)) {
            return null;
        }

        $attributes = $this->attributesInRenderStructure($element, 'type');
        $models = [];
        $expressions = [];

        foreach ($attributes as $attribute) {
            $identities = $this->conditionalPredicateIdentities($attribute);
            if ($this->hasUnmodelledConditionalAncestry($attribute, count($identities))) {
                return null;
            }

            $predicates = [];
            foreach ($identities as $identity) {
                if (isset($predicates[$identity['expression']])
                    && $predicates[$identity['expression']] !== $identity['when']) {
                    return null;
                }

                $predicates[$identity['expression']] = $identity['when'];
                $expressions[$identity['expression']] = true;
            }

            $models[] = ['attribute' => $attribute, 'predicates' => $predicates];
        }

        if (count($expressions) > 7) {
            return null;
        }

        $assignments = [[]];
        foreach (array_keys($expressions) as $expression) {
            $expanded = [];
            foreach ($assignments as $assignment) {
                $expanded[] = $assignment + [$expression => false];
                $expanded[] = $assignment + [$expression => true];
            }

            $assignments = $expanded;
        }

        $contexts = [];
        foreach ($assignments as $assignment) {
            $kind = 'executable';

            foreach ($models as $model) {
                if (! $this->predicateAssignmentMatches($assignment, $model['predicates'])) {
                    continue;
                }

                $kind = $this->scriptTypeKind($model['attribute']);

                break;
            }

            $contexts[] = ['kind' => $kind, 'predicates' => $assignment];
        }

        return $contexts;
    }

    /**
     * @param  list<array{kind: 'json'|'executable'|'ambiguous', predicates: array<string, bool>}>  $contexts
     */
    private function checkScript(
        ElementNode $element,
        Document $document,
        RuleContext $context,
        array $contexts,
    ): void {
        $echoes = $this->contentEchoes($element);

        if ($echoes === []) {
            return;
        }

        $contentStart = $document->findOpeningTagEndPosition($element->index());

        if ($contentStart <= 0) {
            return;
        }

        /** @var array<array{int, int}> $opaqueSpans */
        $opaqueSpans = array_map(
            static fn (EchoNode $echo): array => [$echo->startOffset(), $echo->endOffset()],
            $echoes
        );

        /** @var list<array{EchoNode, string, bool, 'encode'|'from'|null}> $candidates */
        $candidates = [];

        foreach ($echoes as $echo) {
            if ($echo->echoType() !== 'escaped') {
                continue;
            }

            $expression = trim($echo->content());
            $serializer = $this->jsSerializerMethod($expression);
            $echoPredicates = [];

            foreach ($this->conditionalPredicateIdentities($echo) as $identity) {
                if (isset($echoPredicates[$identity['expression']])
                    && $echoPredicates[$identity['expression']] !== $identity['when']) {
                    continue 2;
                }

                $echoPredicates[$identity['expression']] = $identity['when'];
            }

            $reachableKinds = [];
            foreach ($contexts as $scriptContext) {
                if ($this->predicateAssignmentMatches($scriptContext['predicates'], $echoPredicates)) {
                    $reachableKinds[$scriptContext['kind']] = true;
                }
            }

            if ($expression === '') {
                continue;
            }

            if ($serializer === 'from') {
                if (! isset($reachableKinds['json'])) {
                    continue;
                }

                $candidates[] = [$echo, $expression, true, $serializer];

                continue;
            }

            if (! isset($reachableKinds['json']) && ! isset($reachableKinds['executable'])) {
                continue;
            }

            $candidates[] = [$echo, $expression, false, $serializer];
        }

        $positions = JsSourceScanner::classifyPositions(
            $document->source(),
            $contentStart,
            array_map(static fn (array $candidate): int => $candidate[0]->startOffset(), $candidates),
            $opaqueSpans,
        );

        foreach ($candidates as [$echo, $expression, $invalidJsonExpression, $serializer]) {
            if ($invalidJsonExpression) {
                $context->report(
                    $echo,
                    'This script requires JSON text, not a JavaScript expression.'
                );

                continue;
            }

            $isValuePosition = $positions[$echo->startOffset()];

            if ($serializer === 'encode') {
                $context->report(
                    $echo,
                    'HTML escaping produces invalid JSON in this script.'
                );

                continue;
            }

            $context->report(
                $echo,
                $isValuePosition
                    ? 'Blade echo does not safely serialize this JavaScript value.'
                    : 'Blade echo is unsafe in this JavaScript context.'
            );
        }
    }

    private function hasUnmodelledConditionalAncestry(Attribute $attribute, int $modelledPredicates): bool
    {
        $parentIndex = $attribute->getFlatNode()['parent'];
        $ancestor = $parentIndex >= 0 ? $attribute->getDocument()->getNode($parentIndex) : null;
        $conditionalDepth = 0;

        while ($ancestor !== null) {
            $parent = $ancestor->getParent();

            if ($ancestor instanceof DirectiveNode
                && ($ancestor->isOpening() || $ancestor->isIntermediate())
                && $parent instanceof DirectiveBlockNode) {
                $conditionalDepth++;
            }

            $ancestor = $parent;
        }

        return $conditionalDepth !== $modelledPredicates;
    }

    /**
     * @param  array<string, bool>  $assignment
     * @param  array<string, bool>  $required
     */
    private function predicateAssignmentMatches(array $assignment, array $required): bool
    {
        foreach ($required as $expression => $when) {
            if (isset($assignment[$expression]) && $assignment[$expression] !== $when) {
                return false;
            }
        }

        return true;
    }

    /** @return 'json'|'executable'|'ambiguous' */
    private function scriptTypeKind(Attribute $attribute): string
    {
        if ($attribute->isDynamic()) {
            return 'ambiguous';
        }

        $type = JavaScriptMimeType::normalizeScriptType($attribute->decodedValueText() ?? '');
        if (in_array($type, self::JSON_DATA_TYPES, true)) {
            return 'json';
        }

        if (JavaScriptMimeType::isEssenceMatch($type)
            || in_array($type, self::EXECUTABLE_SCRIPT_TYPES, true)) {
            return 'executable';
        }

        return 'ambiguous';
    }

    /**
     * @return array<EchoNode>
     */
    private function contentEchoes(ElementNode $element): array
    {
        $echoes = [];

        foreach ($element->descendants() as $descendant) {
            if ($descendant instanceof EchoNode && $descendant->startOffset() >= 0) {
                $echoes[] = $descendant;
            }
        }

        return $echoes;
    }

    /** @return 'encode'|'from'|null */
    private function jsSerializerMethod(string $expression): ?string
    {
        $tokens = PhpSource::tokenize($expression);
        if ($tokens === null) {
            return null;
        }

        $tokens = array_values(array_filter(
            $tokens,
            static fn (array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));

        if (count($tokens) < 5 || ! is_array($tokens[0])) {
            return null;
        }

        $class = strtolower(ltrim($tokens[0][1], '\\'));
        if (! in_array($class, ['js', 'illuminate\\support\\js'], true)) {
            return null;
        }

        $method = $this->jsSerializerMethodName($tokens);
        if ($method === null) {
            return null;
        }

        $depth = 0;
        foreach (array_slice($tokens, 3) as $index => $token) {
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;

                if ($depth === 0) {
                    return $index === count($tokens) - 4 ? $method : null;
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{int, string, int}|string>  $tokens
     * @return 'encode'|'from'|null
     */
    private function jsSerializerMethodName(array $tokens): ?string
    {
        $operator = $tokens[1] ?? null;
        $method = $tokens[2] ?? null;

        if (! is_array($operator) || $operator[0] !== T_DOUBLE_COLON) {
            return null;
        }

        if (! is_array($method) || $method[0] !== T_STRING || ($tokens[3] ?? null) !== '(') {
            return null;
        }

        $name = strtolower($method[1]);

        return in_array($name, ['encode', 'from'], true) ? $name : null;
    }
}
