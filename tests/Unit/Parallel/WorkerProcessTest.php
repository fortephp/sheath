<?php

declare(strict_types=1);

use Forte\Sheath\Parallel\Transport\WorkerChannel;
use Forte\Sheath\Parallel\WorkerProcess;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;
use Forte\Sheath\Serialization\InternalDataCodec;
use React\ChildProcess\Process;
use React\Stream\ThroughStream;

it('decodes lint result records larger than the NDJSON library default', function (): void {
    $input = new ThroughStream;
    $output = new ThroughStream;
    $channel = new WorkerChannel(new Process(PHP_BINARY, fds: []), $input, $output);
    $worker = new WorkerProcess($channel, LintResult::class);

    $violation = new Violation(
        ruleId: 'a11y-alt-text',
        message: str_repeat('A normal lint message. ', 8),
        severity: Severity::ERROR,
        filePath: 'large.blade.php',
        start: new Position(0, 1, 1),
        end: new Position(1, 1, 1),
    );
    $lintResult = new LintResult('large.blade.php', array_fill(0, 400, $violation));
    $record = json_encode(['result' => $lintResult->toArray()], JSON_THROW_ON_ERROR)."\n";

    expect(strlen($record))->toBeGreaterThan(65_536);

    $received = [];
    $worker->onResult(function (LintResult $result) use (&$received): void {
        $received[] = $result;
    });

    $output->write($record);

    expect($received)->toHaveCount(1)
        ->and($received[0]->violations)->toHaveCount(400);
});

it('terminates a busy worker when its input cannot be encoded', function (): void {
    $worker = new WorkerProcess(
        new WorkerChannel(
            new Process(PHP_BINARY, fds: []),
            new ThroughStream,
            new ThroughStream,
        ),
        LintResult::class,
    );
    $errors = [];
    $worker->onError(function (string $error) use (&$errors): void {
        $errors[] = $error;
    });

    $worker->sendFiles(["invalid-\xB1-utf8.blade.php"]);

    expect($errors)->toHaveCount(1)
        ->and($worker->isBusy())->toBeFalse()
        ->and($worker->isTerminated())->toBeTrue();
});

it('releases a busy worker when an oversized protocol record is rejected', function (): void {
    $input = new ThroughStream;
    $output = new ThroughStream;
    $worker = new WorkerProcess(
        new WorkerChannel(new Process(PHP_BINARY, fds: []), $input, $output),
        LintResult::class,
    );
    $errors = [];
    $worker->onError(function (string $error) use (&$errors): void {
        $errors[] = $error;
    });

    $worker->sendFiles(['oversized.blade.php']);
    $output->write(str_repeat('x', 17 * 1024 * 1024)."\n");

    expect($errors)->toHaveCount(1)
        ->and($worker->isBusy())->toBeFalse()
        ->and($worker->isTerminated())->toBeTrue();
});

it('terminates and completes a busy worker when result deserialization fails', function (): void {
    $input = new ThroughStream;
    $output = new ThroughStream;
    $worker = new WorkerProcess(
        new WorkerChannel(new Process(PHP_BINARY, fds: []), $input, $output),
        LintResult::class,
    );
    $errors = [];
    $completed = 0;
    $worker->onError(function (string $error) use (&$errors): void {
        $errors[] = $error;
    });
    $worker->onComplete(function () use (&$completed): void {
        $completed++;
    });

    $worker->sendFiles(['broken.blade.php']);
    $output->write(json_encode([
        'result' => ['filePath' => 'broken.blade.php', 'violations' => 'not-a-list'],
    ], JSON_THROW_ON_ERROR)."\n");

    expect($errors)->toHaveCount(1)
        ->and($completed)->toBe(1)
        ->and($worker->isBusy())->toBeFalse()
        ->and($worker->isTerminated())->toBeTrue();
});

it('decodes binary-safe result payloads losslessly', function (): void {
    $input = new ThroughStream;
    $output = new ThroughStream;
    $worker = new WorkerProcess(
        new WorkerChannel(new Process(PHP_BINARY, fds: []), $input, $output),
        LintResult::class,
    );
    $received = [];
    $worker->onResult(function (LintResult $result) use (&$received): void {
        $received[] = $result;
    });

    $result = new LintResult('binary.blade.php', [new Violation(
        ruleId: 'binary-fix',
        message: 'Binary fix.',
        severity: Severity::WARNING,
        filePath: 'binary.blade.php',
        start: new Position(0, 1, 1),
        end: new Position(1, 1, 2),
        fix: new Fix(0, 1, "\xFF"),
    )]);

    $output->write(json_encode([
        'result' => InternalDataCodec::encode($result->toArray()),
    ], JSON_THROW_ON_ERROR)."\n");

    expect($received)->toHaveCount(1)
        ->and($received[0]->violations[0]->fix?->replacement)->toBe("\xFF");
});
