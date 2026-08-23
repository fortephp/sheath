<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Security;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsExclusiveBranches;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\Concerns\ReportsWithFix;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\ReactiveAttributeSemantics;
use Forte\Support\AttributeQuoting;
use Forte\Support\HtmlWhitespace;

/** @internal */
class NoTargetBlankRule extends AbstractRule
{
    use DetectsExclusiveBranches;
    use DetectsOpaqueAttributes;
    use ReportsWithFix;

    protected array $options = [
        'requireExplicitNoopener' => false,
    ];

    public function getId(): string
    {
        return 'security-no-target-blank';
    }

    public function getDescription(): string
    {
        return 'Prevent target="_blank" links from retaining access to window.opener.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::SECURITY;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $bases = $document->queryElements('base')->values()->all();

        foreach (['a', 'area'] as $tagName) {
            $document->queryElements($tagName)
                ->each(function (ElementNode $link) use ($bases, $context): void {
                    $this->checkLink($link, $this->possibleBaseTargets($link, $bases), $context);
                });
        }
    }

    /**
     * @param  array<int, ElementNode>  $bases
     * @return list<Attribute>
     */
    private function possibleBaseTargets(ElementNode $link, array $bases): array
    {
        $targets = [];

        foreach ($bases as $base) {
            if (! $this->elementsShareTree($base, $link)
                || $this->nodesAreMutuallyExclusive($base, $link)
                || ($this->isInsideNonOutputCapture($base) && ! $this->nodeRendersWhenever($base, $link))) {
                continue;
            }

            if ($this->elementHasUnmodelledAttributes($base)
                && $this->firstAttributesOnRenderPaths($base, 'target') === null) {
                continue;
            }

            $paths = $this->explicitAttributeRenderPaths($base, 'target');
            if ($paths === null) {
                continue;
            }

            $targetIsGuaranteed = $paths !== [];
            foreach ($paths as $path) {
                $target = $this->firstPathAttribute($path, 'target');
                if ($target === null) {
                    $targetIsGuaranteed = false;

                    continue;
                }

                $targets[spl_object_id($target)] = $target;
            }

            if ($targetIsGuaranteed && $this->nodeRendersWhenever($base, $link)) {
                break;
            }
        }

        return array_values($targets);
    }

    /** @param list<Attribute> $baseTargets */
    private function checkLink(ElementNode $link, array $baseTargets, RuleContext $context): void
    {
        if ($this->elementHasUnmodelledAttributes($link)
            && ($this->firstAttributesOnRenderPaths($link, 'target') === null
                || $this->firstAttributesOnRenderPaths($link, 'rel') === null)) {
            return;
        }

        $paths = $this->explicitAttributeRenderPaths($link, ['target', 'rel'], true);
        if ($paths === null) {
            return;
        }

        $requireExplicitNoopener = (bool) $this->getOption('requireExplicitNoopener', false);
        $legacyViolation = false;

        foreach ($paths as $path) {
            $target = $this->firstPathAttribute($path, 'target');
            $effectiveTargets = $target === null ? $baseTargets : [$target];

            $rel = $this->firstPathAttribute($path, 'rel');
            foreach ($effectiveTargets as $effectiveTarget) {
                if (ReactiveAttributeSemantics::valueIsDynamic($effectiveTarget)
                    || ! $this->targetsBlankBrowsingContext($effectiveTarget->decodedValueText() ?? '')) {
                    continue;
                }

                if ($rel === null || ReactiveAttributeSemantics::valueIsDynamic($rel)) {
                    $legacyViolation = $legacyViolation || $requireExplicitNoopener;

                    continue;
                }

                $relValues = $rel->tokensLower();
                $hasNoopener = in_array('noreferrer', $relValues, true)
                    || in_array('noopener', $relValues, true);

                if (! $hasNoopener && in_array('opener', $relValues, true)) {
                    $context->report(
                        $link,
                        'target="_blank" link enables window.opener.',
                        $link->hasAttribute('rel') ? $this->createAppendRelFix($rel) : null
                    );

                    return;
                }

                $legacyViolation = $legacyViolation || ($requireExplicitNoopener && ! $hasNoopener);
            }
        }

        if (! $legacyViolation) {
            return;
        }

        $relAttributes = $this->attributesInRenderStructure($link, 'rel');
        $context->report(
            $link,
            'target="_blank" link is missing rel="noopener".',
            $relAttributes === []
                ? $this->createAddAttributeFix($link, 'rel', 'noopener')
                : (count($relAttributes) === 1 && $link->hasAttribute('rel')
                    ? $this->createAppendRelFix($relAttributes[0])
                    : null)
        );
    }

    /**
     * @param  list<Attribute>  $path
     */
    private function firstPathAttribute(array $path, string $name): ?Attribute
    {
        foreach ($path as $attribute) {
            if ($this->attributeMatchesName($attribute, $name)) {
                return $attribute;
            }
        }

        return null;
    }

    private function targetsBlankBrowsingContext(string $target): bool
    {
        return strcasecmp($target, '_blank') === 0
            || (str_contains($target, '<') && strpbrk($target, "\x09\x0A\x0D") !== false);
    }

    private function createAppendRelFix(Attribute $relAttr): ?Fix
    {
        if (! $relAttr->isUnconditionallyPresent()) {
            return null;
        }

        $existing = HtmlWhitespace::trimEnd($relAttr->valueText() ?? '');
        $value = $existing === '' ? 'noopener' : $existing.' noopener';

        $quote = AttributeQuoting::styleOf($relAttr);
        if (str_contains($value, $quote)) {
            return null;
        }

        return $this->createReplaceAttributeFix(
            $relAttr,
            AttributeQuoting::render($relAttr->nameText(), $value, $quote)
        );
    }
}
