<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Content;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Accessibility\Aria\Aria12Data;
use Forte\Sheath\Rules\Accessibility\Aria\NoInvalidRoleRule;
use Forte\Sheath\Rules\Concerns\ChecksAccessibility;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Support\HtmlInteger;

/** @internal */
class ImgAltTextRule extends AbstractRule
{
    use ChecksAccessibility;

    protected array $options = [
        'requireNonEmpty' => false,
    ];

    public function getId(): string
    {
        return 'a11y-alt-text';
    }

    public function getDescription(): string
    {
        return 'Images must have an alt attribute for accessibility.';
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
        $requireNonEmpty = (bool) $this->getOption('requireNonEmpty', false);

        $document->queryElements('img')
            ->each(function (ElementNode $img) use ($context, $requireNonEmpty): void {
                if ($this->elementHasUnmodelledAttributes($img)
                    || $this->isUnconditionallyExcludedFromAccessibilityTree($img)) {
                    return;
                }

                $paths = $this->explicitAttributeRenderPaths($img, [
                    'alt', 'role', 'aria-label', 'aria-labelledby', 'title',
                    'hidden', 'inert', 'aria-hidden', 'tabindex', 'contenteditable',
                    ...Aria12Data::GLOBAL_PROPERTIES,
                ]);
                if ($paths === null) {
                    return;
                }

                foreach ($paths as $path) {
                    if ($this->accessibilityAttributePathIsExcluded($path)) {
                        continue;
                    }

                    $altAttribute = $this->firstAccessibilityAttributeOnPath($path, 'alt');
                    if ($altAttribute === null) {
                        $context->report(
                            $img,
                            'Image is missing an alt attribute.'
                        );

                        return;
                    }

                    $role = $this->effectiveRoleOnPath($path);
                    $semanticImageNeedsName = in_array($role, ['img', 'image'], true);
                    if (($requireNonEmpty || $semanticImageNeedsName)
                        && ! $this->attributeMayHaveNonEmptyValue($altAttribute)
                        && ! $this->attributePathProvidesAccessibleName(
                            $img,
                            $path,
                            // HTML-AAM consults title only when alt is absent.
                            // This branch has a present but empty alt, so only
                            // higher-precedence ARIA naming can restore a name.
                            ['aria-label', 'aria-labelledby'],
                        )) {
                        $context->report(
                            $img,
                            $semanticImageNeedsName
                                ? 'Semantic image has no accessible name.'
                                : 'Image has an empty alt attribute.'
                        );

                        return;
                    }
                }
            });
    }

    /** @param list<Attribute> $path */
    private function effectiveRoleOnPath(array $path): ?string
    {
        $attribute = $this->firstAccessibilityAttributeOnPath($path, 'role');
        if ($attribute === null) {
            $alt = $this->firstAccessibilityAttributeOnPath($path, 'alt');
            if ($alt === null || $alt->isDynamic()) {
                return null;
            }
            if (($alt->decodedValueText() ?? '') !== '') {
                return 'img';
            }

            $conflict = $this->presentationRoleConflictsOnPath($path);

            return match ($conflict) {
                true => 'img',
                false => 'none',
                null => null,
            };
        }

        if ($attribute->isDynamic()) {
            return null;
        }

        foreach ($attribute->tokensLower() as $role) {
            if (NoInvalidRoleRule::isValidRole($role)) {
                if (in_array($role, ['none', 'presentation'], true)) {
                    $conflict = $this->presentationRoleConflictsOnPath($path);

                    return match ($conflict) {
                        true => 'img',
                        false => $role,
                        null => null,
                    };
                }

                return $role;
            }
        }

        return null;
    }

    /** @param list<Attribute> $path */
    private function presentationRoleConflictsOnPath(array $path): ?bool
    {
        $tabindex = $this->firstAccessibilityAttributeOnPath($path, 'tabindex');
        if ($tabindex !== null) {
            if ($tabindex->isDynamic()) {
                return null;
            }
            if (HtmlInteger::parse($tabindex->decodedValueText() ?? '') !== null) {
                return true;
            }
        }

        $contenteditable = $this->firstAccessibilityAttributeOnPath($path, 'contenteditable');
        if ($contenteditable !== null) {
            if ($contenteditable->isDynamic()) {
                return null;
            }
            if (in_array(strtolower($contenteditable->decodedValueText() ?? ''), ['', 'true', 'plaintext-only'], true)) {
                return true;
            }
        }

        foreach (Aria12Data::GLOBAL_PROPERTIES as $property) {
            if ($this->firstAccessibilityAttributeOnPath($path, $property) !== null) {
                return true;
            }
        }

        return false;
    }
}
