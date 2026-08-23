<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Accessibility\Content\AnchorContentRule;
use Forte\Sheath\Rules\Accessibility\Content\ButtonAccessibleNameRule;
use Forte\Sheath\Rules\Accessibility\Content\FormLabelRule;
use Forte\Sheath\Rules\Accessibility\Headings\NoEmptyHeadingsRule;
use Forte\Sheath\Rules\RuleRegistry;

function hiddenContentViolations(Rule $rule, string $code): array
{
    $registry = new RuleRegistry;
    $registry->register($rule);
    $config = Config::make();
    $config->setRule($rule->getId(), $rule->getDefaultSeverity()->value);

    return (new Linter($registry))->lint($code, 'component.blade.php', $config)->violations;
}

describe('hidden accessible content', function (): void {
    it('does not count native hidden descendants', function (Rule $rule, string $code): void {
        expect(hiddenContentViolations($rule, $code))->not->toBeEmpty();
    })->with([
        'button' => [new ButtonAccessibleNameRule, '<button><span hidden>Invisible</span></button>'],
        'anchor' => [new AnchorContentRule, '<a href="/"><span hidden="false">Invisible</span></a>'],
        'label' => [new FormLabelRule, '<label><span hidden>Invisible</span><input type="text"></label>'],
        'heading' => [new NoEmptyHeadingsRule, '<h1><span hidden>Invisible</span></h1>'],
    ]);

    it('does not count text from non-rendered content elements', function (string $tag): void {
        expect(hiddenContentViolations(
            new ButtonAccessibleNameRule,
            '<button><'.$tag.'>Not a name</'.$tag.'></button>',
        ))->not->toBeEmpty();
    })->with(['script', 'style', 'template']);

    it('still uses a hidden element explicitly referenced by aria-labelledby', function (): void {
        expect(hiddenContentViolations(
            new ButtonAccessibleNameRule,
            '<span id="label" hidden>Save</span><button aria-labelledby="label"></button>',
        ))->toBe([]);
    });
});
