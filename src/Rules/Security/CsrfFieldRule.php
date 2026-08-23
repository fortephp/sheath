<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Security;

use Forte\Ast\DirectiveBlockNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\EchoNode;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\ChecksFormSubmission;
use Forte\Sheath\Rules\Concerns\ChecksRenderPathGuarantees;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueHeadContent;
use Forte\Sheath\Rules\Concerns\ReportsWithFix;
use Forte\Sheath\Rules\Concerns\TraversesRenderedTree;
use Forte\Sheath\Rules\Concerns\ValidatesDocumentStructure;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\ReactiveAttributeSemantics;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Throwable;

/** @internal */
class CsrfFieldRule extends AbstractRule
{
    use ChecksFormSubmission;
    use ChecksRenderPathGuarantees;
    use DetectsOpaqueAttributes;
    use DetectsOpaqueHeadContent;
    use ReportsWithFix;
    use TraversesRenderedTree;
    use ValidatesDocumentStructure;

    private const POST_METHOD = 'post';

    private const TARGET_APPLICATION = 'application';

    private const TARGET_EXTERNAL = 'external';

    private const TARGET_UNKNOWN = 'unknown';

    private const BASE_NOT_FOUND = 'base-not-found';

    protected array $options = [
        'applicationHosts' => [],
    ];

    public function getId(): string
    {
        return 'security-csrf-field';
    }

    public function getDescription(): string
    {
        return 'Forms posting to the application must include @csrf for CSRF protection.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::SECURITY;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $newline = $this->sourceNewline($document->source());
        $forms = iterator_to_array($document->queryElements('form'));
        $controls = $document->queryElements(['button', 'input'])->values()->all();
        $controlsByForm = $this->indexControlsByForm($forms, $controls);
        $tokenInputsByForm = $this->indexControlsByForm(
            $forms,
            iterator_to_array($document->queryElements('input')),
        );
        $documentBaseTarget = $this->documentBaseTarget($document);

        foreach ($forms as $form) {
            $targets = $this->targetAnalysis(
                $form,
                $controlsByForm[$form->index()] ?? [],
                $documentBaseTarget,
            );

            if (array_intersect(
                [self::TARGET_APPLICATION, self::TARGET_UNKNOWN],
                $targets['post']
            ) === []) {
                continue;
            }

            if ($this->formHasCsrf($form, $tokenInputsByForm[$form->index()] ?? [])) {
                continue;
            }

            if ($this->formContentIsOpaque($form)) {
                continue;
            }

            $context->report(
                $form,
                'Application POST form is missing @csrf.',
                array_filter(
                    $targets['all'],
                    static fn (string $target): bool => $target !== self::TARGET_APPLICATION
                ) === []
                    ? $this->createInsertAfterOpeningTagFix($form, "{$newline}    @csrf", dangerous: true)
                    : null
            );
        }
    }

    /**
     * @param  array<int, ElementNode>  $forms
     * @param  array<int, ElementNode>  $controls
     * @return array<int, list<ElementNode>>
     */
    private function indexControlsByForm(array $forms, array $controls): array
    {
        $indexed = [];

        foreach ($controls as $control) {
            if (! $this->nodeCreatesSuccessfulFormControl($control)) {
                continue;
            }

            foreach ($this->possibleControlOwners($control, $forms) as $owner) {
                $indexed[$owner->index()][] = $control;
            }
        }

        return $indexed;
    }

    /**
     * @param  array<int, ElementNode>  $forms
     * @return list<ElementNode>
     */
    private function possibleControlOwners(ElementNode $control, array $forms): array
    {
        if ($this->elementHasUnmodelledAttributes($control)) {
            return array_values(array_filter(
                $forms,
                fn (ElementNode $form): bool => $this->elementsShareTree($control, $form),
            ));
        }

        $paths = $this->explicitAttributeRenderPaths($control, 'form');
        if ($paths === null) {
            return array_values(array_filter(
                $forms,
                fn (ElementNode $form): bool => $this->elementsShareTree($control, $form),
            ));
        }

        $owners = [];
        foreach ($paths as $path) {
            $formAttribute = $this->firstPathAttribute($path, 'form');

            if ($formAttribute === null) {
                $owner = $this->renderedFormOwner($control);
            } elseif ($formAttribute->isDynamic()) {
                foreach ($forms as $form) {
                    if ($this->elementsShareTree($control, $form)) {
                        $owners[$form->index()] = $form;
                    }
                }

                continue;
            } else {
                $ownerId = $formAttribute->decodedValueText() ?? '';
                $candidate = $this->elementByIdInTree($control, $ownerId);
                $owner = $candidate?->isTag('form') === true ? $candidate : null;
            }

            if ($owner !== null) {
                $owners[$owner->index()] = $owner;
            }
        }

        return array_values($owners);
    }

    /**
     * A submit button can override its form owner's method. Missing, empty,
     * and invalid method values use HTML's GET default.
     *
     * @param  array<int, ElementNode>  $controls
     * @return array{post: list<string>, all: list<string>}
     */
    private function targetAnalysis(ElementNode $form, array $controls, string $documentBaseTarget): array
    {
        $postTargets = [];
        $allTargets = [];
        $formPaths = $this->submissionPaths($form, 'method', 'action', $documentBaseTarget);

        if ($formPaths === null) {
            return ['post' => [], 'all' => []];
        }

        foreach ($formPaths as $path) {
            if ($path['intercepted']) {
                continue;
            }

            $this->recordSubmission($path['method'], $path['target'], $postTargets, $allTargets);
        }

        foreach ($controls as $control) {
            if (! $this->isSubmitControl($control)) {
                continue;
            }

            if ($this->elementHasUnmodelledAttributes($control)
                && ($this->firstAttributesOnRenderPaths($control, 'formmethod') === null
                    || $this->firstAttributesOnRenderPaths($control, 'formaction') === null)) {
                continue;
            }

            $controlPaths = $this->explicitAttributeRenderPaths($control, ['formmethod', 'formaction']);
            if ($controlPaths === null) {
                continue;
            }

            foreach ($controlPaths as $controlPath) {
                $methodOverride = $this->firstPathAttribute($controlPath, 'formmethod');
                $targetOverride = $this->firstPathAttribute($controlPath, 'formaction');

                foreach ($formPaths as $formPath) {
                    if ($formPath['intercepted']) {
                        continue;
                    }

                    $method = $methodOverride === null
                        ? $formPath['method']
                        : $this->methodFromAttribute($methodOverride);
                    $target = $targetOverride === null
                        ? $formPath['target']
                        : $this->submissionTargetFromAttribute($targetOverride, $documentBaseTarget);

                    $this->recordSubmission($method, $target, $postTargets, $allTargets);
                }
            }
        }

        return [
            'post' => array_values(array_unique($postTargets)),
            'all' => array_values(array_unique($allTargets)),
        ];
    }

    /**
     * @return list<array{method: string, target: string, intercepted: bool}>|null
     */
    private function submissionPaths(
        ElementNode $element,
        string $methodName,
        string $targetName,
        string $documentBaseTarget,
    ): ?array {
        if ($this->elementHasUnmodelledAttributes($element)
            && ($this->firstAttributesOnRenderPaths($element, $methodName) === null
                || $this->firstAttributesOnRenderPaths($element, $targetName) === null)) {
            return null;
        }

        $submissionDirectiveNames = [];
        foreach ($this->attributesInRenderStructure($element) as $attribute) {
            if (ReactiveAttributeSemantics::isFormSubmissionDirective($attribute)) {
                $submissionDirectiveNames[] = strtolower($attribute->name()->rawName());
            }
        }

        $paths = $this->explicitAttributeRenderPaths($element, [
            $methodName,
            $targetName,
            ...array_values(array_unique($submissionDirectiveNames)),
        ], retainSpecificBindings: true);
        if ($paths === null) {
            return null;
        }

        $submissions = [];
        foreach ($paths as $path) {
            $method = $this->firstPathAttribute($path, $methodName);
            $target = $this->firstPathAttribute($path, $targetName);
            $targetClassification = $this->submissionTargetFromAttribute($target, $documentBaseTarget);
            $intercepted = false;
            foreach ($path as $attribute) {
                if (ReactiveAttributeSemantics::preventsNativeFormSubmission($attribute, $element)) {
                    $intercepted = true;
                    break;
                }
            }
            $key = $this->methodFromAttribute($method).'|'.$targetClassification.'|'.($intercepted ? '1' : '0');
            $submissions[$key] = [
                'method' => $this->methodFromAttribute($method),
                'target' => $targetClassification,
                'intercepted' => $intercepted,
            ];
        }

        return array_values($submissions);
    }

    /**
     * @param  list<string>  $postTargets
     * @param  list<string>  $allTargets
     */
    private function recordSubmission(string $method, string $target, array &$postTargets, array &$allTargets): void
    {
        if ($method === 'dialog') {
            return;
        }

        $allTargets[] = $target;

        if ($method === self::POST_METHOD || $method === self::TARGET_UNKNOWN) {
            $postTargets[] = $target;
        }
    }

    private function methodFromAttribute(?Attribute $attribute): string
    {
        if ($attribute === null) {
            return 'get';
        }

        if ($attribute->isDynamic()
            || ReactiveAttributeSemantics::boundAttributeName($attribute) !== null) {
            return self::TARGET_UNKNOWN;
        }

        $method = strtolower($attribute->decodedValueText() ?? '');

        return in_array($method, ['get', 'post', 'dialog'], true) ? $method : 'get';
    }

    private function submissionTargetFromAttribute(?Attribute $attribute, string $documentBaseTarget): string
    {
        if ($attribute === null) {
            return self::TARGET_APPLICATION;
        }

        if (ReactiveAttributeSemantics::boundAttributeName($attribute) !== null) {
            return self::TARGET_UNKNOWN;
        }

        if (! $attribute->isUnconditionallyPresent()) {
            return self::TARGET_UNKNOWN;
        }

        if ($attribute->hasComplexValue()) {
            $expression = null;

            foreach ($attribute->value()?->parts() ?? [] as $part) {
                if (is_string($part)) {
                    if (trim($part) !== '') {
                        return self::TARGET_UNKNOWN;
                    }

                    continue;
                }

                if (! $part instanceof EchoNode || $expression !== null) {
                    return self::TARGET_UNKNOWN;
                }

                $expression = $part->expression();
            }

            return $expression !== null && $this->isApplicationUrlExpression($expression)
                ? self::TARGET_APPLICATION
                : self::TARGET_UNKNOWN;
        }

        $target = preg_replace(
            '/\A[\x00-\x20]+|[\x00-\x20]+\z/',
            '',
            $attribute->decodedValueText() ?? ''
        ) ?? '';
        if ($target === '') {
            return self::TARGET_APPLICATION;
        }

        return $this->staticUrlTarget($target, $documentBaseTarget);
    }

    private function staticUrlTarget(string $target, string $relativeTarget): string
    {

        $parts = parse_url($target);
        if ($parts === false) {
            return self::TARGET_UNKNOWN;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ($scheme === '' && ! str_starts_with($target, '//')) {
            return $relativeTarget;
        }

        if ($scheme !== '' && ! in_array($scheme, ['http', 'https'], true)) {
            return self::TARGET_EXTERNAL;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return self::TARGET_UNKNOWN;
        }

        return in_array($host, $this->applicationHosts(), true)
            ? self::TARGET_APPLICATION
            : self::TARGET_EXTERNAL;
    }

    private function documentBaseTarget(Document $document): string
    {
        $head = $this->getHeadElement($document);
        if ($head === null) {
            return self::TARGET_APPLICATION;
        }

        $targets = array_map(
            static fn (string $target): string => $target === self::BASE_NOT_FOUND
                ? self::TARGET_APPLICATION
                : $target,
            $this->firstBaseTargetsInSequence($head->children()),
        );
        $targets = array_values(array_unique($targets));

        return count($targets) === 1 ? $targets[0] : self::TARGET_UNKNOWN;
    }

    /**
     * Follow Blade render paths while preserving HTML's first-base-wins rule.
     *
     * @param  iterable<mixed>  $nodes
     * @return list<string>
     */
    private function firstBaseTargetsInSequence(iterable $nodes): array
    {
        $outcomes = [self::BASE_NOT_FOUND];

        foreach ($nodes as $node) {
            if (! in_array(self::BASE_NOT_FOUND, $outcomes, true)) {
                break;
            }

            $nodeOutcomes = $this->firstBaseTargetsFromNode($node);
            $next = [];

            foreach ($outcomes as $outcome) {
                if ($outcome !== self::BASE_NOT_FOUND) {
                    $next[] = $outcome;

                    continue;
                }

                array_push($next, ...$nodeOutcomes);
            }

            $outcomes = array_values(array_unique($next));
        }

        return $outcomes;
    }

    /** @return list<string> */
    private function firstBaseTargetsFromNode(mixed $node): array
    {
        if ($node instanceof ElementNode) {
            if ($node->isTag('base')) {
                return $this->baseElementTargets($node);
            }

            if ($node->isTag('template')) {
                return [self::BASE_NOT_FOUND];
            }

            return $this->firstBaseTargetsInSequence($node->children());
        }

        if (! $node instanceof DirectiveBlockNode) {
            return [self::BASE_NOT_FOUND];
        }

        $start = $node->startDirective();
        if ($start === null) {
            return [self::TARGET_UNKNOWN];
        }

        $name = strtolower($node->nameText());
        $branches = [$start, ...iterator_to_array($node->intermediateDirectives())];
        $outcomes = [];
        $hasFallback = false;

        foreach ($branches as $branch) {
            $branchName = strtolower($branch->nameText());
            $hasFallback = $hasFallback || in_array($branchName, ['else', 'empty', 'default'], true);
            array_push($outcomes, ...$this->firstBaseTargetsInSequence($branch->children()));
        }

        if (in_array($name, ['for', 'foreach', 'while'], true) || ! $hasFallback) {
            $outcomes[] = self::BASE_NOT_FOUND;
        }

        return array_values(array_unique($outcomes));
    }

    /** @return list<string> */
    private function baseElementTargets(ElementNode $base): array
    {
        if ($this->elementHasUnmodelledAttributes($base)
            && $this->firstAttributesOnRenderPaths($base, 'href') === null) {
            return [self::TARGET_UNKNOWN];
        }

        $paths = $this->explicitAttributeRenderPaths($base, 'href');
        if ($paths === null) {
            return [self::TARGET_UNKNOWN];
        }

        $targets = [];
        foreach ($paths as $path) {
            $href = $this->firstPathAttribute($path, 'href');
            if ($href === null) {
                $targets[] = self::BASE_NOT_FOUND;

                continue;
            }

            if ($href->isDynamic() || $href->hasComplexValue()) {
                $targets[] = self::TARGET_UNKNOWN;

                continue;
            }

            $value = preg_replace(
                '/\A[\x00-\x20]+|[\x00-\x20]+\z/',
                '',
                $href->decodedValueText() ?? '',
            ) ?? '';
            $targets[] = $value === ''
                ? self::TARGET_APPLICATION
                : $this->staticUrlTarget($value, self::TARGET_APPLICATION);
        }

        return array_values(array_unique($targets));
    }

    /** @return list<string> */
    private function applicationHosts(): array
    {
        $configured = $this->getOption('applicationHosts', []);
        $hosts = is_array($configured)
            ? array_map(
                static fn (string $host): string => strtolower(trim($host, " \t\n\r\0\x0B.")),
                array_values(array_filter($configured, is_string(...)))
            )
            : [];

        try {
            $container = Container::getInstance();
            if ($container !== null && $container->bound('config')) {
                $config = $container->make('config');
                if ($config instanceof ConfigRepository) {
                    $appUrl = $config->get('app.url');
                    $appHost = is_string($appUrl) ? parse_url($appUrl, PHP_URL_HOST) : null;

                    if (is_string($appHost) && $appHost !== '') {
                        $hosts[] = strtolower($appHost);
                    }
                }
            }
        } catch (Throwable) {
            // A standalone Linter may run without a booted Laravel container.
        }

        return array_values(array_unique(array_filter($hosts)));
    }

    private function isApplicationUrlExpression(string $expression): bool
    {
        $tokens = PhpSource::tokenize($expression);
        if ($tokens === null) {
            return false;
        }

        $tokens = array_values(array_filter(
            $tokens,
            static fn (array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));

        if (! $this->hasApplicationUrlCallShape($tokens)) {
            return false;
        }

        $depth = 0;
        foreach (array_slice($tokens, 1) as $index => $token) {
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
            }

            if ($depth === 0 && $index !== count($tokens) - 2) {
                return false;
            }
        }

        return $depth === 0;
    }

    /** @param list<array{int, string, int}|string> $tokens */
    private function hasApplicationUrlCallShape(array $tokens): bool
    {
        $function = $tokens[0] ?? null;
        if (! is_array($function)
            || ! in_array($function[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }

        $name = strtolower(ltrim($function[1], '\\'));

        return in_array($name, ['action', 'route', 'secure_url', 'url'], true)
            && ($tokens[1] ?? null) === '('
            && end($tokens) === ')';
    }

    private function isSubmitControl(ElementNode $control): bool
    {
        $tagName = strtolower($control->tagNameText());

        if ($this->elementHasUnmodelledAttributes($control)
            && $this->firstAttributesOnRenderPaths($control, 'type') === null) {
            return true;
        }

        $paths = $this->explicitAttributeRenderPaths($control, 'type');
        if ($paths === null) {
            return true;
        }

        if ($tagName === 'button') {
            foreach ($paths as $path) {
                $type = $this->firstPathAttribute($path, 'type');
                if ($type === null || $type->isDynamic()) {
                    return true;
                }

                $value = strtolower($type->decodedValueText() ?? '');
                if ($value === '' || ! in_array($value, ['button', 'reset'], true)) {
                    return true;
                }
            }

            return false;
        }

        if ($tagName !== 'input') {
            return false;
        }

        foreach ($paths as $path) {
            $type = $this->firstPathAttribute($path, 'type');
            if ($type !== null && ($type->isDynamic() || in_array(
                strtolower($type->decodedValueText() ?? ''),
                ['submit', 'image'],
                true,
            ))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<Attribute> $path */
    private function firstPathAttribute(array $path, string $name): ?Attribute
    {
        foreach ($path as $attribute) {
            if ($this->attributeMatchesName($attribute, $name)) {
                return $attribute;
            }
        }

        return null;
    }

    private function formContentIsOpaque(ElementNode $form): bool
    {
        if ($this->elementContainsOpaqueContent($form)) {
            return true;
        }

        foreach ($form->descendants() as $descendant) {
            if ($descendant instanceof EchoNode && trim($descendant->expression()) === '$slot') {
                return true;
            }
        }

        return false;
    }

    /** @param list<ElementNode> $associatedInputs */
    private function formHasCsrf(ElementNode $form, array $associatedInputs): bool
    {
        if ($this->everyRenderPathContains(
            $form->children(),
            fn (mixed $node): bool => $node instanceof Node && $this->isCsrfField($node, $form),
            fn (Node $node): bool => ! $node instanceof ElementNode || ! $node->isTag('template'),
        )) {
            return true;
        }

        foreach ($associatedInputs as $input) {
            if (! $form->contains($input)
                && $this->externalControlIsGuaranteed($input)
                && $this->isCsrfTokenInput($input, $form)) {
                return true;
            }
        }

        return false;
    }

    private function externalControlIsGuaranteed(ElementNode $control): bool
    {
        foreach ($control->ancestors() as $ancestor) {
            if ($ancestor instanceof DirectiveBlockNode) {
                return false;
            }

            if ($ancestor instanceof ElementNode
                && ReactiveAttributeSemantics::isLocalTemplateRenderer($ancestor)) {
                return false;
            }
        }

        return true;
    }

    private function isCsrfField(Node $node, ElementNode $form): bool
    {
        if (! $this->nodeGuaranteesSuccessfulFormControl($node)) {
            return false;
        }

        if ($node instanceof DirectiveNode) {
            return strtolower($node->nameText()) === 'csrf';
        }

        if ($node instanceof EchoNode) {
            return $this->callsCsrfField($node->expression());
        }

        return $node instanceof ElementNode
            && $node->isTag('input')
            && $this->isCsrfTokenInput($node, $form);
    }

    private function callsCsrfField(string $expression): bool
    {
        return $this->isExactZeroArgumentGlobalCall($expression, 'csrf_field');
    }

    private function isCsrfTokenInput(ElementNode $input, ElementNode $form): bool
    {
        if (! $this->nodeGuaranteesSuccessfulFormControl($input)
            || ! $this->inputCanSubmitTokenValue($input)
            || ! $this->controlIsOwnedByForm($input, $form)) {
            return false;
        }

        if ($input->staticAttributeValue('name') !== '_token') {
            return false;
        }

        $value = $input->attribute('value')?->value();
        if ($value === null) {
            return false;
        }

        $hasCsrfToken = false;

        foreach ($value->parts() as $part) {
            if (is_string($part)) {
                if (trim($part) !== '') {
                    return false;
                }

                continue;
            }

            if (! $part instanceof EchoNode
                || $hasCsrfToken
                || ! $this->isCsrfTokenExpression($part->expression())) {
                return false;
            }

            $hasCsrfToken = true;
        }

        return $hasCsrfToken;
    }

    private function controlIsOwnedByForm(ElementNode $control, ElementNode $form): bool
    {
        if ($this->elementHasUnmodelledAttributes($control)) {
            return false;
        }

        $paths = $this->explicitAttributeRenderPaths($control, 'form');
        if ($paths === null || $paths === []) {
            return false;
        }

        foreach ($paths as $path) {
            $formAttribute = $this->firstPathAttribute($path, 'form');
            if ($formAttribute === null) {
                if ($this->renderedFormOwner($control) !== $form) {
                    return false;
                }

                continue;
            }

            if ($formAttribute->isDynamic()) {
                return false;
            }

            $ownerId = $formAttribute->decodedValueText() ?? '';
            if ($this->elementByIdInTree($control, $ownerId) !== $form) {
                return false;
            }
        }

        return true;
    }

    private function renderedFormOwner(ElementNode $control): ?ElementNode
    {
        $owner = $this->renderedParentElement($control);

        while ($owner !== null && ! $owner->isTag('form')) {
            $owner = $this->renderedParentElement($owner);
        }

        return $owner;
    }

    private function isCsrfTokenExpression(string $expression): bool
    {
        return $this->isExactZeroArgumentGlobalCall($expression, 'csrf_token');
    }

    private function isExactZeroArgumentGlobalCall(string $expression, string $function): bool
    {
        $tokens = PhpSource::tokenize($expression);
        if ($tokens === null) {
            return false;
        }

        $tokens = array_values(array_filter(
            $tokens,
            static fn (array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
        ));

        if (count($tokens) !== 3 || $tokens[1] !== '(' || $tokens[2] !== ')' || ! is_array($tokens[0])) {
            return false;
        }

        [$type, $name] = $tokens[0];

        return in_array($type, [T_STRING, T_NAME_FULLY_QUALIFIED], true)
            && strtolower(ltrim($name, '\\')) === $function;
    }

    private function nodeGuaranteesSuccessfulFormControl(Node $node): bool
    {
        if (! $this->nodeCreatesSuccessfulFormControl($node)) {
            return false;
        }

        if ($node instanceof ElementNode && ! $this->elementIsGuaranteedEnabled($node)) {
            return false;
        }

        foreach ($node->ancestors() as $ancestor) {
            if (! $ancestor instanceof ElementNode
                || ! $ancestor->isTag('fieldset')
                || $this->isInsideFirstLegend($ancestor, $node)) {
                continue;
            }

            if (! $this->elementIsGuaranteedEnabled($ancestor)) {
                return false;
            }
        }

        return true;
    }

    private function elementIsGuaranteedEnabled(ElementNode $element): bool
    {
        if ($this->elementHasUnmodelledAttributes($element)) {
            return false;
        }

        foreach ($element->attributes() as $attribute) {
            if (! $attribute->isBladeConstruct()) {
                continue;
            }

            $construct = $attribute->getBladeConstruct();
            if ($construct instanceof DirectiveNode
                && strtolower($construct->nameText()) === 'disabled') {
                return false;
            }
        }

        $paths = $this->explicitAttributeRenderPaths($element, 'disabled');
        if ($paths === null || $paths === []) {
            return false;
        }

        foreach ($paths as $path) {
            if ($this->firstPathAttribute($path, 'disabled') !== null) {
                return false;
            }
        }

        return true;
    }

    private function isInsideFirstLegend(ElementNode $fieldset, Node $node): bool
    {
        foreach ($fieldset->children() as $child) {
            if ($child instanceof ElementNode && $child->isTag('legend')) {
                return $child->contains($node);
            }
        }

        return false;
    }

    private function inputCanSubmitTokenValue(ElementNode $input): bool
    {
        if ($this->elementHasUnmodelledAttributes($input)) {
            return false;
        }

        $paths = $this->explicitAttributeRenderPaths($input, 'type');
        if ($paths === null || $paths === []) {
            return false;
        }

        foreach ($paths as $path) {
            $type = $this->firstPathAttribute($path, 'type');
            if ($type === null) {
                continue;
            }

            if ($type->isDynamic()) {
                return false;
            }

            if (in_array(strtolower($type->decodedValueText() ?? ''), [
                'button',
                'checkbox',
                'color',
                'date',
                'datetime-local',
                'file',
                'image',
                'month',
                'number',
                'radio',
                'range',
                'reset',
                'submit',
                'time',
                'week',
            ], true)) {
                return false;
            }
        }

        return true;
    }
}
