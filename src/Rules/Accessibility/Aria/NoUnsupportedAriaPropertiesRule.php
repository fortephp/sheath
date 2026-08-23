<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Aria;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoUnsupportedAriaPropertiesRule extends AbstractRule
{
    use ChecksAriaConformance;

    public function getId(): string
    {
        return 'a11y-no-unsupported-aria-properties';
    }

    public function getDescription(): string
    {
        return 'ARIA properties must be supported by an element effective role.';
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
            if ($this->explicitAriaAttributes($element) === []
                || $this->elementHasUnmodelledAttributes($element)
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
                $seen = [];

                foreach ($path as $attribute) {
                    $property = $this->ariaAttributeName($attribute);
                    if ($property === null
                        || isset($seen[$property])
                        || Aria12Data::property($property) === null) {
                        continue;
                    }
                    $seen[$property] = true;

                    if ($role === null) {
                        // Global properties are valid without a role. For a
                        // non-global property on an unmapped native/extension
                        // role, the deterministic layer deliberately stands down.
                        continue;
                    }

                    if (in_array($property, $role['prohibited'], true)) {
                        $message = "ARIA property \"{$property}\" is prohibited on role \"{$effective['role']}\".";
                    } elseif (in_array($property, Aria12Data::GLOBAL_PROPERTIES, true)) {
                        continue;
                    } elseif (! in_array($property, $role['supported'], true)) {
                        $message = "ARIA property \"{$property}\" is not supported by role \"{$effective['role']}\".";
                    } else {
                        continue;
                    }

                    $key = $effective['role']."\0".$property;
                    if (! isset($reported[$key])) {
                        $reported[$key] = true;
                        $context->report($element, $message);
                    }
                }
            }
        });
    }
}
