<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Security;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Support\JsonResource;

/** @internal */
class NoInlineJsRule extends AbstractRule
{
    use DetectsOpaqueAttributes;

    protected array $options = [
        'allowed' => [],
    ];

    public function getId(): string
    {
        return 'security-no-inline-js';
    }

    public function getDescription(): string
    {
        return 'Disallow inline JavaScript event handlers for security and maintainability.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::SECURITY;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $allowedHandlers = $this->getOption('allowed', []);
        /** @var array<string> $allowedHandlers */
        $allowedHandlers = is_array($allowedHandlers) ? $allowedHandlers : [];
        $allowedHandlers = array_map(static fn (string $handler): string => strtolower(trim($handler)), $allowedHandlers);
        $source = $document->source();

        $context->elements()
            ->each(function (ElementNode $element) use ($context, $allowedHandlers, $source): void {
                foreach ($this->attributesInRenderStructure($element) as $attr) {
                    $attrName = strtolower($attr->name()->rawName());

                    if (in_array($attrName, $allowedHandlers, true)) {
                        continue;
                    }

                    if (self::isJavaScriptEventHandler($attrName)) {
                        $context->report(
                            $element,
                            "Inline JavaScript handler '{$attrName}' is disallowed.",
                            $attr->isDynamic()
                                ? null
                                : $this->createRemoveAttributeFix($attr, $source)
                        );
                    }
                }
            });
    }

    private static function isJavaScriptEventHandler(string $attribute): bool
    {
        /** @var array<string, true>|null $handlers */
        static $handlers;

        if ($handlers === null) {
            $handlers = array_fill_keys(JsonResource::stringList('html/event-handler-attributes.json'), true);
        }

        return isset($handlers[$attribute]);
    }

    private function createRemoveAttributeFix(Attribute $attr, string $source): ?Fix
    {
        $startOffset = $attr->startOffset();
        $endOffset = $attr->endOffset();

        if ($startOffset < 0) {
            return null;
        }

        return Fix::dangerous(
            $this->findWhitespaceStart($source, $startOffset),
            $endOffset,
            ''
        );
    }
}
