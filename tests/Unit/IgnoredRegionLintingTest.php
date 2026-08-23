<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Contracts\IgnoredRegionProvider;
use Forte\Sheath\Fixer;
use Forte\Sheath\Linter;
use Forte\Sheath\Parsing\IgnoredRegion;
use Forte\Sheath\Parsing\IgnoredRegionRegistry;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Accessibility\Content\ImgAltTextRule;
use Forte\Sheath\Rules\BestPractices\Attributes\ButtonTypeRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Rules\RuleRegistry;

final class TestAntlersIgnoredRegionProvider implements IgnoredRegionProvider
{
    public function id(): string
    {
        return 'test-antlers';
    }

    public function regions(string $source, string $filePath): iterable
    {
        $offset = 0;

        while (($start = strpos($source, '@antlers', $offset)) !== false) {
            $end = strpos($source, '@endantlers', $start + 9);
            $end = $end === false ? strlen($source) : $end + strlen('@endantlers');
            yield new IgnoredRegion($start, $end);
            $offset = $end;
        }
    }

    public function cacheContext(): string
    {
        return 'test-v1';
    }
}

final class OriginalDelimiterProbeRule extends AbstractRule
{
    public function getId(): string
    {
        return 'test-original-delimiter';
    }

    public function getDescription(): string
    {
        return 'Reports an unmatched ignored-region delimiter from original source.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $source = $context->getOriginalSource();
        $start = strpos($source, '@antlers');

        if ($start === false || str_contains($source, '@endantlers')) {
            return;
        }

        $context->reportAt(
            Position::fromOffset($document, $start),
            Position::fromOffset($document, $start + strlen('@antlers')),
            'Unmatched @antlers delimiter: '.$context->getOriginalSourceAt($start, $start + 9),
        );
    }
}

final class IgnoredFixProbeRule extends AbstractRule
{
    public function getId(): string
    {
        return 'test-ignored-fix';
    }

    public function getDescription(): string
    {
        return 'Attempts a fix across an ignored source region.';
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
        $start = strpos($context->getOriginalSource(), '@antlers');
        if ($start === false) {
            return;
        }

        $context->reportAt(
            new Position(0, 1, 1),
            new Position(0, 1, 1),
            'Unsafe ignored-region fix.',
            new Fix($start, $start + 1, ''),
        );
    }
}

/** @return array{Linter, RuleRegistry} */
function ignoredRegionLinter(): array
{
    $rules = new RuleRegistry;
    $rules->register(ImgAltTextRule::class);
    $regions = new IgnoredRegionRegistry;
    $regions->register(new TestAntlersIgnoredRegionProvider);

    return [new Linter($rules, ignoredRegionRegistry: $regions), $rules];
}

it('masks foreign syntax and markup while preserving outside offsets and original hash', function (): void {
    [$linter] = ignoredRegionLinter();
    $source = "\xC3\xA9\r\n@antlers\r\n{{ collection:articles\r\n<img src=\"inside\">\r\n@endantlers\r\n\xC3\xA9<img src=\"outside\">";
    $outsideOffset = strpos($source, '<img src="outside">');

    $result = $linter->lint(
        $source,
        'statamic.blade.php',
        Config::make(['rules' => ['a11y-alt-text' => 'error']]),
    );

    expect($result->hasParseErrors)->toBeFalse()
        ->and($result->violations)->toHaveCount(1)
        ->and($result->violations[0]->start->offset)->toBe($outsideOffset)
        ->and($result->violations[0]->start->line)->toBe(6)
        ->and($result->violations[0]->start->character)->toBe(2)
        ->and($result->sourceHash)->toBe(hash('xxh128', $source));
});

it('preserves ignored regions through component semantic remapping', function (): void {
    [$linter] = ignoredRegionLinter();
    $source = "@antlers\n<x-image src=\"inside\">\n@endantlers\n<x-image src=\"outside\">";
    $outsideOffset = strpos($source, '<x-image src="outside">');

    $result = $linter->lint(
        $source,
        'statamic.blade.php',
        Config::make([
            'componentMappings' => ['x-image' => 'img'],
            'rules' => ['a11y-alt-text' => 'error'],
        ]),
    );

    expect($result->hasParseErrors)->toBeFalse()
        ->and($result->violations)->toHaveCount(1)
        ->and($result->violations[0]->start->offset)->toBe($outsideOffset)
        ->and($result->violations[0]->start->line)->toBe(4);
});

it('does not let a suppression comment in foreign content affect outside findings', function (): void {
    [$linter] = ignoredRegionLinter();
    $source = "@antlers\n<!-- sheath-disable-file a11y-alt-text -->\n@endantlers\n<img src=\"outside\">";

    $result = $linter->lint(
        $source,
        'statamic.blade.php',
        Config::make(['rules' => ['a11y-alt-text' => 'error']]),
    );

    expect($result->violations)->toHaveCount(1)
        ->and($result->violations[0]->ruleId)->toBe('a11y-alt-text');
});

it('lets package rules inspect and report original delimiters while ordinary rules see masked content', function (): void {
    [$linter, $rules] = ignoredRegionLinter();
    $rules->register(OriginalDelimiterProbeRule::class);
    $source = "@antlers\n<img src=\"inside\">";

    $result = $linter->lint(
        $source,
        'statamic.blade.php',
        Config::make(['rules' => [
            'a11y-alt-text' => 'error',
            'test-original-delimiter' => 'error',
        ]]),
    );

    expect($result->hasParseErrors)->toBeFalse()
        ->and($result->violations)->toHaveCount(1)
        ->and($result->violations[0]->ruleId)->toBe('test-original-delimiter')
        ->and($result->violations[0]->start->offset)->toBe(0);
});

it('strips fixes that intersect ignored source while keeping their diagnostic', function (): void {
    [$linter, $rules] = ignoredRegionLinter();
    $rules->register(IgnoredFixProbeRule::class);

    $result = $linter->lint(
        "outside\n@antlers\nforeign\n@endantlers",
        'statamic.blade.php',
        Config::make(['rules' => ['test-ignored-fix' => 'warning']]),
    );

    expect($result->violations)->toHaveCount(1)
        ->and($result->violations[0]->fix)->toBeNull();
});

it('applies outside fixes to the original source without changing ignored bytes', function (): void {
    [$linter, $rules] = ignoredRegionLinter();
    $rules->register(ButtonTypeRule::class);
    $source = "@antlers\n<button>inside</button>\n@endantlers\n<button>outside</button>";

    $result = $linter->lint(
        $source,
        'statamic.blade.php',
        Config::make(['rules' => ['best-practices-button-type' => 'warning']]),
    );
    $fixed = (new Fixer)->applyFixes($source, $result->getFixes(), includeDangerous: true);

    expect($result->violations)->toHaveCount(1)
        ->and($fixed->content)->toBe(
            "@antlers\n<button>inside</button>\n@endantlers\n<button type=\"button\">outside</button>"
        );
});
