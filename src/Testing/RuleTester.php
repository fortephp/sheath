<?php

declare(strict_types=1);

namespace Forte\Sheath\Testing;

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Fixer;
use Forte\Sheath\Linter;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Violation;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\SheathManager;
use PHPUnit\Framework\Assert;

class RuleTester
{
    private string $filePath = 'test.blade.php';

    public function withFilePath(string $path): static
    {
        $this->filePath = $path;

        return $this;
    }

    /**
     * @param  array{valid?: array<int|string, string|array{code: string, path?: string}>, invalid?: array<int|string, array{code: string, path?: string, errors: int|array<array{message?: string, line?: int, column?: int}>}>}  $tests
     */
    public function run(Rule $rule, array $tests): void
    {
        if (isset($tests['valid'])) {
            foreach ($tests['valid'] as $index => $test) {
                if (is_string($test)) {
                    $code = $test;
                    $path = $this->filePath;
                } else {
                    $code = $test['code'];
                    $path = $test['path'] ?? $this->filePath;
                }
                $this->runValidTest($rule, $code, $index, $path);
            }
        }

        if (isset($tests['invalid'])) {
            foreach ($tests['invalid'] as $index => $test) {
                $path = $test['path'] ?? $this->filePath;
                $this->runInvalidTest($rule, $test, $index, $path);
            }
        }
    }

    private function runValidTest(Rule $rule, string $code, int|string $index, string $path = 'test.blade.php'): void
    {
        $result = $this->lint($rule, $code, $path);

        $violations = array_filter(
            $result->violations,
            fn ($v) => $v->ruleId === $rule->getId()
        );

        Assert::assertCount(
            0,
            $violations,
            sprintf(
                "Valid test case #%s should have no violations, but found:\n%s\nCode:\n%s",
                $index,
                $this->formatViolations($violations),
                $code
            )
        );
    }

    /**
     * @param  array{code: string, path?: string, hasFix?: bool, errors: int|array<array{message?: string, line?: int, column?: int, hasFix?: bool, hasFixAvailable?: bool, hasDangerousFix?: bool}>, output?: string}  $test
     */
    private function runInvalidTest(Rule $rule, array $test, int|string $index, string $path = 'test.blade.php'): void
    {
        $code = $test['code'];
        $expectedErrors = $test['errors'];
        $expectedErrorCount = is_int($expectedErrors) ? $expectedErrors : count($expectedErrors);
        $expectedOutput = $test['output'] ?? null;
        $expectedHasFix = isset($test['hasFix']) && is_bool($test['hasFix']) ? $test['hasFix'] : null;

        $result = $this->lint($rule, $code, $path);

        $violations = array_filter(
            $result->violations,
            fn ($v) => $v->ruleId === $rule->getId()
        );

        $violations = array_values($violations);

        Assert::assertCount(
            $expectedErrorCount,
            $violations,
            sprintf(
                "Invalid test case #%s expected %d errors but got %d.\nCode:\n%s\nViolations:\n%s",
                $index,
                $expectedErrorCount,
                count($violations),
                $code,
                $this->formatViolations($violations)
            )
        );

        foreach (is_array($expectedErrors) ? $expectedErrors : [] as $errorIndex => $expectedError) {
            $violation = $violations[$errorIndex];

            if (isset($expectedError['message'])) {
                Assert::assertSame(
                    $expectedError['message'],
                    $violation->message,
                    sprintf(
                        'Invalid test case #%s, error #%d: message mismatch',
                        $index,
                        $errorIndex
                    )
                );
            }

            if (isset($expectedError['line'])) {
                Assert::assertSame(
                    $expectedError['line'],
                    $violation->getLine(),
                    sprintf(
                        'Invalid test case #%s, error #%d: line mismatch',
                        $index,
                        $errorIndex
                    )
                );
            }

            if (isset($expectedError['column'])) {
                Assert::assertSame(
                    $expectedError['column'],
                    $violation->getColumn(),
                    sprintf(
                        'Invalid test case #%s, error #%d: column mismatch',
                        $index,
                        $errorIndex
                    )
                );
            }

            $errorExpectedHasFix = $expectedError['hasFixAvailable'] ?? $expectedError['hasFix'] ?? $expectedHasFix;
            if (is_bool($errorExpectedHasFix)) {
                Assert::assertSame(
                    $errorExpectedHasFix,
                    $violation->hasFixAvailable(),
                    sprintf(
                        'Invalid test case #%s, error #%d: hasFixAvailable mismatch (expected %s, got %s)',
                        $index,
                        $errorIndex,
                        $errorExpectedHasFix ? 'true' : 'false',
                        $violation->hasFixAvailable() ? 'true' : 'false'
                    )
                );
            }

            if (isset($expectedError['hasDangerousFix'])) {
                Assert::assertSame(
                    $expectedError['hasDangerousFix'],
                    $violation->hasDangerousFix(),
                    sprintf(
                        'Invalid test case #%s, error #%d: hasDangerousFix mismatch (expected %s, got %s)',
                        $index,
                        $errorIndex,
                        $expectedError['hasDangerousFix'] ? 'true' : 'false',
                        $violation->hasDangerousFix() ? 'true' : 'false'
                    )
                );
            }
        }

        if ($expectedOutput !== null) {
            $this->validateFixOutput($code, $violations, $expectedOutput, $index);
        }
    }

    /**
     * @param  array<Violation>  $violations
     */
    private function validateFixOutput(string $code, array $violations, string $expectedOutput, int|string $index): void
    {
        $fixes = [];

        foreach ($violations as $violation) {
            if ($violation->fix !== null) {
                $fixes[] = $violation->fix;
            }
        }

        if (empty($fixes)) {
            Assert::fail(sprintf(
                'Invalid test case #%s: output specified but no fixes available',
                $index
            ));
        }

        $result = (new Fixer)->applyFixes($code, $fixes, includeDangerous: true);

        Assert::assertSame(
            $expectedOutput,
            $result->content,
            sprintf(
                "Invalid test case #%s: fixed output mismatch.\nExpected:\n%s\n\nActual:\n%s",
                $index,
                $expectedOutput,
                $result->content
            )
        );
    }

    public function fix(Rule $rule, string $code, bool $dangerous = false): string
    {
        $result = $this->lint($rule, $code, $this->filePath);

        $fixes = [];
        foreach ($result->violations as $violation) {
            if ($violation->fix !== null) {
                $fixes[] = $violation->fix;
            }
        }

        if ($fixes === []) {
            return $code;
        }

        return (new Fixer)->applyFixes($code, $fixes, includeDangerous: $dangerous)->content;
    }

    public function fixToFixpoint(Rule $rule, string $code, bool $dangerous = false, int $maxPasses = SheathManager::MAX_FIX_PASSES): string
    {
        $seenContent = [hash('xxh128', $code) => true];

        for ($pass = 0; $pass < $maxPasses; $pass++) {
            $fixed = $this->fix($rule, $code, $dangerous);

            if ($fixed === $code) {
                break;
            }

            $code = $fixed;
            $contentHash = hash('xxh128', $code);
            if (isset($seenContent[$contentHash])) {
                break;
            }

            $seenContent[$contentHash] = true;
        }

        return $code;
    }

    private function lint(Rule $rule, string $code, string $path = 'test.blade.php'): LintResult
    {
        $registry = new RuleRegistry;
        $registry->register($rule);

        $options = $rule instanceof AbstractRule
            ? $rule->getConfiguredOptions()
            : $rule->getOptions();

        $config = Config::make()->setRule($rule->getId(), [
            'severity' => $rule->getDefaultSeverity()->value,
            'options' => $options,
        ]);

        return (new Linter($registry))->lint($code, $path, $config);
    }

    /**
     * @param  array<Violation>  $violations
     */
    private function formatViolations(array $violations): string
    {
        if (empty($violations)) {
            return '(none)';
        }

        $lines = [];
        foreach ($violations as $violation) {
            $lines[] = sprintf(
                '  - Line %d, Col %d: %s',
                $violation->getLine(),
                $violation->getColumn(),
                $violation->message
            );
        }

        return implode("\n", $lines);
    }
}
