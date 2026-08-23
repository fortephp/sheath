<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Concerns;

use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;

/** @internal */
trait RecognizesCharsetDeclarations
{
    use DetectsOpaqueAttributes;

    protected function charsetDeclarationCandidateStatus(ElementNode $meta): ?bool
    {
        return $this->charsetDeclarationRenderPathStatus(
            static function (?Attribute $charset, ?Attribute $httpEquiv, ?Attribute $content): ?bool {
                if ($charset !== null) {
                    return $charset->isDynamic() ? null : true;
                }

                if ($httpEquiv?->isDynamic() || $content?->isDynamic()) {
                    return null;
                }

                if (strcasecmp($httpEquiv?->decodedValueText() ?? '', 'content-type') !== 0) {
                    return false;
                }

                return preg_match(
                    '/(?:^|;)[\x09\x0A\x0C\x0D\x20]*charset[\x09\x0A\x0C\x0D\x20]*=/i',
                    $content?->decodedValueText() ?? '',
                ) === 1;
            },
            $meta,
        );
    }

    protected function charsetDeclarationStatus(ElementNode $meta): ?bool
    {
        return $this->charsetDeclarationRenderPathStatus(
            static function (?Attribute $charset, ?Attribute $httpEquiv, ?Attribute $content): ?bool {
                if ($charset !== null) {
                    if ($charset->isDynamic()) {
                        return null;
                    }

                    return strcasecmp($charset->decodedValueText() ?? '', 'utf-8') === 0;
                }

                if ($httpEquiv?->isDynamic() || $content?->isDynamic()) {
                    return null;
                }

                if (strcasecmp($httpEquiv?->decodedValueText() ?? '', 'content-type') !== 0) {
                    return false;
                }

                return preg_match(
                    '/\Atext\/html;[\x09\x0A\x0C\x0D\x20]*charset=utf-8\z/i',
                    $content?->decodedValueText() ?? '',
                ) === 1;
            },
            $meta,
        );
    }

    /**
     * Return a definite verdict only when every correlated opening-tag path
     * agrees. Mixed or runtime-produced declarations remain indeterminate.
     *
     * @param  callable(?Attribute, ?Attribute, ?Attribute): ?bool  $verdict
     */
    private function charsetDeclarationRenderPathStatus(callable $verdict, ElementNode $meta): ?bool
    {
        $names = ['charset', 'http-equiv', 'content'];

        if ($this->elementHasUnmodelledAttributes($meta)
            || $this->attributeRenderPathsNeedIndependentConditionCorrelation($meta, $names)) {
            return null;
        }

        $paths = $this->explicitAttributeRenderPaths($meta, $names);
        if ($paths === null || $paths === []) {
            return null;
        }

        $result = null;
        $initialized = false;

        foreach ($paths as $path) {
            $pathResult = $verdict(
                $this->firstAttributeOnRenderPath($path, 'charset'),
                $this->firstAttributeOnRenderPath($path, 'http-equiv'),
                $this->firstAttributeOnRenderPath($path, 'content'),
            );

            if ($pathResult === null) {
                return null;
            }

            if (! $initialized) {
                $result = $pathResult;
                $initialized = true;

                continue;
            }

            if ($pathResult !== $result) {
                return null;
            }
        }

        return $result;
    }
}
