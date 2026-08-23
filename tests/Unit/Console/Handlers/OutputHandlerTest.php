<?php

declare(strict_types=1);

use Forte\Sheath\Console\Handlers\OutputHandler;
use Forte\Sheath\Reporters\ReporterRegistry;
use Forte\Sheath\Results\LintResult;
use Illuminate\Console\Command;

describe('parseMaxWarnings', function (): void {
    it('parses valid thresholds', function (mixed $value, int $expected): void {
        expect(OutputHandler::parseMaxWarnings($value))->toBe($expected);
    })->with([
        'disabled by default' => [null, -1],
        'empty string' => ['', -1],
        'explicit -1' => ['-1', -1],
        'zero' => ['0', 0],
        'positive' => ['10', 10],
        'integer from array-style test input' => [5, 5],
        'surrounding whitespace' => [' 3 ', 3],
    ]);

    it('rejects values that are not non-negative integers', function (mixed $value): void {
        OutputHandler::parseMaxWarnings($value);
    })->with([
        'letters' => ['abc'],
        'decimal' => ['1.5'],
        'negative other than -1' => ['-2'],
        'trailing junk' => ['3x'],
    ])->throws(InvalidArgumentException::class);
});

it('surfaces an invalid --max-warnings before formatting run-context reporters', function (): void {
    $command = new class extends Command
    {
        protected $signature = 'test:output-handler';

        public function option($key = null)
        {
            return $key === 'max-warnings' ? 'abc' : null;
        }
    };

    $handler = new OutputHandler($command, new ReporterRegistry);

    expect(fn (): bool => $handler->output([new LintResult('a.blade.php', [])], 'agent'))
        ->toThrow(InvalidArgumentException::class);
});
