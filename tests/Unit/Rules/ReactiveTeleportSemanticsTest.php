<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Linter;
use Forte\Sheath\Results\Violation;
use Forte\Sheath\Rules\Accessibility\Content\ButtonAccessibleNameRule;
use Forte\Sheath\Rules\Accessibility\Content\FormLabelRule;
use Forte\Sheath\Rules\Accessibility\Headings\NoHeadingInsideButtonRule;
use Forte\Sheath\Rules\Accessibility\Headings\NoSkipHeadingLevelsRule;
use Forte\Sheath\Rules\BestPractices\Attributes\ButtonTypeRule;
use Forte\Sheath\Rules\BestPractices\Documents\RequireMetaCharsetRule;
use Forte\Sheath\Rules\BestPractices\Markup\NoNestedInteractiveRule;
use Forte\Sheath\Rules\RuleRegistry;

/** @return list<Violation> */
function reactiveTeleportViolations(Rule $rule, string $source): array
{
    $registry = new RuleRegistry;
    $registry->register($rule);

    return (new Linter($registry))->lint(
        $source,
        'component.blade.php',
        Config::make()->setRule($rule->getId(), 'error'),
    )->violations;
}

describe('reactive teleport rendered-tree semantics', function (): void {
    it('does not preserve source ancestry across Livewire teleport blocks', function (Rule $rule, string $source): void {
        expect(reactiveTeleportViolations($rule, $source))->toBe([]);
    })->with([
        'interactive content leaves its source button' => [
            new NoNestedInteractiveRule,
            '<button type="button">Outer @teleport("body")<a href="/x">Link</a>@endteleport</button>',
        ],
        'heading leaves its source button' => [
            new NoHeadingInsideButtonRule,
            '<button type="button">Outer @teleport("body")<h2>Heading</h2>@endteleport</button>',
        ],
    ]);

    it('does not preserve source ancestry across Alpine template teleports', function (Rule $rule, string $source): void {
        expect(reactiveTeleportViolations($rule, $source))->toBe([]);
    })->with([
        'interactive content leaves its source button' => [
            new NoNestedInteractiveRule,
            '<button type="button">Outer <template x-teleport="body"><a href="/x">Link</a></template></button>',
        ],
        'heading leaves its source button' => [
            new NoHeadingInsideButtonRule,
            '<button type="button">Outer <template x-teleport="body"><h2>Heading</h2></template></button>',
        ],
    ]);

    it('does not use teleported content as the accessible name of its source button', function (string $source): void {
        expect(reactiveTeleportViolations(new ButtonAccessibleNameRule, $source))->toHaveCount(1);
    })->with([
        'Livewire' => '<button type="button">@teleport("body")<span>Save</span>@endteleport</button>',
        'Alpine' => '<button type="button"><template x-teleport="body"><span>Save</span></template></button>',
    ]);

    it('keeps document-wide accessible-name references across teleport boundaries', function (string $source): void {
        expect(reactiveTeleportViolations(new ButtonAccessibleNameRule, $source))->toBe([]);
    })->with([
        'Livewire external aria-labelledby' => '<html><body><span id="save-label">Save</span>@teleport("body")<button type="button" aria-labelledby="save-label"></button>@endteleport</body></html>',
        'Alpine external aria-labelledby' => '<html><body><span id="save-label">Save</span><template x-teleport="body"><button type="button" aria-labelledby="save-label"></button></template></body></html>',
    ]);

    it('does not infer label ownership across a teleport boundary', function (Rule $rule, string $source): void {
        expect(reactiveTeleportViolations($rule, $source))->toHaveCount(1);
    })->with([
        'Livewire implicit label' => [
            new FormLabelRule,
            '<label>Name @teleport("body")<input type="text">@endteleport</label>',
        ],
        'Alpine implicit label' => [
            new FormLabelRule,
            '<label>Name <template x-teleport="body"><input type="text"></template></label>',
        ],
    ]);

    it('keeps explicit label references across teleport boundaries', function (string $source): void {
        expect(reactiveTeleportViolations(new FormLabelRule, $source))->toBe([]);
    })->with([
        'Livewire explicit label' => '<label for="name">Name</label>@teleport("body")<input id="name" type="text">@endteleport',
        'Alpine explicit label' => '<label for="name">Name</label><template x-teleport="body"><input id="name" type="text"></template>',
    ]);

    it('does not infer form ownership across a Livewire teleport boundary', function (): void {
        $violations = reactiveTeleportViolations(
            new ButtonTypeRule,
            '<form>@teleport("body")<button>Save</button>@endteleport</form>',
        );

        expect($violations)->toHaveCount(1)
            ->and($violations[0]->fix?->replacement)->toContain('type="button"');
    });

    it('keeps relationships within the same teleported fragment', function (): void {
        expect(reactiveTeleportViolations(
            new FormLabelRule,
            '@teleport("body")<label for="name">Name</label><input id="name" type="text">@endteleport',
        ))->toBe([]);

        expect(reactiveTeleportViolations(
            new NoNestedInteractiveRule,
            '@teleport("body")<button type="button">Outer <a href="/x">Link</a></button>@endteleport',
        ))->toHaveCount(1);
    });

    it('does not use a teleport source position for document-order semantics', function (Rule $rule, string $source, int $expected): void {
        expect(reactiveTeleportViolations($rule, $source))->toHaveCount($expected);
    })->with([
        'Livewire heading order' => [
            new NoSkipHeadingLevelsRule,
            '<h2>Source heading</h2>@teleport("body")<h4>Moved heading</h4>@endteleport',
            0,
        ],
        'Livewire head metadata' => [
            new RequireMetaCharsetRule,
            '<!doctype html><html><head>@teleport("body")<meta charset="utf-8">@endteleport</head><body></body></html>',
            1,
        ],
    ]);
});
