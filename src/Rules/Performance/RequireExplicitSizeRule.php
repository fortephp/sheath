<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Performance;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Support\HtmlInteger;
use Forte\Support\ImageSource;

/** @internal */
class RequireExplicitSizeRule extends AbstractRule
{
    use DetectsOpaqueAttributes;

    public function getId(): string
    {
        return 'perf-require-explicit-size';
    }

    public function getDescription(): string
    {
        return 'Images should have explicit width and height attributes to prevent layout shifts.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::PERFORMANCE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $document->queryElements('img')->each(function (ElementNode $img) use ($context): void {
            if ($this->unmodelledAttributesAffectDecision($img)) {
                return;
            }

            $paths = $this->explicitAttributeRenderPaths($img, ['src', 'width', 'height']);
            if ($paths === null) {
                return;
            }

            $missingWidth = false;
            $missingHeight = false;
            $invalidDimension = null;

            foreach ($paths as $path) {
                $src = $this->firstAttributeOnRenderPath($path, 'src');
                if ($src !== null && $src->isDynamic()) {
                    continue;
                }
                if ($src !== null && ImageSource::isSvg($src->decodedValueText() ?? '')) {
                    continue;
                }

                foreach (['width', 'height'] as $dimension) {
                    $attribute = $this->firstAttributeOnRenderPath($path, $dimension);
                    if ($attribute === null) {
                        if ($dimension === 'width') {
                            $missingWidth = true;
                        } else {
                            $missingHeight = true;
                        }

                        continue;
                    }
                    if ($attribute->isDynamic()) {
                        continue;
                    }

                    $value = HtmlInteger::parse($attribute->decodedValueText() ?? '');
                    if ($value === null || $value < 0) {
                        $invalidDimension ??= $dimension;
                    }
                }
            }

            if ($missingWidth && $missingHeight) {
                $context->report(
                    $img,
                    'Image is missing explicit width and height attributes.'
                );

                return;
            }

            if ($missingWidth) {
                $context->report(
                    $img,
                    'Image is missing a width attribute.'
                );

                return;
            }

            if ($missingHeight) {
                $context->report(
                    $img,
                    'Image is missing a height attribute.'
                );

                return;
            }

            if ($invalidDimension !== null) {
                $context->report(
                    $img,
                    "Invalid image {$invalidDimension}; expected a non-negative integer."
                );
            }
        });
    }

    private function unmodelledAttributesAffectDecision(ElementNode $image): bool
    {
        if (! $this->elementHasUnmodelledAttributes($image)) {
            return false;
        }

        foreach (['src', 'width', 'height'] as $attribute) {
            if ($this->firstAttributesOnRenderPaths($image, $attribute) === null) {
                return true;
            }
        }

        return false;
    }
}
