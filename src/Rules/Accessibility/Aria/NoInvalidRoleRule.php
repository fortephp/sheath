<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Aria;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\Concerns\ReportsWithFix;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoInvalidRoleRule extends AbstractRule
{
    use DetectsOpaqueAttributes;
    use ReportsWithFix;

    private const ABSTRACT_ROLES_RULE_ID = 'a11y-no-abstract-roles';

    public function getId(): string
    {
        return 'a11y-no-invalid-role';
    }

    public function getDescription(): string
    {
        return 'ARIA role values must be valid.';
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
        $abstractRolesReportedElsewhere = $this->abstractRolesAreReportedElsewhere($context);

        $context->elements()->each(function (ElementNode $element) use ($context, $abstractRolesReportedElsewhere): void {
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
                $invalidRoles = [];
                $validRoles = [];

                foreach ($roles as $role) {
                    $roleLower = strtolower($role);

                    if (self::isValidRole($roleLower)) {
                        $validRoles[] = $role;

                        continue;
                    }

                    if ($abstractRolesReportedElsewhere
                        && in_array($roleLower, NoAbstractRolesRule::ABSTRACT_ROLES, true)) {
                        continue;
                    }

                    $invalidRoles[] = $roleLower;
                }

                // Unknown tokens are valid forward-compatible fallbacks when
                // the list also contains a concrete role understood today.
                if ($validRoles !== []) {
                    continue;
                }

                foreach ($invalidRoles as $role) {
                    $key = spl_object_id($roleAttr)."\0".$role;
                    if (isset($reported[$key])) {
                        continue;
                    }

                    $reported[$key] = true;
                    $context->report(
                        $element,
                        "Unknown ARIA role \"{$role}\".",
                        $this->createRemoveAttributeFix($roleAttr)
                    );
                }
            }
        });
    }

    private function abstractRolesAreReportedElsewhere(RuleContext $context): bool
    {
        return $this->configuredRuleShouldReport(
            $context,
            self::ABSTRACT_ROLES_RULE_ID,
            (new NoAbstractRolesRule)->getDefaultSeverity(),
        );
    }

    public static function isValidRole(string $role): bool
    {
        /** @var array<string, true>|null $roles */
        static $roles;

        if ($roles === null) {
            $roles = array_fill_keys(AriaData::validRoles(), true);
        }

        return isset($roles[$role]);
    }
}
