<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Parallel\Contracts\WorkerConfig;
use Forte\Sheath\Parallel\LintWorkerProcessor;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Rules\Security\NoRawEchoRule;

describe('LintWorkerProcessor', function (): void {
    beforeEach(function (): void {
        $this->testDir = sys_get_temp_dir().'/sheath-processor-test-'.uniqid();
        mkdir($this->testDir);
    });

    afterEach(function (): void {
        if (is_dir($this->testDir)) {
            array_map(unlink(...), glob($this->testDir.'/*') ?: []);
            rmdir($this->testDir);
        }
    });

    describe('process', function (): void {
        it('processes a clean Blade file', function (): void {
            $filePath = $this->testDir.'/test.blade.php';
            file_put_contents($filePath, '<div>Hello World</div>');

            $ruleRegistry = new RuleRegistry;
            $processor = new LintWorkerProcessor($ruleRegistry);

            $result = $processor->process($filePath, Config::make());

            expect($result)->toBeInstanceOf(LintResult::class)
                ->and($result->getFilePath())->toBe($filePath)
                ->and($result->hasViolations())->toBeFalse();
        });

        it('returns result with violations for problematic code', function (): void {
            $filePath = $this->testDir.'/raw-echo.blade.php';
            file_put_contents($filePath, '{!! $userInput !!}');

            $ruleRegistry = new RuleRegistry;
            $ruleRegistry->register(NoRawEchoRule::class);
            $processor = new LintWorkerProcessor($ruleRegistry);

            $result = $processor->process(
                $filePath,
                Config::make(['rules' => ['security-no-raw-echo' => 'error']])
            );

            expect($result)->toBeInstanceOf(LintResult::class);
            expect($result->filePath)->toBe($filePath);
            expect($result->hasViolations())->toBeTrue();
        });

        it('returns a parse-error result when a file exceeds the parser depth limit', function (): void {
            $filePath = $this->testDir.'/deep.blade.php';
            file_put_contents($filePath, str_repeat('<div>', 2048).str_repeat('</div>', 2048));

            $processor = new LintWorkerProcessor(new RuleRegistry);
            $result = $processor->process($filePath, Config::make());

            expect($result->hasParseErrors)->toBeTrue()
                ->and($result->violations)->toHaveCount(1)
                ->and($result->violations[0]->ruleId)->toBe('parse-error');
        });

        it('throws exception for non-existent file', function (): void {
            $filePath = $this->testDir.'/nonexistent.blade.php';

            $ruleRegistry = new RuleRegistry;
            $processor = new LintWorkerProcessor($ruleRegistry);

            expect(fn () => $processor->process($filePath, Config::make()))
                ->toThrow(RuntimeException::class);
        });

        it('rejects the wrong worker config type even when assertions are disabled', function (): void {
            $processor = new LintWorkerProcessor(new RuleRegistry);
            $config = new class implements WorkerConfig
            {
                public function toArray(): array
                {
                    return [];
                }

                public static function fromArray(array $data): static
                {
                    return new self;
                }
            };

            expect(fn () => $processor->process('unused.blade.php', $config))
                ->toThrow(InvalidArgumentException::class);
        });

    });
});
