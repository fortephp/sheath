<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Structure;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Accessibility\Aria\AriaData;
use Forte\Sheath\Rules\Accessibility\Aria\NoInvalidRoleRule;
use Forte\Sheath\Rules\Concerns\ChecksAccessibility;
use Forte\Sheath\Rules\Concerns\DetectsExclusiveBranches;
use Forte\Sheath\Rules\Concerns\TraversesRenderedTree;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\ReactiveAttributeSemantics;
use Forte\Support\HtmlInteger;

/** @internal */
class TableHeadersRule extends AbstractRule
{
    use ChecksAccessibility;
    use DetectsExclusiveBranches;
    use TraversesRenderedTree;

    private const MAX_TABLE_OUTCOMES = 128;

    protected array $options = [
        'requireScope' => false,
        'minRows' => 2,
    ];

    protected array $optionRules = [
        'minRows' => 'positive-integer',
    ];

    public function getId(): string
    {
        return 'a11y-table-headers';
    }

    public function getDescription(): string
    {
        return 'Tables must have proper header cells for accessibility.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::ACCESSIBILITY;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $requireScope = (bool) $this->getOption('requireScope', false);
        /** @var int $minRows */
        $minRows = $this->getOption('minRows', 2);

        $document->queryElements('table')->each(function (ElementNode $table) use ($context, $minRows, $requireScope): void {
            if ($this->isUnconditionallyExcludedFromAccessibilityTree($table)
                || $this->tableHasUnresolvedConditionalExposure($table)
                || $this->hasOnlyUnconflictedPresentationalRolePaths($table)) {
                return;
            }

            $role = $this->effectiveRole($table);
            $hasPresentationalRole = $role === 'presentation' || $role === 'none';
            $presentationRoleConflicts = $hasPresentationalRole && $this->presentationRoleConflicts($table);
            if ($hasPresentationalRole && ! $presentationRoleConflicts) {
                return;
            }

            $hasHeaders = false;
            $scopeIssues = [];

            $table->walkDescendants(
                function (Node $node) use (&$hasHeaders, &$scopeIssues, $requireScope): void {
                    if (! $node instanceof ElementNode) {
                        return;
                    }

                    if (! $this->isHeaderCell($node)) {
                        return;
                    }

                    $hasHeaders = true;
                    if ($requireScope) {
                        $scopeAttributes = $this->firstAttributesOnRenderPaths($node, 'scope');
                        if ($scopeAttributes === null) {
                            return;
                        }

                        foreach ($scopeAttributes as $scopeAttribute) {
                            if ($scopeAttribute === null) {
                                $scopeIssues[] = [
                                    'header' => $node,
                                    'message' => 'Table header is missing a scope attribute.',
                                ];

                                break;
                            }

                            if ($scopeAttribute->isDynamic()) {
                                continue;
                            }

                            $scope = strtolower($scopeAttribute->decodedValueText() ?? '');
                            if (in_array($scope, ['row', 'col', 'rowgroup', 'colgroup'], true)) {
                                continue;
                            }

                            $scopeIssues[] = [
                                'header' => $node,
                                'message' => 'Invalid table header scope; expected row, col, rowgroup, or colgroup.',
                            ];

                            break;
                        }
                    }
                },
                fn (Node $node): bool => (! $node instanceof ElementNode
                        || ! $node->isTag('table')
                        && (! $node->isTag('template') || ReactiveAttributeSemantics::isLocalTemplateRenderer($node)))
                    && (! $node instanceof DirectiveBlockNode || ! $this->isNonOutputCaptureBlock($node)),
            );

            $tableOutcomes = $this->tableOutcomes($table->children(), $minRows);
            $qualifyingOutcomes = array_filter(
                $tableOutcomes,
                static fn (array $outcome): bool => $presentationRoleConflicts || $outcome['rows'] >= $minRows,
            );
            if ($qualifyingOutcomes === []) {
                return;
            }

            $hasQualifyingHeaderlessPath = false;
            foreach ($qualifyingOutcomes as $outcome) {
                if (! $outcome['header']) {
                    $hasQualifyingHeaderlessPath = true;

                    break;
                }
            }

            if ($hasQualifyingHeaderlessPath) {
                $context->report(
                    $table,
                    $hasHeaders
                        ? 'Data table has no header cells on one or more render paths.'
                        : 'Data table has no header cells.'
                );

                return;
            }

            if ($requireScope) {
                foreach ($scopeIssues as $issue) {
                    $issueOutcomes = $this->tableOutcomes(
                        $table->children(),
                        $minRows,
                        $issue['header'],
                    );

                    foreach ($issueOutcomes as $outcome) {
                        if (($presentationRoleConflicts || $outcome['rows'] >= $minRows)
                            && $outcome['header']) {
                            $context->report($issue['header'], $issue['message']);

                            break;
                        }
                    }
                }
            }
        });
    }

    private function tableHasUnresolvedConditionalExposure(ElementNode $table): bool
    {
        if ($this->elementHasUnresolvedConditionalExposure($table)) {
            return true;
        }

        $unresolved = false;
        $table->walkDescendants(
            function (Node $node) use (&$unresolved): void {
                if ($node instanceof ElementNode && $this->elementHasUnresolvedConditionalExposure($node)) {
                    $unresolved = true;
                }
            },
            fn (Node $node): bool => (! $node instanceof ElementNode
                    || ! $node->isTag('table')
                    && (! $node->isTag('template') || ReactiveAttributeSemantics::isLocalTemplateRenderer($node)))
                && (! $node instanceof DirectiveBlockNode || ! $this->isNonOutputCaptureBlock($node)),
        );

        return $unresolved;
    }

    private function elementHasUnresolvedConditionalExposure(ElementNode $element): bool
    {
        foreach ($this->attributesInRenderStructure($element, ['hidden', 'inert', 'aria-hidden']) as $attribute) {
            if ($attribute->isDynamic() || $attribute->isBound()) {
                return true;
            }

            if ($attribute->isNamed('aria-hidden')
                && strtolower($attribute->decodedValueText() ?? '') !== 'true') {
                continue;
            }

            if (! $attribute->isUnconditionallyPresent()
                || $this->conditionalBranchProvidingAttribute($element, $attribute) !== null) {
                return true;
            }
        }

        return false;
    }

    private function effectiveRole(ElementNode $element): ?string
    {
        if ($element->attributeIsDynamic('role')) {
            return null;
        }

        foreach ($element->staticAttributeTokensLower('role') ?? [] as $role) {
            if (NoInvalidRoleRule::isValidRole($role)) {
                return $role;
            }
        }

        return null;
    }

    private function isHeaderCell(ElementNode $element): bool
    {
        if (! $element->isTag('th')
            || $this->isUnconditionallyExcludedFromAccessibilityTree($element)) {
            return false;
        }

        $paths = $this->explicitAttributeRenderPaths($element, [
            'role', 'hidden', 'inert', 'aria-hidden', 'tabindex', 'contenteditable',
            ...AriaData::globalProperties(),
        ]);
        if ($paths === null) {
            return true;
        }

        $hasExposedPath = false;
        foreach ($paths as $path) {
            if ($this->accessibilityAttributePathIsExcluded($path)) {
                continue;
            }

            $hasExposedPath = true;
            $roleAttribute = $this->firstAttributeOnRenderPath($path, 'role');
            if ($roleAttribute === null) {
                continue;
            }
            if ($roleAttribute->isDynamic()) {
                continue;
            }

            $role = $this->recognizedRole($roleAttribute);
            if (in_array($role, ['none', 'presentation'], true)
                && $this->presentationRoleConflictsOnPath($path)) {
                continue;
            }

            if ($role !== null && ! in_array($role, ['columnheader', 'rowheader'], true)) {
                return false;
            }
        }

        return $hasExposedPath;
    }

    private function hasOnlyUnconflictedPresentationalRolePaths(ElementNode $table): bool
    {
        if ($this->isContentEditable($table)) {
            return false;
        }

        $paths = $this->explicitAttributeRenderPaths($table, [
            'role', 'tabindex', 'contenteditable', ...AriaData::globalProperties(),
        ]);
        if ($paths === null || $paths === []) {
            return false;
        }

        foreach ($paths as $path) {
            $roleAttribute = $this->firstAttributeOnRenderPath($path, 'role');
            if ($roleAttribute === null || $roleAttribute->isDynamic()) {
                return false;
            }
            if (! in_array($this->recognizedRole($roleAttribute), ['none', 'presentation'], true)
                || $this->presentationRoleConflictsOnPath($path)) {
                return false;
            }
        }

        return true;
    }

    private function recognizedRole(Attribute $attribute): ?string
    {
        foreach ($attribute->tokensLower() as $role) {
            if (NoInvalidRoleRule::isValidRole($role)) {
                return $role;
            }
        }

        return null;
    }

    /** @param list<Attribute> $path */
    private function presentationRoleConflictsOnPath(array $path): bool
    {
        $tabindex = $this->firstAttributeOnRenderPath($path, 'tabindex');
        if ($tabindex !== null) {
            $value = $tabindex->decodedValueText();
            if ($tabindex->isDynamic() || ($value !== null && HtmlInteger::parse($value) !== null)) {
                return true;
            }
        }

        $contenteditable = $this->firstAttributeOnRenderPath($path, 'contenteditable');
        if ($contenteditable !== null) {
            if ($contenteditable->isDynamic()) {
                return true;
            }

            if (in_array(strtolower($contenteditable->decodedValueText() ?? ''), ['', 'true', 'plaintext-only'], true)) {
                return true;
            }
        }

        foreach (AriaData::globalProperties() as $name) {
            if ($this->firstAttributeOnRenderPath($path, $name) !== null) {
                return true;
            }
        }

        return false;
    }

    private function presentationRoleConflicts(ElementNode $element): bool
    {
        foreach ($this->attributesInRenderStructure($element, 'tabindex') as $tabindex) {
            $value = $tabindex->decodedValueText();
            if ($tabindex->hasComplexValue() || ($value !== null && HtmlInteger::parse($value) !== null)) {
                return true;
            }
        }

        if ($this->isContentEditable($element)) {
            return true;
        }

        foreach ($this->attributesInRenderStructure($element, AriaData::globalProperties()) as $attribute) {
            if (in_array(strtolower($attribute->name()->rawName()), AriaData::globalProperties(), true)) {
                return true;
            }
        }

        return false;
    }

    private function isContentEditable(ElementNode $element): bool
    {
        $candidate = $element;

        while (true) {
            $paths = $this->explicitAttributeRenderPaths($candidate, ['contenteditable']);
            if ($paths === null) {
                return true;
            }

            $allPathsExplicitlyFalse = $paths !== [];
            foreach ($paths as $path) {
                $attribute = $this->firstAttributeOnRenderPath($path, 'contenteditable');
                if ($attribute === null) {
                    $allPathsExplicitlyFalse = false;

                    continue;
                }

                if ($attribute->isDynamic()) {
                    return true;
                }

                $value = strtolower($attribute->decodedValueText() ?? '');
                if (in_array($value, ['', 'true', 'plaintext-only'], true)) {
                    return true;
                }

                if ($value === 'false') {
                    continue;
                }

                $allPathsExplicitlyFalse = false;
            }

            if ($allPathsExplicitlyFalse) {
                return false;
            }

            $parent = $this->renderedParentElement($candidate);
            if ($parent === null || $parent->isTag('template')) {
                return false;
            }

            $candidate = $parent;
        }

    }

    /**
     * @param  iterable<mixed>  $nodes
     * @return list<array{rows: int, header: bool, flow: string|null, predicates: array<string, bool>}>
     */
    private function tableOutcomes(iterable $nodes, int $cap, ?ElementNode $trackedHeader = null): array
    {
        $states = [['rows' => 0, 'header' => false, 'flow' => null, 'predicates' => []]];

        foreach ($nodes as $node) {
            $nodeOutcomes = $this->tableNodeOutcomes($node, $cap, $trackedHeader);
            $next = [];

            foreach ($states as $state) {
                if ($state['flow'] !== null) {
                    $next[] = $state;

                    continue;
                }

                foreach ($nodeOutcomes as $outcome) {
                    $predicates = $this->mergeTablePredicates($state['predicates'], $outcome['predicates']);
                    if ($predicates === null) {
                        continue;
                    }

                    $next[] = [
                        'rows' => min($cap, $state['rows'] + $outcome['rows']),
                        'header' => $state['header'] || $outcome['header'],
                        'flow' => $outcome['flow'],
                        'predicates' => $predicates,
                    ];
                }
            }

            $states = $this->uniqueTableOutcomes($next);
        }

        return $states;
    }

    /** @return list<array{rows: int, header: bool, flow: string|null, predicates: array<string, bool>}> */
    private function tableNodeOutcomes(mixed $node, int $cap, ?ElementNode $trackedHeader): array
    {
        if ($node instanceof ElementNode) {
            if ($node->isTag('table') || $this->isUnconditionallyExcludedFromAccessibilityTree($node)) {
                return [['rows' => 0, 'header' => false, 'flow' => null, 'predicates' => []]];
            }

            if ($node->isTag('template')) {
                if (! ReactiveAttributeSemantics::isLocalTemplateRenderer($node)) {
                    return [['rows' => 0, 'header' => false, 'flow' => null, 'predicates' => []]];
                }

                return $this->reactiveTemplateTableOutcomes($node, $cap, $trackedHeader);
            }

            $outcomes = $this->tableOutcomes($node->children(), $cap, $trackedHeader);
            $isRow = $node->isTag('tr');
            $isHeader = $trackedHeader === null
                ? $this->isHeaderCell($node)
                : $node === $trackedHeader;

            foreach ($outcomes as &$outcome) {
                if ($isRow) {
                    $outcome['rows'] = min($cap, $outcome['rows'] + 1);
                }
                $outcome['header'] = $outcome['header'] || $isHeader;
            }
            unset($outcome);

            $visibilityConstraints = [];
            foreach ($this->attributesInRenderStructure($node) as $attribute) {
                $constraint = ReactiveAttributeSemantics::visibilityConstraint($attribute, $node);
                if ($constraint !== null) {
                    $visibilityConstraints[$constraint] = true;
                }
            }
            $visibilityConstraints = array_keys($visibilityConstraints);
            if ($visibilityConstraints !== []) {
                foreach ($outcomes as &$outcome) {
                    foreach ($visibilityConstraints as $constraint) {
                        $outcome['predicates'][$constraint] = true;
                    }
                }
                unset($outcome);

                foreach ($visibilityConstraints as $constraint) {
                    $outcomes[] = [
                        'rows' => 0,
                        'header' => false,
                        'flow' => null,
                        'predicates' => [$constraint => false],
                    ];
                }
            }

            return $outcomes;
        }

        if ($node instanceof DirectiveNode) {
            $name = strtolower($node->nameText());
            if (! in_array($name, ['break', 'continue'], true)) {
                return [['rows' => 0, 'header' => false, 'flow' => null, 'predicates' => []]];
            }

            $flow = ['rows' => 0, 'header' => false, 'flow' => $name, 'predicates' => []];

            return trim($node->arguments() ?? '') === ''
                ? [$flow]
                : [['rows' => 0, 'header' => false, 'flow' => null, 'predicates' => []], $flow];
        }

        if (! $node instanceof DirectiveBlockNode) {
            return [['rows' => 0, 'header' => false, 'flow' => null, 'predicates' => []]];
        }

        if ($this->isNonOutputCaptureBlock($node)) {
            return [['rows' => 0, 'header' => false, 'flow' => null, 'predicates' => []]];
        }

        $start = $node->startDirective();
        if ($start === null) {
            return [['rows' => 0, 'header' => false, 'flow' => null, 'predicates' => []]];
        }

        $name = strtolower($node->nameText());
        if ($name === 'section') {
            return $this->tableOutcomes($start->children(), $cap, $trackedHeader);
        }

        if ($name === 'switch') {
            return $this->tableSwitchOutcomes($start, $cap, $trackedHeader);
        }

        if (in_array($name, ['foreach', 'forelse', 'for', 'while'], true)) {
            return $this->tableLoopOutcomes($node, $start, $cap, $trackedHeader);
        }

        $outcomes = [];
        $hasFallback = false;
        foreach ([$start, ...iterator_to_array($node->intermediateDirectives())] as $branch) {
            $branchName = strtolower($branch->nameText());
            $hasFallback = $hasFallback || in_array($branchName, ['else', 'empty', 'default'], true);
            $branchOutcomes = $this->tableOutcomes($branch->children(), $cap, $trackedHeader);
            $predicate = $this->conditionalPredicateIdentity($branch);
            foreach ($branchOutcomes as &$branchOutcome) {
                if ($predicate !== null) {
                    $branchOutcome['predicates'][$predicate['expression']] = $predicate['when'];
                }
            }
            unset($branchOutcome);
            array_push($outcomes, ...$branchOutcomes);
        }

        if (! $hasFallback) {
            $predicates = [];
            $openingPredicate = $this->conditionalPredicateIdentity($start);
            if ($openingPredicate !== null) {
                $predicates[$openingPredicate['expression']] = ! $openingPredicate['when'];
            }
            $outcomes[] = ['rows' => 0, 'header' => false, 'flow' => null, 'predicates' => $predicates];
        }

        return $this->uniqueTableOutcomes($outcomes);
    }

    /** @return list<array{rows: int, header: bool, flow: string|null, predicates: array<string, bool>}> */
    private function reactiveTemplateTableOutcomes(
        ElementNode $template,
        int $cap,
        ?ElementNode $trackedHeader,
    ): array {
        $predicate = 'template:'.$template->index();
        $empty = [
            'rows' => 0,
            'header' => false,
            'flow' => null,
            'predicates' => [$predicate => false],
        ];
        $oneRender = $this->tableOutcomes($template->children(), $cap, $trackedHeader);
        foreach ($oneRender as &$outcome) {
            $outcome['predicates'][$predicate] = true;
        }
        unset($outcome);
        $outcomes = [$empty, ...$oneRender];

        if ($template->hasAttribute('x-for')) {
            foreach ($oneRender as $outcome) {
                $outcomes[] = [
                    'rows' => min($cap, $outcome['rows'] * 2),
                    'header' => $outcome['header'],
                    'flow' => $outcome['flow'],
                    'predicates' => $outcome['predicates'],
                ];
            }
        }

        return $this->uniqueTableOutcomes($outcomes);
    }

    /** @return list<array{rows: int, header: bool, flow: string|null, predicates: array<string, bool>}> */
    private function tableLoopOutcomes(
        DirectiveBlockNode $loop,
        DirectiveNode $start,
        int $cap,
        ?ElementNode $trackedHeader,
    ): array {
        $name = strtolower($loop->nameText());
        $body = $this->tableOutcomes($start->children(), $cap, $trackedHeader);
        $completed = [];
        $hasPositiveContinuation = false;
        $hasHeaderlessPositiveContinuation = false;
        $hasHeaderContinuation = false;

        foreach ($body as $iteration) {
            $state = $iteration;
            $state['flow'] = null;
            $completed[] = $state;

            if ($iteration['flow'] === 'break') {
                continue;
            }

            $hasHeaderContinuation = $hasHeaderContinuation || $iteration['header'];
            if ($iteration['rows'] > 0) {
                $hasPositiveContinuation = true;
                $hasHeaderlessPositiveContinuation = $hasHeaderlessPositiveContinuation || ! $iteration['header'];
                $completed[] = ['rows' => $cap, 'header' => $iteration['header'], 'flow' => null, 'predicates' => $iteration['predicates']];
            }
        }

        if ($hasPositiveContinuation && $hasHeaderContinuation) {
            $completed[] = ['rows' => $cap, 'header' => true, 'flow' => null, 'predicates' => []];
        }

        foreach ($body as $iteration) {
            if ($iteration['flow'] !== 'break' || ! $hasPositiveContinuation) {
                continue;
            }

            if ($iteration['header'] || $hasHeaderContinuation) {
                $completed[] = ['rows' => $cap, 'header' => true, 'flow' => null, 'predicates' => $iteration['predicates']];
            }
            if (! $iteration['header'] && $hasHeaderlessPositiveContinuation) {
                $completed[] = ['rows' => $cap, 'header' => false, 'flow' => null, 'predicates' => $iteration['predicates']];
            }
        }

        if ($name === 'forelse') {
            foreach ($loop->intermediateDirectives() as $branch) {
                if (strtolower($branch->nameText()) === 'empty') {
                    array_push($completed, ...$this->tableOutcomes($branch->children(), $cap, $trackedHeader));
                }
            }
        } else {
            $completed[] = ['rows' => 0, 'header' => false, 'flow' => null, 'predicates' => []];
        }

        return $this->uniqueTableOutcomes($completed);
    }

    /** @return list<array{rows: int, header: bool, flow: string|null, predicates: array<string, bool>}> */
    private function tableSwitchOutcomes(
        DirectiveNode $switch,
        int $cap,
        ?ElementNode $trackedHeader,
    ): array {
        $branches = [];
        $hasDefault = false;

        foreach ($switch->children() as $child) {
            if (! $child instanceof DirectiveNode
                || ! in_array(strtolower($child->nameText()), ['case', 'default'], true)) {
                continue;
            }

            $branches[] = $child;
            $hasDefault = $hasDefault || strtolower($child->nameText()) === 'default';
        }

        $completed = $hasDefault ? [] : [['rows' => 0, 'header' => false, 'flow' => null, 'predicates' => []]];

        foreach (array_keys($branches) as $entry) {
            $states = [['rows' => 0, 'header' => false, 'flow' => null, 'predicates' => []]];

            for ($index = $entry; $index < count($branches); $index++) {
                $branchOutcomes = $this->tableOutcomes($branches[$index]->children(), $cap, $trackedHeader);
                $next = [];

                foreach ($states as $before) {
                    foreach ($branchOutcomes as $branch) {
                        $predicates = $this->mergeTablePredicates($before['predicates'], $branch['predicates']);
                        if ($predicates === null) {
                            continue;
                        }

                        $state = [
                            'rows' => min($cap, $before['rows'] + $branch['rows']),
                            'header' => $before['header'] || $branch['header'],
                            'flow' => $branch['flow'],
                            'predicates' => $predicates,
                        ];

                        if ($state['flow'] === 'break') {
                            $state['flow'] = null;
                            $completed[] = $state;
                        } elseif ($state['flow'] !== null) {
                            $completed[] = $state;
                        } else {
                            $next[] = $state;
                        }
                    }
                }

                $states = $this->uniqueTableOutcomes($next);
                if ($states === []) {
                    break;
                }
            }

            array_push($completed, ...$states);
        }

        return $this->uniqueTableOutcomes($completed);
    }

    /**
     * @param  list<array{rows: int, header: bool, flow: string|null, predicates: array<string, bool>}>  $outcomes
     * @return list<array{rows: int, header: bool, flow: string|null, predicates: array<string, bool>}>
     */
    private function uniqueTableOutcomes(array $outcomes): array
    {
        $unique = [];

        foreach ($outcomes as $outcome) {
            ksort($outcome['predicates']);
            $predicateKey = json_encode($outcome['predicates'], JSON_THROW_ON_ERROR);
            $key = $outcome['rows'].'|'.($outcome['header'] ? '1' : '0').'|'.($outcome['flow'] ?? '').'|'.$predicateKey;
            $unique[$key] = $outcome;

            if (count($unique) > self::MAX_TABLE_OUTCOMES) {
                // Preserve conservative row/header possibilities while
                // bounding independent-predicate state growth.
                $compacted = [];
                foreach ($outcomes as $candidate) {
                    $candidate['predicates'] = [];
                    $compactKey = $candidate['rows'].'|'.($candidate['header'] ? '1' : '0').'|'.($candidate['flow'] ?? '');
                    $compacted[$compactKey] = $candidate;
                }

                return array_values($compacted);
            }
        }

        return array_values($unique);
    }

    /**
     * @param  array<string, bool>  $left
     * @param  array<string, bool>  $right
     * @return array<string, bool>|null
     */
    private function mergeTablePredicates(array $left, array $right): ?array
    {
        foreach ($right as $expression => $when) {
            if (array_key_exists($expression, $left) && $left[$expression] !== $when) {
                return null;
            }
            $left[$expression] = $when;
        }

        return $left;
    }
}
