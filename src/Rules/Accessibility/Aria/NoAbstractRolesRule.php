<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Aria;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\Concerns\ReportsWithFix;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\AttributeSerializer;
use Forte\Support\AttributeQuoting;

/** @internal */
class NoAbstractRolesRule extends AbstractRule
{
    use DetectsOpaqueAttributes;
    use ReportsWithFix;

    /**
     * @var array<string>
     */
    public const ABSTRACT_ROLES = [
        'command',
        'composite',
        'input',
        'landmark',
        'range',
        'roletype',
        'section',
        'sectionhead',
        'select',
        'structure',
        'widget',
        'window',
    ];

    public function getId(): string
    {
        return 'a11y-no-abstract-roles';
    }

    public function getDescription(): string
    {
        return 'Abstract ARIA roles must not be used.';
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
            $roleAttributes = $this->firstAttributesOnRenderPaths($element, 'role');
            if ($roleAttributes === null) {
                return;
            }

            $reported = [];
            foreach ($roleAttributes as $roleAttr) {
                if ($roleAttr === null) {
                    continue;
                }

                if ($roleAttr->isDynamic()) {
                    continue;
                }

                $roles = $roleAttr->tokens();
                $abstractRoles = [];
                $concreteRoles = [];

                foreach ($roles as $role) {
                    $roleLower = strtolower($role);
                    if (in_array($roleLower, self::ABSTRACT_ROLES, true)) {
                        $abstractRoles[] = $roleLower;
                    } else {
                        $concreteRoles[] = $role;
                    }
                }

                foreach ($abstractRoles as $role) {
                    $key = spl_object_id($roleAttr)."\0".$role;
                    if (isset($reported[$key])) {
                        continue;
                    }

                    $reported[$key] = true;
                    $context->report(
                        $element,
                        "Abstract ARIA role \"{$role}\" is not valid on elements.",
                        $this->createRoleRemovalFix($roleAttr, $concreteRoles)
                    );
                }
            }
        });
    }

    /**
     * @param  array<string>  $concreteRoles
     */
    private function createRoleRemovalFix(Attribute $roleAttr, array $concreteRoles): ?Fix
    {
        if (count($concreteRoles) === 0) {
            return $this->createRemoveAttributeFix($roleAttr);
        }

        $quote = AttributeQuoting::styleOf($roleAttr);
        $newValue = AttributeSerializer::render('role', implode(' ', $concreteRoles), $quote);

        return $this->createReplaceAttributeFix($roleAttr, $newValue);
    }
}
