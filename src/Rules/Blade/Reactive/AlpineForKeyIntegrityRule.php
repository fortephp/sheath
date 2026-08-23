<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Reactive;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\AlpineForExpression;
use Forte\Sheath\Support\ReactiveAttributeSemantics;
use Forte\Sheath\Support\StaticClientExpression;

/** @internal */
final class AlpineForKeyIntegrityRule extends AbstractReactiveRule
{
    public function getId(): string
    {
        return 'blade-alpine-for-key-integrity';
    }

    public function getDescription(): string
    {
        return 'Alpine x-for templates require a unique bound key.';
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
        $document->queryElements('template')->each(function (ElementNode $template) use ($document, $context): void {
            $paths = $this->explicitAttributeRenderPaths($template);
            if ($paths === null) {
                return;
            }

            foreach ($paths as $path) {
                $path = array_values(array_filter(
                    $path,
                    static fn (Attribute $attribute): bool => ! ReactiveAttributeSemantics::isClientDirective($attribute)
                        || ReactiveAttributeSemantics::clientDirectiveRuns($attribute, $template),
                ));
                $for = $this->firstDirective($path, 'x-for');
                if ($for === null) {
                    continue;
                }

                $cannotRepeat = ! $for->hasComplexValue()
                    && AlpineForExpression::collectionCanContainAtMostOneItem($for->decodedValueText() ?? '');

                foreach ($path as $attribute) {
                    $rawName = strtolower($attribute->name()->rawName());
                    if ($rawName === 'key') {
                        $this->reportAttribute(
                            $document,
                            $attribute,
                            $context,
                            'Alpine x-for requires a bound :key.',
                        );

                        return;
                    }

                    if (! in_array($rawName, [':key', 'x-bind:key'], true)
                        || $attribute->hasComplexValue()) {
                        continue;
                    }

                    $expression = $attribute->decodedValueText() ?? '';
                    if (StaticClientExpression::isObjectValue($expression)) {
                        $this->reportAttribute(
                            $document,
                            $attribute,
                            $context,
                            'Invalid Alpine x-for key type; expected a string or integer.',
                        );

                        return;
                    }

                    if (! StaticClientExpression::isConstant($expression)) {
                        continue;
                    }

                    if ($cannotRepeat) {
                        continue;
                    }

                    $this->reportAttribute(
                        $document,
                        $attribute,
                        $context,
                        'Alpine x-for key is static across iterations.',
                    );

                    return;
                }
            }
        });
    }
}
