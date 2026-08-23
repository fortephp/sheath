<?php

declare(strict_types=1);

use Forte\Sheath\Reporters\AgentReporter;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;

function warningOnlyResult(): LintResult
{
    return new LintResult('test.blade.php', [
        new Violation(
            ruleId: 'a11y-alt-text',
            message: 'Image missing alt text',
            severity: Severity::WARNING,
            filePath: 'test.blade.php',
            start: new Position(0, 1, 1),
            end: new Position(5, 1, 6),
        ),
    ]);
}

describe('ReceivesRunContext', function (): void {
    it('feeds maxWarnings into the pass/fail verdict', function (): void {
        $failing = new AgentReporter;
        $failing->receiveRunContext(['maxWarnings' => 0]);

        $passing = new AgentReporter;
        $passing->receiveRunContext(['maxWarnings' => 5]);

        expect(json_decode($failing->formatMany([warningOnlyResult()]), true)['result'])->toBe('fail')
            ->and(json_decode($passing->formatMany([warningOnlyResult()]), true)['result'])->toBe('passed');
    });

    it('ignores keys it does not recognize and wrong types', function (): void {
        $reporter = new AgentReporter;

        $reporter->receiveRunContext(['somethingElse' => true, 'maxWarnings' => 'zero']);

        expect(json_decode($reporter->formatMany([warningOnlyResult()]), true)['result'])->toBe('passed');
    });
});
