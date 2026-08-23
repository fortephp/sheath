<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\BestPractices\Markup;

use Forte\Ast\Document\Document;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;

/** @internal */
class NoObsoleteTagsRule extends AbstractRule
{
    protected const OBSOLETE_TAGS = [
        'applet', 'acronym', 'bgsound', 'dir', 'frame', 'frameset',
        'noframes', 'isindex', 'keygen', 'listing', 'menuitem', 'nextid',
        'noembed', 'plaintext', 'rb', 'rtc', 'strike', 'xmp', 'basefont',
        'big', 'blink', 'center', 'font', 'marquee', 'multicol', 'nobr',
        'spacer', 'tt',
        'param',
    ];

    protected array $options = [
        'allow' => [],
    ];

    public function getId(): string
    {
        return 'best-practices-no-obsolete-tags';
    }

    public function getDescription(): string
    {
        return 'Disallow obsolete HTML elements.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BEST_PRACTICES;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $allowed = array_map(
            static fn (mixed $tag): string => strtolower(is_string($tag) ? $tag : ''),
            (array) $this->getOption('allow', [])
        );
        $tags = array_values(array_diff(self::OBSOLETE_TAGS, $allowed));

        $byTag = $document->elementsGroupedByName($tags);

        foreach ($tags as $tag) {
            foreach ($byTag[$tag] as $element) {
                $context->report(
                    $element,
                    "Obsolete HTML element <{$tag}>."
                );
            }
        }
    }
}
