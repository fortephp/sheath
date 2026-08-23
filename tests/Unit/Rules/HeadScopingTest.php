<?php

declare(strict_types=1);

use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Rules\BestPractices\Documents\NoDuplicateInHeadRule;
use Forte\Sheath\Rules\BestPractices\Documents\RequireMetaCharsetRule;
use Forte\Sheath\Rules\BestPractices\Documents\RequireMetaViewportRule;
use Forte\Sheath\Rules\Seo\CanonicalTagRule;
use Forte\Sheath\Rules\Seo\MetaDescriptionRule;
use Forte\Sheath\Rules\Seo\RequireOpenGraphRule;
use Forte\Sheath\Rules\Seo\RequireTitleRule;

it('does not let body metadata satisfy head requirements', function (Rule $rule, string $bodyMarkup): void {
    $this->getRuleTester()->run($rule, [
        'invalid' => [[
            'code' => "<html><head></head><body>{$bodyMarkup}</body></html>",
            'errors' => 1,
        ]],
    ]);
})->with([
    'charset' => [new RequireMetaCharsetRule, '<meta charset="utf-8">'],
    'viewport' => [new RequireMetaViewportRule, '<meta name="viewport" content="width=device-width">'],
    'title' => [new RequireTitleRule, '<title>Body title</title>'],
    'description' => [new MetaDescriptionRule, '<meta name="description" content="A sufficiently long description that would otherwise satisfy this SEO rule.">'],
    'canonical' => [new CanonicalTagRule, '<link rel="canonical" href="https://example.com">'],
    'open graph' => [new RequireOpenGraphRule, '<meta property="og:title"><meta property="og:type"><meta property="og:image"><meta property="og:url">'],
]);

it('ignores duplicates outside head', function (): void {
    $this->getRuleTester()->run(new NoDuplicateInHeadRule, [
        'valid' => [
            '<html><head><title>Head title</title></head><body><title>A</title><title>B</title></body></html>',
            '<html><head></head><body><meta name="viewport"><meta name="viewport"></body></html>',
        ],
    ]);
});
