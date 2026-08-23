<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\RuleRegistry;

/** @param array<string, mixed> $options */
function htmlReferenceViolationCount(string $ruleId, string $source, array $options = []): int
{
    $registry = RuleRegistry::withBuiltInRules();
    $ruleConfig = $options === []
        ? 'error'
        : ['severity' => 'error', 'options' => $options];
    $result = (new Linter($registry))->lint(
        $source,
        'entities.blade.php',
        Config::make(['rules' => [$ruleId => $ruleConfig]])
    );

    return count(array_filter(
        $result->violations,
        static fn ($violation): bool => $violation->ruleId === $ruleId
    ));
}

it('uses browser-decoded static values in every affected rule', function (
    string $ruleId,
    string $source,
    int $expected,
    array $options = [],
): void {
    expect(htmlReferenceViolationCount($ruleId, $source, $options))->toBe($expected);
})->with([
    'abstract role' => ['a11y-no-abstract-roles', '<div role="widg&#101;t">x</div>', 1],
    'valid role' => ['a11y-no-invalid-role', '<div role="but&#116;on">x</div>', 0],
    'aria hidden' => ['a11y-no-aria-hidden-on-focusable', '<button aria-hidden="tr&#117;e">x</button>', 1],
    'positive tabindex' => ['a11y-no-positive-tabindex', '<div tabindex="&#49;">x</div>', 1],
    'language tag' => ['a11y-html-lang', '<html lang="e&#110;"><head></head><body></body></html>', 0],
    'viewport scale' => ['a11y-no-non-scalable-viewport', '<meta name="viewp&#111;rt" content="maximum-scale=&#49;">', 1],
    'heading role' => ['a11y-no-heading-inside-button', '<div role="but&#116;on"><h2>x</h2></div>', 1],
    'presentational table role' => ['a11y-table-headers', '<table role="n&#111;ne"><tr><td>a</td></tr><tr><td>b</td></tr></table>', 0],
    'anchor whitespace name' => ['a11y-anchor-content', '<a href="/">&#32;</a>', 1],
    'button whitespace name' => ['a11y-button-accessible-name', '<button>&#32;</button>', 1],
    'matching label id' => ['a11y-form-label', '<label for="f&#111;o">Name</label><input id="foo">', 0],
    'empty alt' => ['a11y-alt-text', '<img src="x" alt="&#32;">', 1, ['requireNonEmpty' => true]],
    'empty frame title' => ['a11y-require-frame-title', '<iframe title="&#32;"></iframe>', 1],
    'empty heading' => ['a11y-no-empty-headings', '<h2>&#32;</h2>', 1],
    'button type' => ['best-practices-button-type', '<button type="butt&#111;n">x</button>', 0],
    'duplicate class' => ['best-practices-no-duplicate-class', '<div class="foo f&#111;o"></div>', 1],
    'duplicate id' => ['best-practices-no-duplicate-id', '<div id="foo"></div><span id="f&#111;o"></span>', 1],
    'allowed inline property' => ['best-practices-no-inline-styles', '<div style="c&#111;lor:red"></div>', 0, ['allowedProperties' => ['color']]],
    'form method' => ['best-practices-require-form-method', '<form method="p&#111;st"></form>', 0],
    'duplicate head metadata' => ['best-practices-no-duplicate-in-head', '<html><head><meta name="viewp&#111;rt"><meta name="viewport"></head><body></body></html>', 1],
    'required viewport' => ['best-practices-require-meta-viewport', '<html><head><meta name="viewp&#111;rt" content="width=device-width, initial-scale=1"></head><body></body></html>', 0],
    'nested interactive tabindex' => ['best-practices-no-nested-interactive', '<div tabindex="&#48;"><button>x</button></div>', 0],
    'script type' => ['best-practices-no-script-style-type', '<script type="text/javascr&#105;pt"></script>', 1],
    'canonical relation' => ['seo-canonical-tag', '<html><head><link rel="can&#111;nical" href="https://e.test"></head><body></body></html>', 0],
    'description name and length' => ['seo-meta-description', '<html><head><meta name="descr&#105;ption" content="&#65;&#65;&#65;&#65;&#65;&#65;&#65;&#65;&#65;&#65;"></head><body></body></html>', 1],
    'open graph properties' => ['seo-require-open-graph', '<html><head><meta property="og&#58;title" content="T"><meta property="og&#58;type" content="website"><meta property="og&#58;image" content="i"><meta property="og&#58;url" content="u"></head><body></body></html>', 0],
    'empty title' => ['seo-require-title', '<html><head><title>&#32;</title></head><body></body></html>', 1],
    'lazy fetch priority' => ['perf-lazy-load-images', '<img src="hero.jpg"><img src="next.jpg" fetchpriority="h&#105;gh">', 0],
    'javascript MIME type' => ['perf-no-render-blocking', '<html><head><script src="app.js" type="text/javascr&#105;pt"></script></head><body></body></html>', 1],
    'SVG source' => ['perf-require-explicit-size', '<img src="icon.sv&#103;" alt="x">', 0],
    'small image width' => ['perf-responsive-images', '<img src="photo.jpg" width="&#50;00" alt="x">', 0],
    'HTTP URL' => ['security-prefer-https', '<a href="http&#58;//evil.test">x</a>', 1],
    'explicit opener' => ['security-no-target-blank', '<a target="_blank" rel="op&#101;ner">x</a>', 1],
    'POST method' => ['security-csrf-field', '<form method="p&#111;st"></form>', 1],
    'spoofed method' => ['blade-method-field', '<form method="D&#69;LETE"></form>', 1],
    'JSON script type' => ['blade-prefer-json-in-script', '<script type="application&#47;json">{{ $data }}</script>', 1],
]);
