<?php

declare(strict_types=1);

use Forte\Sheath\Caching\ResultCache;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Console\LintCommand;
use Forte\Sheath\Files\FileFinder;
use Forte\Sheath\Parallel\Config as ParallelConfig;
use Forte\Sheath\Parallel\Factory;
use Forte\Sheath\Parallel\LintWorkerProcessor;
use Forte\Sheath\Parallel\Runner;
use Forte\Sheath\Parsing\IgnoredRegionRegistry;
use Forte\Sheath\Reporters\ReporterRegistry;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Violation;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Tests\Fixtures\Parallel\MarkerIgnoredRegionProvider;
use Symfony\Component\Console\Tester\CommandTester;

require_once __DIR__.'/../Fixtures/parallel/MarkerIgnoredRegionProvider.php';

class ParallelUnavailableLintCommand extends LintCommand
{
    protected function isParallelAvailable(): bool
    {
        return false;
    }
}

function makeParallelTestLintCommand(string $class = LintCommand::class): LintCommand
{
    $registry = new RuleRegistry;
    $registry->discoverRules(__DIR__.'/../../src/Rules');

    /** @var LintCommand $command */
    $command = new $class(
        new FileFinder,
        new ReporterRegistry,
        $registry,
        new ResultCache,
        app(IgnoredRegionRegistry::class),
    );
    $command->setLaravel(app());

    return $command;
}

describe('--parallel availability and fallback', function (): void {
    it('is genuinely available with the optional packages installed, Windows included', function (): void {
        expect(Runner::isAvailable())->toBeTrue();
    });

    it('returns every result with progressive parallel mode enabled', function (): void {
        withSandbox(function (TestViewSandbox $sandbox): void {
            foreach (range(1, 3) as $i) {
                $sandbox->file("page-{$i}.blade.php", '<img src="a.png">');
            }

            $tester = new CommandTester(makeParallelTestLintCommand());
            $status = $tester->execute([
                'paths' => [$sandbox->root],
                '--parallel-if-available' => true,
                '--only' => 'a11y-alt-text',
                '--format' => 'json',
            ], ['capture_stderr_separately' => true]);
            $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $reported = array_map(basename(...), array_column($payload['results'] ?? [], 'filePath'));
            sort($reported);

            expect($status)->toBe(1)
                ->and($reported)->toBe(['page-1.blade.php', 'page-2.blade.php', 'page-3.blade.php']);
        }, TestViewSandbox::make('parallel-threshold-'));
    });

    it('falls back when parallel is unavailable and still lints every file', function (): void {
        withSandbox(function (TestViewSandbox $sandbox): void {
            foreach (range(1, 3) as $i) {
                $sandbox->file("page-{$i}.blade.php", '<img src="a.png">');
            }

            $tester = new CommandTester(makeParallelTestLintCommand(ParallelUnavailableLintCommand::class));
            $status = $tester->execute([
                'paths' => [$sandbox->root],
                '--parallel' => true,
                '--only' => 'a11y-alt-text',
                '--format' => 'json',
            ], ['capture_stderr_separately' => true]);
            $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $reported = array_map(basename(...), array_column($payload['results'] ?? [], 'filePath'));
            sort($reported);

            expect($status)->toBe(1)
                ->and($reported)->toBe(['page-1.blade.php', 'page-2.blade.php', 'page-3.blade.php']);
        }, TestViewSandbox::make('parallel-fallback-'));
    });

    it('uses sequential mode when progressive parallel dependencies are unavailable', function (): void {
        withSandbox(function (TestViewSandbox $sandbox): void {
            foreach (range(1, 3) as $i) {
                $sandbox->file("page-{$i}.blade.php", '<img src="a.png">');
            }

            $tester = new CommandTester(makeParallelTestLintCommand(ParallelUnavailableLintCommand::class));
            $status = $tester->execute([
                'paths' => [$sandbox->root],
                '--parallel-if-available' => true,
                '--processes' => '8',
                '--only' => 'a11y-alt-text',
                '--format' => 'json',
            ], ['capture_stderr_separately' => true]);
            $payload = json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
            $reported = array_map(basename(...), array_column($payload['results'] ?? [], 'filePath'));
            sort($reported);

            expect($status)->toBe(1)
                ->and($reported)->toBe(['page-1.blade.php', 'page-2.blade.php', 'page-3.blade.php']);
        }, TestViewSandbox::make('parallel-progressive-fallback-'));
    });
});

describe('--parallel over the real transport', function (): void {
    it('produces results identical to sequential linting, across real worker processes', function (): void {
        withSandbox(function (TestViewSandbox $sandbox): void {
            $files = [];

            foreach (range(1, 12) as $i) {
                $content = match ($i % 3) {
                    0 => "<div>{!! \$userInput{$i} !!}</div>\n",
                    1 => "<img src=\"photo-{$i}.png\">\n<div>{!! \$raw{$i} !!}</div>\n",
                    default => "[[ignore]]{{ foreign:syntax <img src=\"inside-{$i}\">[[/ignore]]<div>{{ \$safe{$i} }}</div>\n",
                };

                $files[] = $sandbox->file("view-{$i}.blade.php", $content);
            }

            $config = Config::make(['rules' => [
                'security-no-raw-echo' => 'error',
                'a11y-alt-text' => 'warning',
            ]]);

            $script = realpath(__DIR__.'/../Fixtures/parallel/lint-worker.php');
            expect($script)->not->toBeFalse();

            $template = PHP_OS_FAMILY === 'Windows'
                ? sprintf('"%s" "%s" %s', PHP_BINARY, $script, Factory::CONFIG_PLACEHOLDER)
                : sprintf('%s %s %s', escapeshellarg(PHP_BINARY), escapeshellarg((string) $script), Factory::CONFIG_PLACEHOLDER);

            $runner = (new Runner(LintResult::class, 'sheath:worker', ParallelConfig::create(2, 3)))
                ->withCommandTemplate($template);

            $parallelResults = $runner->run($files, $config);

            expect($runner->getErrors())->toBe([]);
            expect($parallelResults)->toHaveCount(12);

            $registry = new RuleRegistry;
            $registry->discoverRules(__DIR__.'/../../src/Rules');
            $ignoredRegions = new IgnoredRegionRegistry;
            $ignoredRegions->register(new MarkerIgnoredRegionProvider);
            $processor = new LintWorkerProcessor($registry, ignoredRegionRegistry: $ignoredRegions);

            $signature = function (LintResult $result): array {
                $violations = array_map(
                    fn (Violation $v): string => sprintf(
                        '%s:%d:%d:%s:%s',
                        $v->ruleId,
                        $v->getLine(),
                        $v->getColumn(),
                        $v->severity->name,
                        $v->message,
                    ),
                    $result->violations,
                );
                sort($violations);

                return [
                    'parseErrors' => $result->hasParseErrors,
                    'violations' => $violations,
                ];
            };

            $parallel = [];
            foreach ($parallelResults as $result) {
                assert($result instanceof LintResult);
                $parallel[$result->filePath] = $signature($result);
            }

            $sequential = [];
            foreach ($files as $file) {
                $result = $processor->process($file, $config);
                assert($result instanceof LintResult);
                $sequential[$result->filePath] = $signature($result);
            }

            ksort($parallel);
            ksort($sequential);

            expect($parallel)->toBe($sequential);

            $totalViolations = array_sum(array_map(count(...), $sequential));
            expect($totalViolations)->toBeGreaterThan(0);
        }, TestViewSandbox::makeInSystemTemp('parallel-e2e-'));
    });
});
