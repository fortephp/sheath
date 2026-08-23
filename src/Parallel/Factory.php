<?php

declare(strict_types=1);

namespace Forte\Sheath\Parallel;

use Forte\Sheath\Parallel\Contracts\WorkerConfig;
use Forte\Sheath\Parallel\Contracts\WorkerResult;
use Forte\Sheath\Parallel\Transport\Transport;
use Forte\Sheath\Parallel\Transport\TransportSelector;
use JsonException;
use React\EventLoop\LoopInterface;

/** @internal */
class Factory
{
    public const CONFIG_PLACEHOLDER = '{config}';

    private readonly Transport $transport;

    private ?string $commandTemplate = null;

    /**
     * @param  class-string<WorkerResult>  $resultClass
     */
    public function __construct(
        private readonly string $resultClass,
        private readonly string $workerCommand,
    ) {
        $this->transport = TransportSelector::select();
    }

    /**
     * The template must contain the `{config}` placeholder.
     */
    public function withCommandTemplate(string $template): self
    {
        $this->commandTemplate = $template;

        return $this;
    }

    public function close(): void
    {
        $this->transport->close();
    }

    /**
     * @throws JsonException
     */
    public function create(WorkerConfig $config, LoopInterface $loop): WorkerProcess
    {
        $command = $this->buildCommand($config);
        $channel = $this->transport->spawn($command, $loop);

        return new WorkerProcess($channel, $this->resultClass);
    }

    /**
     * @throws JsonException
     */
    private function buildCommand(WorkerConfig $config): string
    {
        $configBase64 = base64_encode(json_encode($config->toArray(), JSON_THROW_ON_ERROR));
        $escapedConfig = $this->escapeArgument($configBase64);

        if ($this->commandTemplate !== null) {
            return str_replace(self::CONFIG_PLACEHOLDER, $escapedConfig, $this->commandTemplate);
        }

        $phpBinary = $this->getPhpBinary();
        $artisan = $this->getArtisanPath();

        return sprintf(
            '%s %s %s %s',
            $this->escapeArgument($phpBinary),
            $this->escapeArgument($artisan),
            $this->workerCommand,
            $escapedConfig
        );
    }

    private function getPhpBinary(): string
    {
        $binary = PHP_BINARY;

        if (PHP_OS_FAMILY === 'Windows') {
            if (stripos($binary, '.exe') === false && file_exists($binary.'.exe')) {
                $binary .= '.exe';
            }
        }

        return $binary;
    }

    private function getArtisanPath(): string
    {
        $possiblePaths = [
            base_path('artisan'),
            getcwd().'/artisan',
            dirname(__DIR__, 3).'/artisan',
        ];

        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                return realpath($path) ?: $path;
            }
        }

        return 'artisan';
    }

    private function escapeArgument(string $argument): string
    {
        if ($argument === '') {
            return '""';
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return '"'.str_replace('"', '""', $argument).'"';
        }

        return escapeshellarg($argument);
    }
}
