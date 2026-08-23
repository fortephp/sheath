<?php

declare(strict_types=1);

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Testing\RuleTester;

class CyclingRuleTesterProbeRule extends AbstractRule
{
    public static int $checks = 0;

    public function getId(): string
    {
        return 'rule-tester-cycle-probe';
    }

    public function getDescription(): string
    {
        return 'Alternates between two equivalent test fixtures.';
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
        self::$checks++;

        foreach ([['b', 'i'], ['i', 'b']] as [$from, $to]) {
            $document->findElementsByName($from)->each(
                fn (ElementNode $element) => $context->report(
                    $element,
                    'Cycle probe.',
                    Fix::fromNode($element, "<{$to}>x</{$to}>")
                )
            );
        }
    }
}

it('stops fixpoint testing when a rule enters a content cycle', function (): void {
    CyclingRuleTesterProbeRule::$checks = 0;

    $fixed = (new RuleTester)->fixToFixpoint(
        new CyclingRuleTesterProbeRule,
        '<b>x</b>',
        maxPasses: 10,
    );

    expect($fixed)->toBe('<b>x</b>')
        ->and(CyclingRuleTesterProbeRule::$checks)->toBe(2);
});

it('accepts named valid and invalid cases', function (): void {
    (new RuleTester)->run(new CyclingRuleTesterProbeRule, [
        'valid' => [
            'plain text stays valid' => '<span>x</span>',
        ],
        'invalid' => [
            'bold element is diagnosed' => [
                'code' => '<b>x</b>',
                'errors' => 1,
                'output' => '<i>x</i>',
            ],
        ],
    ]);
});
