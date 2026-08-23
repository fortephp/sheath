<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;

describe('Config Serialization', function (): void {
    it('survives worker transport without materializing defaults', function (): void {
        $original = Config::make([
            'preset' => 'empty',
            'rules' => ['img-alt-text' => ['warning', ['requireAlt' => true]]],
            'ignore' => ['vendor/**'],
            'neverFix' => ['img-alt-text'],
            'packageRequirementMode' => 'ignore',
            'componentMappings' => ['x-button' => 'button'],
        ]);

        /** @var array<string, mixed> $transported */
        $transported = json_decode(json_encode($original->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $decoded = Config::fromArray($transported);

        expect($decoded->toArray())->toBe($original->toArray())
            ->and($decoded->has('paths'))->toBeFalse()
            ->and($decoded->has('baselineLineTolerance'))->toBeFalse()
            ->and(Config::make()->toArray())->toBe([]);
    });

    it('lets a transported config merge without stomping base settings', function (): void {
        $base = Config::make(['paths' => ['app/views'], 'baselineLineTolerance' => 7]);
        $overlay = Config::fromArray(Config::make(['rules' => ['no-raw-echo' => 'error']])->toArray());

        $base->merge($overlay);

        expect($base->getPaths())->toBe(['app/views'])
            ->and($base->getBaselineLineTolerance())->toBe(7)
            ->and($base->getRules())->toBe(['no-raw-echo' => 'error']);
    });
});

describe('Result Serialization', function (): void {
    it('emits the worker wire shape for violations and fixes', function (): void {
        $violation = new Violation(
            ruleId: 'test-rule',
            message: 'Test message',
            severity: Severity::ERROR,
            filePath: '/path/to/file.blade.php',
            start: new Position(offset: 10, line: 2, character: 5),
            end: new Position(offset: 20, line: 2, character: 15),
            fix: new Fix(startOffset: 10, endOffset: 20, replacement: 'fixed'),
        );

        $data = (new LintResult('/path/to/file.blade.php', [$violation]))->toArray();

        expect($data['violations'])->toHaveCount(1)
            ->and($data['violations'][0]['ruleId'])->toBe($violation->ruleId)
            ->and($data['violations'][0]['message'])->toBe($violation->message)
            ->and($data['violations'][0]['severity'])->toBe('error')
            ->and($data['violations'][0]['offset'])->toBe(10)
            ->and($data['violations'][0]['endOffset'])->toBe(20)
            ->and($data['violations'][0]['fix'])->toBe([
                'startOffset' => 10,
                'endOffset' => 20,
                'replacement' => 'fixed',
                'dangerous' => false,
            ]);
    });

    it('round-trips non-default fix ordering priority', function (): void {
        $fix = new Fix(4, 4, ' /', priority: Fix::PRIORITY_TRAILING_SYNTAX);

        expect(Fix::fromArray($fix->toArray()))->toEqual($fix)
            ->and($fix->toArray()['priority'])->toBe(Fix::PRIORITY_TRAILING_SYNTAX);
    });

    it('survives worker transport with result metadata and violations intact', function (): void {
        $violation = new Violation(
            ruleId: 'no-raw-echo',
            message: 'Test message',
            severity: Severity::ERROR,
            filePath: '/path/to/file.blade.php',
            start: new Position(offset: 50, line: 5, character: 10),
            end: new Position(offset: 60, line: 5, character: 20),
            fix: new Fix(startOffset: 50, endOffset: 60, replacement: '{{ $safe }}'),
        );
        $original = new LintResult(
            filePath: '/path/to/file.blade.php',
            violations: [$violation],
            hasParseErrors: true,
            sourceHash: '0123456789abcdef',
        );

        /** @var array<string, mixed> $transported */
        $transported = json_decode(json_encode($original->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $decoded = LintResult::fromArray($transported);

        expect($decoded->filePath)->toBe($original->filePath)
            ->and($decoded->hasParseErrors)->toBeTrue()
            ->and($decoded->sourceHash)->toBe($original->sourceHash)
            ->and($decoded->violations)->toHaveCount(1)
            ->and($decoded->violations[0])->toEqual($violation);
    });

    it('rejects a malformed violations collection instead of decoding a false clean result', function (): void {
        expect(fn (): LintResult => LintResult::fromArray([
            'filePath' => '/path/to/file.blade.php',
            'violations' => 'not-a-list',
        ]))->toThrow(InvalidArgumentException::class);
    });

    it('rejects malformed violation entries instead of filling required fields with defaults', function (): void {
        expect(fn (): LintResult => LintResult::fromArray([
            'filePath' => '/path/to/file.blade.php',
            'violations' => [['message' => 'Missing required fields']],
        ]))->toThrow(InvalidArgumentException::class);
    });

    it('rejects zero-based columns from serialized data', function (string $column): void {
        $data = [
            'ruleId' => 'test-rule',
            'message' => 'Test message',
            'severity' => 'error',
            'filePath' => '/path/to/file.blade.php',
            'offset' => 0,
            'line' => 1,
            'column' => 1,
            'endOffset' => 1,
            'endLine' => 1,
            'endColumn' => 2,
        ];
        $data[$column] = 0;

        expect(fn (): Violation => Violation::fromArray($data))
            ->toThrow(InvalidArgumentException::class);
    })->with(['column', 'endColumn']);

    it('rejects a same-line range whose end column precedes its start column', function (): void {
        expect(fn (): Violation => Violation::fromArray([
            'ruleId' => 'test-rule',
            'message' => 'Test message',
            'severity' => 'error',
            'filePath' => '/path/to/file.blade.php',
            'offset' => 10,
            'line' => 2,
            'column' => 8,
            'endOffset' => 12,
            'endLine' => 2,
            'endColumn' => 3,
        ]))->toThrow(InvalidArgumentException::class);
    });
});
