<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Php;

use Forte\Ast\Document\Document;
use Forte\Ast\PhpTagNode;
use Forte\Sheath\Contracts\ProvidesCacheContext;
use Forte\Sheath\Parsing\LivewireComponentSource;
use Forte\Sheath\Parsing\VoltComponentSource;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoPhpTagRule extends AbstractRule implements ProvidesCacheContext
{
    public function getId(): string
    {
        return 'blade-no-php-tag';
    }

    public function getDescription(): string
    {
        return 'Prefer @php directive over <?php ?> tags in Blade templates.';
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
        if (! str_contains($document->source(), '<?')) {
            return;
        }

        $isVoltTemplate = VoltComponentSource::isTemplate($document, $context->getFilePath());

        $document->allOfType(PhpTagNode::class, true)->each(
            function (PhpTagNode $phpTag) use ($context, $isVoltTemplate): void {
                if (($isVoltTemplate && $phpTag->isPhpTag())
                    || LivewireComponentSource::isLeadingClassPreamble($phpTag)) {
                    return;
                }

                $context->report(
                    $phpTag,
                    'Raw PHP tag in Blade template.'
                );
            }
        );
    }

    /** @param array<string, mixed> $options */
    public function cacheContext(array $options): array|string
    {
        return [
            'schema' => 1,
            'voltMountedPaths' => VoltComponentSource::mountedPaths() ?? 'unavailable',
        ];
    }
}
