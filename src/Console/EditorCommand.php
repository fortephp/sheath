<?php

declare(strict_types=1);

namespace Forte\Sheath\Console;

use Forte\Sheath\Baselines\Baseline;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Console\Concerns\WritesNotices;
use Forte\Sheath\Console\Handlers\BaselineHandler;
use Forte\Sheath\Console\Handlers\ConfigHandler;
use Forte\Sheath\Exceptions\BaselineException;
use Forte\Sheath\Exceptions\ConfigurationException;
use Forte\Sheath\Exceptions\ReporterNotFoundException;
use Forte\Sheath\Files\PathResolver;
use Forte\Sheath\Linter;
use Forte\Sheath\Reporters\JsonReporter;
use Forte\Sheath\Reporters\ReporterRegistry;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Rules\RuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * @internal
 */
class EditorCommand extends Command
{
    use WritesNotices;

    public const PROTOCOL_VERSION = 1;

    protected $signature = 'sheath:editor
                            {--config= : Path to configuration file}
                            {--baseline= : Use baseline file to ignore known violations}
                            {--ignore-baseline : Run without baseline filtering}';

    protected $description = 'Run the persistent Sheath editor protocol.';

    protected $hidden = true;

    public function __construct(
        private readonly ReporterRegistry $reporterRegistry,
        private readonly RuleRegistry $ruleRegistry,
        private readonly Linter $linter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $config = $this->resolveEditorConfig();
            $reporter = $this->reporterRegistry->get('json');

            if (! $reporter instanceof JsonReporter) {
                throw new InvalidArgumentException('The Sheath JSON reporter is unavailable.');
            }

            $this->writeEditorMessage([
                'type' => 'ready',
                'protocolVersion' => self::PROTOCOL_VERSION,
                'reportSchemaVersion' => JsonReporter::SCHEMA_VERSION,
            ]);

            foreach ($this->readEditorMessages() as $message) {
                if (($message['type'] ?? null) === 'shutdown') {
                    return self::SUCCESS;
                }

                $this->handleEditorMessage($message, $config, $reporter);
            }

            return self::SUCCESS;
        } catch (BaselineException|ConfigurationException|InvalidArgumentException|ReporterNotFoundException $exception) {
            $this->noticeError($exception->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * @throws Throwable
     * @throws ConfigurationException
     */
    private function resolveEditorConfig(): Config
    {
        $handler = new ConfigHandler($this, $this->ruleRegistry);
        $configPath = $this->option('config');
        $config = $handler->load(is_string($configPath) ? $configPath : null);
        $this->ruleRegistry->setPackageRequirementMode($config->getPackageRequirementMode());

        return $handler->applyOverrides($config, null, null);
    }

    /**
     * @param  array<string, mixed>  $message
     *
     * @throws JsonException
     */
    private function handleEditorMessage(
        array $message,
        Config $config,
        JsonReporter $reporter,
    ): void {
        $id = $message['id'] ?? null;

        try {
            if (! is_string($id) || $id === '') {
                throw new InvalidArgumentException('Editor request id must be a non-empty string.');
            }

            $filePath = $message['filePath'] ?? null;
            $source = $message['source'] ?? null;
            if (! is_string($filePath) || $filePath === '') {
                throw new InvalidArgumentException('Editor request filePath must be a non-empty string.');
            }
            if (! is_string($source)) {
                throw new InvalidArgumentException('Editor request source must be a string.');
            }

            $result = $this->linter->lint($source, $filePath, $config);
            $results = $this->applyEditorBaseline($result, $source, $config);

            $this->writeEditorMessage([
                'type' => 'result',
                'id' => $id,
                'report' => $reporter->report($results),
            ]);
        } catch (Throwable $exception) {
            $this->writeEditorMessage([
                'type' => 'error',
                'id' => is_string($id) ? $id : null,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @return array<LintResult>
     *
     * @throws BaselineException
     */
    private function applyEditorBaseline(
        LintResult $result,
        string $source,
        Config $config,
    ): array {
        if ($this->option('ignore-baseline')) {
            return [$result];
        }

        $baselineOption = $this->option('baseline');
        $baselinePath = is_string($baselineOption) && $baselineOption !== ''
            ? PathResolver::toAbsolutePath($baselineOption)
            : base_path(Baseline::DEFAULT_PATH);

        if (! file_exists($baselinePath)) {
            return [$result];
        }

        $handler = new BaselineHandler(
            $this,
            $baselinePath,
            $config->getBaselineLineTolerance(),
        );
        $handler->preloadFileContent($result->filePath, $source);

        return $handler->applyBaseline([$result]);
    }

    /**
     * @return iterable<array<string, mixed>>
     *
     * @throws JsonException
     */
    protected function readEditorMessages(): iterable
    {
        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            try {
                $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                $this->writeEditorMessage([
                    'type' => 'error',
                    'id' => null,
                    'message' => 'Invalid editor request JSON: '.$exception->getMessage(),
                ]);

                continue;
            }

            if (! is_array($message) || array_is_list($message)) {
                $this->writeEditorMessage([
                    'type' => 'error',
                    'id' => null,
                    'message' => 'Editor request must be a JSON object.',
                ]);

                continue;
            }

            /** @var array<string, mixed> $message */
            yield $message;
        }
    }

    /**
     * @param  array<string, mixed>  $message
     *
     * @throws JsonException
     */
    protected function writeEditorMessage(array $message): void
    {
        $payload = json_encode(
            $message,
            JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
        );
        $this->getOutput()->writeln($payload, OutputInterface::OUTPUT_RAW);
    }

    protected function noticeOutput(): OutputStyle
    {
        return $this->getOutput();
    }
}
