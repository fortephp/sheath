<?php

declare(strict_types=1);

namespace Forte\Sheath\Console;

use Forte\Parser\ParserOptions;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Packages\Dependencies;
use Forte\Sheath\Parallel\Contracts\WorkerConfig;
use Forte\Sheath\Parallel\Contracts\WorkerProcessor;
use Forte\Sheath\Parallel\LintWorkerProcessor;
use Forte\Sheath\Parallel\WorkerCommand as BaseWorkerCommand;
use Forte\Sheath\Parsing\IgnoredRegionRegistry;
use Forte\Sheath\Rules\RuleRegistry;

/**
 * @internal
 */
class WorkerCommand extends BaseWorkerCommand
{
    protected $signature = 'sheath:worker
                            {config : Base64-encoded configuration}';

    protected $description = 'Internal worker for parallel linting.';

    public function __construct(
        protected RuleRegistry $ruleRegistry,
        protected ?Dependencies $dependencies = null,
        protected ?ParserOptions $parserOptions = null,
        protected ?IgnoredRegionRegistry $ignoredRegionRegistry = null,
    ) {
        parent::__construct();
    }

    protected function getProcessor(): WorkerProcessor
    {
        return new LintWorkerProcessor(
            $this->ruleRegistry,
            $this->dependencies,
            $this->parserOptions,
            $this->ignoredRegionRegistry,
        );
    }

    /**
     * @return class-string<WorkerConfig>
     */
    protected function getConfigClass(): string
    {
        return Config::class;
    }
}
