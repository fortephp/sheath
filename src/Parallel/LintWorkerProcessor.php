<?php

declare(strict_types=1);

namespace Forte\Sheath\Parallel;

use Forte\Parser\ParserOptions;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Files\FileSystem;
use Forte\Sheath\Linter;
use Forte\Sheath\Packages\Dependencies;
use Forte\Sheath\Parallel\Contracts\WorkerConfig;
use Forte\Sheath\Parallel\Contracts\WorkerProcessor;
use Forte\Sheath\Parallel\Contracts\WorkerResult;
use Forte\Sheath\Parsing\IgnoredRegionRegistry;
use Forte\Sheath\Rules\RuleRegistry;
use InvalidArgumentException;
use RuntimeException;

/** @internal */
readonly class LintWorkerProcessor implements WorkerProcessor
{
    private Linter $linter;

    public function __construct(
        private RuleRegistry $ruleRegistry,
        ?Dependencies $dependencies = null,
        ?ParserOptions $parserOptions = null,
        ?IgnoredRegionRegistry $ignoredRegionRegistry = null,
    ) {
        $this->linter = new Linter(
            $this->ruleRegistry,
            $dependencies,
            $parserOptions,
            $ignoredRegionRegistry,
        );
    }

    public function process(string $filePath, WorkerConfig $config): WorkerResult
    {
        if (! $config instanceof Config) {
            throw new InvalidArgumentException('LintWorkerProcessor requires a '.Config::class.' worker configuration.');
        }

        $this->ruleRegistry->setPackageRequirementMode($config->getPackageRequirementMode());

        $content = FileSystem::readFile($filePath);
        if ($content === null) {
            throw new RuntimeException("Failed to read file: {$filePath}");
        }

        return $this->linter->lint($content, $filePath, $config);
    }
}
