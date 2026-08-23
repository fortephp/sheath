<?php

declare(strict_types=1);

use Forte\Sheath\Console\Handlers\OutputHandler;
use Forte\Sheath\Reporters\ReporterRegistry;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SplitConsoleOutput extends BufferedOutput implements ConsoleOutputInterface
{
    private readonly BufferedOutput $stderr;

    public function __construct()
    {
        parent::__construct(OutputInterface::VERBOSITY_NORMAL, false);
        $this->stderr = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, false);
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->stderr;
    }

    public function setErrorOutput(OutputInterface $error): void {}

    public function section(): never
    {
        throw new RuntimeException('Not needed for these assertions.');
    }

    public function stderrBuffer(): string
    {
        return $this->stderr->fetch();
    }
}

function splitStreamCommand(SplitConsoleOutput $output): Command
{
    $command = new class extends Command
    {
        protected $signature = 'stream-separation:probe';
    };

    $command->setOutput(new OutputStyle(new ArrayInput([]), $output));

    return $command;
}

function violationForStreams(string $message = 'Images must have an alt attribute'): Violation
{
    return new Violation(
        ruleId: 'a11y-alt-text',
        message: $message,
        severity: Severity::ERROR,
        filePath: 'resources/views/home.blade.php',
        start: new Position(0, 1, 1),
        end: new Position(17, 1, 17),
    );
}

it('writes the report to stdout and statistics to stderr', function (): void {
    $output = new SplitConsoleOutput;
    $handler = new OutputHandler(splitStreamCommand($output), new ReporterRegistry);

    $results = [new LintResult('resources/views/home.blade.php', [violationForStreams()], false)];

    $handler->output($results, 'json');
    $handler->showStats(['filesProcessed' => 1, 'filesFromCache' => 0, 'lintTime' => 0.1, 'totalTime' => 0.2]);

    $stdout = $output->fetch();

    expect(json_decode($stdout, true))->toBeArray()
        ->and($output->stderrBuffer())->not->toBe('');
});

it('writes performance statistics to standard error', function (): void {
    $output = new SplitConsoleOutput;
    $handler = new OutputHandler(splitStreamCommand($output), new ReporterRegistry);

    $handler->showStats(['filesProcessed' => 2, 'filesFromCache' => 1, 'lintTime' => 0.1, 'totalTime' => 0.2]);

    expect($output->stderrBuffer())->not->toBe('')
        ->and($output->fetch())->toBe('');
});

it('keeps the json report parseable when a notice accompanies it', function (): void {
    $output = new SplitConsoleOutput;
    $command = splitStreamCommand($output);
    $handler = new OutputHandler($command, new ReporterRegistry);

    $results = [new LintResult('resources/views/home.blade.php', [violationForStreams()], false)];

    $handler->showStats(['filesProcessed' => 1, 'filesFromCache' => 0, 'lintTime' => 0.1, 'totalTime' => 0.2]);
    $handler->output($results, 'json');

    $decoded = json_decode($output->fetch(), true);

    expect($decoded)->toBeArray()
        ->and($decoded['results'][0]['violations'][0]['ruleId'])->toBe('a11y-alt-text');
});

it('writes machine reporter output without interpreting console markup', function (): void {
    $output = new SplitConsoleOutput;
    $handler = new OutputHandler(splitStreamCommand($output), new ReporterRegistry);
    $message = 'Move the <info>tag</info> outside the component';

    $handler->output([
        new LintResult('resources/views/home.blade.php', [violationForStreams($message)]),
    ], 'json');

    $decoded = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

    expect($decoded['results'][0]['violations'][0]['message'])->toBe($message);
});

it('writes a failed-output notice to standard error, leaving stdout empty', function (): void {
    $output = new SplitConsoleOutput;
    $handler = new OutputHandler(splitStreamCommand($output), new ReporterRegistry);

    $results = [new LintResult('resources/views/home.blade.php', [violationForStreams()], false)];

    $written = $handler->output($results, 'json', base_path('no/such/directory/report.json'));

    expect($written)->toBeFalse()
        ->and($output->stderrBuffer())->not->toBe('')
        ->and($output->fetch())->toBe('');
});
