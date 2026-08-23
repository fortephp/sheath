<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Accessibility\Structure;

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
use Forte\Support\AttributeQuoting;
use Forte\Support\LanguageTag;

/** @internal */
class HtmlLangRule extends AbstractRule
{
    protected array $options = [
        'default' => 'en',
    ];

    use DetectsOpaqueAttributes;
    use ReportsWithFix;

    public function getId(): string
    {
        return 'a11y-html-lang';
    }

    public function getDescription(): string
    {
        return 'The <html> element must have a lang attribute for accessibility.';
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
        $defaultLangOption = $this->getOption('default', 'en');
        $hasUsableConfiguredDefault = array_key_exists('default', $this->getConfiguredOptions())
            && is_string($defaultLangOption)
            && LanguageTag::isWellFormed($defaultLangOption);
        $defaultLang = $hasUsableConfiguredDefault ? $defaultLangOption : 'en';
        $dangerous = ! $hasUsableConfiguredDefault;

        $document->queryElements('html')
            ->each(function (ElementNode $html) use ($context, $defaultLang, $dangerous): void {
                $langAttributes = $this->firstAttributesOnRenderPaths($html, 'lang');
                if ($langAttributes === null) {
                    return;
                }

                foreach ($langAttributes as $langAttribute) {
                    if ($langAttribute !== null) {
                        if ($this->checkStaticLang($html, $langAttribute, $context, $defaultLang, $dangerous)) {
                            return;
                        }

                        continue;
                    }

                    $context->report(
                        $html,
                        '<html> is missing a lang attribute.',

                        $this->createAddAttributeFix($html, 'lang', $defaultLang, $dangerous)
                    );

                    return;
                }
            });
    }

    private function checkStaticLang(
        ElementNode $html,
        Attribute $langAttribute,
        RuleContext $context,
        string $defaultLang,
        bool $dangerous,
    ): bool {
        if ($langAttribute->isDynamic()) {
            return false;
        }

        $lang = $langAttribute->decodedValueText();

        if ($lang !== null && LanguageTag::isWellFormed($lang)) {
            return false;
        }

        $message = $lang === null || trim($lang) === ''
            ? 'The lang attribute on <html> is empty.'
            : "The lang attribute '{$lang}' on <html> is not a well-formed BCP 47 language tag.";

        $context->report(
            $html,
            $message,
            $this->createReplaceLangFix($langAttribute, $defaultLang, $dangerous)
        );

        return true;
    }

    private function createReplaceLangFix(Attribute $langAttr, string $lang, bool $dangerous): ?Fix
    {
        $quote = AttributeQuoting::styleOf($langAttr);

        return $this->createReplaceAttributeFix(
            $langAttr,
            AttributeQuoting::render('lang', $lang, $quote),
            $dangerous,
        );
    }
}
