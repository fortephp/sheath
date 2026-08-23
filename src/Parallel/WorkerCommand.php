<?php

declare(strict_types=1);

namespace Forte\Sheath\Parallel;

use Forte\Sheath\Parallel\Contracts\WorkerConfig;
use Forte\Sheath\Parallel\Contracts\WorkerProcessor;
use Forte\Sheath\Parallel\Transport\SocketTransport;
use Forte\Sheath\Serialization\InternalDataCodec;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

/** @internal */
abstract class WorkerCommand extends Command
{
    protected $hidden = true;

    /**
     * @var resource|null
     */
    private $workerInput;

    /**
     * @var resource|null
     */
    private $workerOutput;

    abstract protected function getProcessor(): WorkerProcessor;

    /**
     * @return class-string<WorkerConfig>
     */
    abstract protected function getConfigClass(): string;

    /**
     * @throws JsonException
     */
    public function handle(): int
    {
        // Endpoints come first: once they are open, every later failure can
        // be reported to the master over the protocol itself.
        if (! $this->openEndpoints()) {
            return self::FAILURE;
        }

        $configClass = $this->getConfigClass();
        $processor = $this->getProcessor();

        $configBase64 = $this->argument('config');
        if (! is_string($configBase64)) {
            $this->writeError('Invalid config argument');
            $this->closeEndpoints();

            return self::FAILURE;
        }

        try {
            /** @var array<string, mixed> $configData */
            $configData = json_decode(base64_decode($configBase64), true, 512, JSON_THROW_ON_ERROR);
            $config = $configClass::fromArray($configData);
        } catch (Throwable $e) {
            $this->writeError('Failed to decode config: '.$e->getMessage());
            $this->closeEndpoints();

            return self::FAILURE;
        }

        $input = $this->workerInput;
        if ($input === null) {
            return self::FAILURE;
        }

        while (($line = fgets($input)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            try {
                $message = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $e) {
                $this->writeError('Invalid JSON: '.$e->getMessage());

                continue;
            }

            if (! is_array($message)) {
                $this->writeError('Invalid input: expected object');

                continue;
            }

            if (isset($message['files']) && is_array($message['files'])) {
                /** @var array<mixed> $files */
                $files = $message['files'];
                foreach ($files as $file) {
                    if (! is_string($file)) {
                        continue;
                    }

                    $this->processFile($file, $config, $processor);
                }
            }

            if (isset($message['shutdown']) && $message['shutdown'] === true) {
                break;
            }
        }

        $this->writeOutput(['complete' => true]);
        $this->closeEndpoints();

        return self::SUCCESS;
    }

    private function openEndpoints(): bool
    {
        $address = getenv(SocketTransport::ENV_SOCKET);
        $token = getenv(SocketTransport::ENV_TOKEN);

        if (is_string($address) && $address !== '' && is_string($token) && $token !== '') {
            return $this->openSocketEndpoints($address, $token);
        }

        $stdin = fopen('php://stdin', 'r');
        if ($stdin === false) {
            fwrite(STDERR, "Failed to open stdin\n");

            return false;
        }

        stream_set_blocking($stdin, true);

        $this->workerInput = $stdin;
        $this->workerOutput = STDOUT;

        return true;
    }

    private function openSocketEndpoints(string $address, string $token): bool
    {
        $errno = 0;
        $errstr = '';

        $socket = @stream_socket_client($address, $errno, $errstr, 10.0);

        if ($socket === false) {
            // Stdout leads to the null device in socket mode; stderr is
            // captured to a file the master surfaces on failure.
            fwrite(STDERR, "Failed to connect to parallel transport at {$address}: {$errstr} ({$errno})\n");

            return false;
        }

        stream_set_blocking($socket, true);

        try {
            $handshake = json_encode(['hello' => $token], JSON_THROW_ON_ERROR)."\n";
        } catch (JsonException $e) {
            fwrite(STDERR, 'Failed to encode handshake: '.$e->getMessage()."\n");
            fclose($socket);

            return false;
        }

        if (fwrite($socket, $handshake) === false) {
            fwrite(STDERR, "Failed to send handshake\n");
            fclose($socket);

            return false;
        }

        fflush($socket);

        $this->workerInput = $socket;
        $this->workerOutput = $socket;

        return true;
    }

    private function closeEndpoints(): void
    {
        if (is_resource($this->workerInput)) {
            fclose($this->workerInput);
        }

        // In socket mode input and output are the same resource; in stdio
        // mode the output is STDOUT, which is not ours to close.
        if (
            $this->workerOutput !== $this->workerInput
            && $this->workerOutput !== STDOUT
            && is_resource($this->workerOutput)
        ) {
            fclose($this->workerOutput);
        }

        $this->workerInput = null;
        $this->workerOutput = null;
    }

    /**
     * @throws JsonException
     */
    protected function processFile(
        string $file,
        WorkerConfig $config,
        WorkerProcessor $processor,
    ): void {
        try {
            $result = $processor->process($file, $config);
            $this->writeOutput(['result' => InternalDataCodec::encode($result->toArray())]);
        } catch (Throwable $e) {
            $this->writeError($e->getMessage(), $file);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws JsonException
     */
    protected function writeOutput(array $data): void
    {
        $output = $this->workerOutput ?? STDOUT;
        $payload = json_encode($data, JSON_THROW_ON_ERROR)."\n";
        $offset = 0;
        $length = strlen($payload);

        while ($offset < $length) {
            $chunk = substr($payload, $offset, 64 * 1024);
            $written = fwrite($output, $chunk);

            if ($written === false || $written === 0) {
                throw new RuntimeException('Failed to write worker output.');
            }

            $offset += $written;
        }

        if (! fflush($output)) {
            throw new RuntimeException('Failed to flush worker output.');
        }
    }

    /**
     * @throws JsonException
     */
    protected function writeError(string $message, ?string $file = null): void
    {
        $error = ['error' => $message];

        if ($file !== null) {
            $error['file'] = $file;
        }

        $this->writeOutput($error);
    }
}
