<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\BestPractices\Documents\RequireMetaCharsetRule;
use Forte\Sheath\Rules\BestPractices\Documents\RequireMetaViewportRule;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Rules\Seo\CanonicalTagRule;
use Forte\Sheath\Rules\Seo\MetaDescriptionRule;
use Forte\Sheath\Rules\Seo\RequireOpenGraphRule;
use Forte\Sheath\Rules\Seo\RequireTitleRule;

function headMetadataViolations(Rule $rule, string $head): array
{
    $registry = new RuleRegistry;
    $registry->register($rule);
    $config = Config::make();
    $config->setRule($rule->getId(), [
        'severity' => $rule->getDefaultSeverity()->value,
        'options' => $rule->getOptions(),
    ]);

    return (new Linter($registry))->lint(
        '<html><head>'.$head.'</head><body></body></html>',
        'page.blade.php',
        $config,
    )->violations;
}

describe('required head metadata across Blade render paths', function (): void {
    it('reports metadata that exists only in a one-sided conditional', function (Rule $rule, string $metadata): void {
        expect(headMetadataViolations($rule, '@if($ok)'.$metadata.'@endif'))->not->toBeEmpty();
    })->with([
        'title' => [new RequireTitleRule, '<title>Page</title>'],
        'charset' => [new RequireMetaCharsetRule, '<meta charset="utf-8">'],
        'viewport' => [new RequireMetaViewportRule, '<meta name="viewport" content="width=device-width, initial-scale=1">'],
        'canonical' => [new CanonicalTagRule, '<link rel="canonical" href="/page">'],
        'description' => [new MetaDescriptionRule, '<meta name="description" content="A complete page description that is comfortably longer than fifty characters.">'],
        'open graph' => [new RequireOpenGraphRule, '<meta property="og:title" content="Page"><meta property="og:type" content="website"><meta property="og:image" content="/image.png"><meta property="og:url" content="/page">'],
    ]);

    it('accepts metadata supplied by every conditional branch', function (Rule $rule, string $metadata): void {
        $head = '@if($ok)'.$metadata.'@else'.$metadata.'@endif';

        expect(headMetadataViolations($rule, $head))->toBe([]);
    })->with([
        'title' => [new RequireTitleRule, '<title>Page</title>'],
        'charset' => [new RequireMetaCharsetRule, '<meta charset="utf-8">'],
        'viewport' => [new RequireMetaViewportRule, '<meta name="viewport" content="width=device-width, initial-scale=1">'],
        'canonical' => [new CanonicalTagRule, '<link rel="canonical" href="/page">'],
        'description' => [new MetaDescriptionRule, '<meta name="description" content="A complete page description that is comfortably longer than fifty characters.">'],
        'open graph' => [new RequireOpenGraphRule, '<meta property="og:title" content="Page"><meta property="og:type" content="website"><meta property="og:image" content="/image.png"><meta property="og:url" content="/page">'],
    ]);

    it('does not treat a possibly empty loop as guaranteed metadata', function (): void {
        expect(headMetadataViolations(
            new RequireTitleRule,
            '@foreach($pages as $page)<title>{{ $page->title }}</title>@endforeach',
        ))->not->toBeEmpty();
    });

    it('recognizes exhaustive branches of other Blade conditionals', function (): void {
        expect(headMetadataViolations(
            new RequireTitleRule,
            '@can(\'edit\')<title>Edit</title>@else<title>View</title>@endcan',
        ))->toBe([]);
    });

    it('recognizes exhaustive forelse and switch outcomes', function (string $head): void {
        expect(headMetadataViolations(new RequireTitleRule, $head))->toBe([]);
    })->with([
        'forelse' => '@forelse($pages as $page)<title>{{ $page->title }}</title>@empty<title>No pages</title>@endforelse',
        'switch' => "@switch(\$kind)\n@case(1)<title>One</title>\n@break\n@default<title>Other</title>\n@endswitch",
        'stacked fallthrough cases' => "@switch(\$kind)\n@case(1)\n@case(2)<title>One or two</title>\n@break\n@default<title>Other</title>\n@endswitch",
    ]);

    it('does not treat a switch without a default as exhaustive', function (): void {
        expect(headMetadataViolations(
            new RequireTitleRule,
            "@switch(\$kind)\n@case(1)<title>One</title>\n@break\n@endswitch",
        ))->not->toBeEmpty();
    });

    it('does not treat content after a conditional switch exit as guaranteed', function (string $head): void {
        expect(headMetadataViolations(new RequireTitleRule, $head))->not->toBeEmpty();
    })->with([
        'conditional break' => "@switch(\$kind)\n@case(1)@break(\$skip)<title>One</title>@break\n@default<title>Other</title>\n@endswitch",
        'break nested in a conditional' => "@switch(\$kind)\n@case(1)@if(\$skip)@break @endif<title>One</title>@break\n@default<title>Other</title>\n@endswitch",
    ]);
});

describe('dynamic head metadata discriminators', function (): void {
    it('stands down when a Blade expression may select the metadata kind', function (Rule $rule, string $metadata): void {
        expect(headMetadataViolations($rule, $metadata))->toBe([]);
    })->with([
        'canonical rel' => [new CanonicalTagRule, '<link rel="{{ $rel }}" href="/page">'],
        'description name' => [new MetaDescriptionRule, '<meta name="{{ $name }}" content="A complete page description that is comfortably longer than fifty characters.">'],
        'viewport name' => [new RequireMetaViewportRule, '<meta name="{{ $name }}" content="width=device-width, initial-scale=1">'],
        'Open Graph property' => [new RequireOpenGraphRule, '<meta property="{{ $property }}" content="value">'],
    ]);
});
