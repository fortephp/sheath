<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Aria;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Support\HtmlWhitespace;

/** @internal */
class RequiredAriaPropertiesRule extends AbstractRule
{
    use ChecksAriaConformance;

    /** Native elements whose implicit ARIA roles can require properties. */
    private const IMPLICIT_REQUIRED_PROPERTY_TAGS = [
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'input', 'meter', 'option', 'select',
    ];

    public function getId(): string
    {
        return 'a11y-required-aria-properties';
    }

    public function getDescription(): string
    {
        return 'ARIA roles must provide their required states and properties.';
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
        $context->elements()->each(function (ElementNode $element) use ($context): void {
            if (! $element->isTag(self::IMPLICIT_REQUIRED_PROPERTY_TAGS)
                && $this->attributesInRenderStructure($element, 'role') === []) {
                return;
            }

            if ($this->elementHasUnmodelledAttributes($element)
                || $this->isUnconditionallyExcludedFromAccessibilityTree($element)
                || $this->ariaRoleApplicabilityIsAmbiguous($element)) {
                return;
            }

            $paths = $this->explicitAttributeRenderPaths(
                $element,
                AriaRoleResolver::consumedAttributeNames(),
            );
            if ($paths === null) {
                return;
            }

            $reported = [];
            foreach ($paths as $path) {
                if ($this->accessibilityAttributePathIsExcluded($path)) {
                    continue;
                }

                $effective = AriaRoleResolver::effectiveRole($element, $path);
                $role = $effective === null ? null : Aria12Data::role($effective['role']);
                if ($effective === null || $role === null) {
                    continue;
                }

                foreach ($role['required'] as $property) {
                    if (AriaRoleResolver::nativeSemanticsSatisfy(
                        $element,
                        $path,
                        $effective['role'],
                        $property,
                        $effective['implicit'],
                    ) || $this->pathProvidesRequiredProperty($path, $property)) {
                        continue;
                    }

                    $key = $effective['role']."\0".$property;
                    if (! isset($reported[$key])) {
                        $reported[$key] = true;
                        $context->report(
                            $element,
                            "Role \"{$effective['role']}\" requires the {$property} property."
                        );
                    }
                }

                $conditional = Aria12Data::CONDITIONAL_REQUIRED_PROPERTIES[$effective['role']] ?? [];
                if (in_array('aria-valuenow', $conditional['focusable'] ?? [], true)
                    && AriaRoleResolver::separatorIsFocusable($element, $path) === true
                    && ! $this->pathProvidesRequiredProperty($path, 'aria-valuenow')) {
                    $key = $effective['role']."\0aria-valuenow";
                    if (! isset($reported[$key])) {
                        $reported[$key] = true;
                        $context->report(
                            $element,
                            'Focusable role "separator" requires the aria-valuenow property.'
                        );
                    }
                }
            }
        });
    }

    /** @param list<Attribute> $path */
    private function pathProvidesRequiredProperty(array $path, string $property): bool
    {
        $attribute = $this->firstAriaAttributeOnPath($path, $property);
        if ($attribute === null) {
            return false;
        }

        return $attribute->isDynamic()
            || HtmlWhitespace::trim($attribute->decodedValueText() ?? '') !== '';
    }
}
