<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\BestPractices\Attributes;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\ReactiveAttributeSemantics;

/** @internal */
class RequireFormMethodRule extends AbstractRule
{
    use DetectsOpaqueAttributes;

    public function getId(): string
    {
        return 'best-practices-require-form-method';
    }

    public function getDescription(): string
    {
        return 'Form elements should have an explicit method attribute.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BEST_PRACTICES;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $document->queryElements('form')->each(function (ElementNode $form) use ($context): void {
            if (! $this->everyFormPathHasSubmissionContract($form)) {
                $context->report(
                    $form,
                    'Form is missing an explicit method attribute.'
                );

                return;
            }

            $methodAttributes = $this->firstAttributesOnRenderPaths($form, 'method');
            if ($methodAttributes === null) {
                return;
            }

            $seen = [];
            foreach ($methodAttributes as $methodAttribute) {
                if ($methodAttribute === null || isset($seen[spl_object_id($methodAttribute)])) {
                    continue;
                }
                $seen[spl_object_id($methodAttribute)] = true;

                if ($methodAttribute->isDynamic()) {
                    continue;
                }

                $method = strtolower($methodAttribute->decodedValueText() ?? '');
                if (! in_array($method, ['get', 'post', 'dialog'], true)) {
                    $context->report($form, 'Invalid form method; expected GET, POST, or dialog.');

                    return;
                }
            }
        });
    }

    private function everyFormPathHasSubmissionContract(ElementNode $form): bool
    {
        $submissionDirectiveNames = [];
        foreach ($this->attributesInRenderStructure($form) as $attribute) {
            if (ReactiveAttributeSemantics::isFormSubmissionDirective($attribute)) {
                $submissionDirectiveNames[] = strtolower($attribute->name()->rawName());
            }
        }

        $paths = $this->elementHasUnmodelledAttributes($form)
            ? null
            : $this->explicitAttributeRenderPaths(
                $form,
                ['method', ...array_values(array_unique($submissionDirectiveNames))],
            );

        if ($paths !== null) {
            foreach ($paths as $path) {
                $satisfied = false;
                foreach ($path as $attribute) {
                    if ($this->attributeMatchesName($attribute, 'method')
                        || ReactiveAttributeSemantics::preventsNativeFormSubmission($attribute, $form)) {
                        $satisfied = true;
                        break;
                    }
                }

                if (! $satisfied) {
                    return false;
                }
            }

            return true;
        }

        return $this->everyRenderPathContains(
            $this->attributeRenderItems($form),
            function (mixed $item) use ($form): bool {
                if ($item instanceof Attribute) {
                    return (ReactiveAttributeSemantics::clientDirectiveRuns($item, $form)
                            && ReactiveAttributeSemantics::isOpaqueAttributeSet($item))
                        || $this->attributeMatchesName($item, 'method', $form)
                        || ReactiveAttributeSemantics::preventsNativeFormSubmission($item, $form);
                }

                return $this->isOpaqueAttributeProvider($item, $form);
            },
        );
    }
}
