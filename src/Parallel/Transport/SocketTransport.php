<?php

declare(strict_types=1);

namespace Forte\Sheath\Parallel\Transport;

use Exception;
use JsonException;
use Random\RandomException;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Stream\DuplexResourceStream;
use React\Stream\ThroughStream;
use RuntimeException;

/**
 * @internal
 */
class SocketTransport implements Transport
{
    public const ENV_SOCKET = 'SHEATH_WORKER_SOCKET';

    public const ENV_TOKEN = 'SHEATH_WORKER_TOKEN';

    private const HANDSHAKE_MAX_BYTES = 8192;

    /** @var resource|null */
    private $server;

    private ?string $address = null;

    private ?LoopInterface $loop = null;

    /**
     * @var array<string, array{
     *     channel: WorkerChannel,
     *     readable: ThroughStream,
     *     writable: DeferredWritableStream,
     *     timer: TimerInterface,
     *     process: Process,
     * }>
     */
    private array $pending = [];

    /**
     * @var array<int, DuplexResourceStream>
     */
    private array $handshaking = [];

    /**
     * @var array<int, DuplexResourceStream>
     */
    private array $connections = [];

    /** @var array<string> */
    private array $stderrFiles = [];

    public function __construct(
        private readonly float $connectTimeout = 10.0,
    ) {}

    /**
     * @throws RandomException
     */
    public function spawn(string $command, LoopInterface $loop): WorkerChannel
    {
        $this->ensureServer($loop);

        if ($this->address === null) {
            throw new RuntimeException('Socket transport has no listening address');
        }

        $token = bin2hex(random_bytes(16));
        $stderrFile = tempnam(sys_get_temp_dir(), 'sheath-worker-');

        if ($stderrFile === false) {
            throw new RuntimeException('Failed to create worker stderr temp file');
        }

        $this->stderrFiles[] = $stderrFile;

        $nullDevice = DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';

        $process = new Process($command, null, $this->buildEnvironment($this->address, $token), [
            ['file', $nullDevice, 'r'],
            ['file', $nullDevice, 'w'],
            ['file', $stderrFile, 'a'],
        ]);

        // Without pipes there are no close events to detect exit from, so
        // react falls back to polling proc_get_status at this interval.
        $process->start($loop, 0.1);

        $readable = new ThroughStream;
        $writable = new DeferredWritableStream;

        $channel = new WorkerChannel($process, $writable, $readable, null, $stderrFile);

        $timer = $loop->addTimer($this->connectTimeout, function () use ($token): void {
            $this->handleConnectTimeout($token);
        });

        $this->pending[$token] = [
            'channel' => $channel,
            'readable' => $readable,
            'writable' => $writable,
            'timer' => $timer,
            'process' => $process,
        ];

        return $channel;
    }

    public function close(): void
    {
        foreach ($this->pending as $entry) {
            $this->loop?->cancelTimer($entry['timer']);
        }
        $this->pending = [];

        foreach ($this->handshaking as $stream) {
            $stream->close();
        }
        $this->handshaking = [];

        foreach ($this->connections as $stream) {
            $stream->close();
        }
        $this->connections = [];

        if ($this->server !== null) {
            $this->loop?->removeReadStream($this->server);
            fclose($this->server);
            $this->server = null;
        }

        $this->address = null;
        $this->loop = null;

        foreach ($this->stderrFiles as $file) {
            @unlink($file);
        }
        $this->stderrFiles = [];
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    /**
     * The full parent environment must be merged in because proc_open()
     * replaces it when one is provided.
     *
     * @return array<string, string>
     */
    public function buildEnvironment(string $address, string $token): array
    {
        /** @var array<string, string> $env */
        $env = getenv();

        $env[self::ENV_SOCKET] = $address;
        $env[self::ENV_TOKEN] = $token;

        return $env;
    }

    /**
     * @throws Exception
     */
    private function ensureServer(LoopInterface $loop): void
    {
        if ($this->server !== null) {
            return;
        }

        $errno = 0;
        $errstr = '';

        // Loopback only: workers are local child processes, and nothing
        // else should ever be able to reach this socket.
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);

        if ($server === false) {
            throw new RuntimeException("Failed to open worker socket: {$errstr} ({$errno})");
        }

        stream_set_blocking($server, false);

        $name = stream_socket_get_name($server, false);

        if ($name === false) {
            fclose($server);

            throw new RuntimeException('Failed to resolve worker socket address');
        }

        $this->server = $server;
        $this->address = 'tcp://'.$name;
        $this->loop = $loop;

        $loop->addReadStream($server, function (): void {
            $this->acceptConnection();
        });
    }

    private function acceptConnection(): void
    {
        if ($this->server === null || $this->loop === null) {
            return;
        }

        $conn = @stream_socket_accept($this->server, 0.0);

        if ($conn === false) {
            return;
        }

        $stream = new DuplexResourceStream($conn, $this->loop);
        $id = spl_object_id($stream);
        $this->handshaking[$id] = $stream;

        $buffer = '';

        $handler = function (mixed $data) use (&$buffer, &$handler, $stream, $id): void {
            if (! is_string($data)) {
                return;
            }

            $buffer .= $data;
            $newline = strpos($buffer, "\n");

            if ($newline === false) {
                if (strlen($buffer) > self::HANDSHAKE_MAX_BYTES) {
                    unset($this->handshaking[$id]);
                    $stream->close();
                }

                return;
            }

            $stream->removeListener('data', $handler);
            unset($this->handshaking[$id]);

            $this->completeHandshake(
                $stream,
                substr($buffer, 0, $newline),
                substr($buffer, $newline + 1),
            );
        };

        $stream->on('data', $handler);

        $stream->on('close', function () use ($id): void {
            unset($this->handshaking[$id], $this->connections[$id]);
        });
    }

    private function completeHandshake(DuplexResourceStream $stream, string $line, string $rest): void
    {
        try {
            $decoded = json_decode($line, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $stream->close();

            return;
        }

        if (! is_array($decoded) || ! isset($decoded['hello']) || ! is_string($decoded['hello'])) {
            $stream->close();

            return;
        }

        $entry = null;
        $matchedToken = null;

        foreach ($this->pending as $token => $candidate) {
            if (hash_equals($token, $decoded['hello'])) {
                $entry = $candidate;
                $matchedToken = $token;

                break;
            }
        }

        if ($entry === null || $matchedToken === null) {
            $stream->close();

            return;
        }

        unset($this->pending[$matchedToken]);
        $this->loop?->cancelTimer($entry['timer']);

        $this->connections[spl_object_id($stream)] = $stream;

        // Anything the worker sent after its handshake line is protocol
        // data and must reach the decoder before the piped stream flows.
        if ($rest !== '') {
            $entry['readable']->write($rest);
        }

        $stream->pipe($entry['readable']);
        $entry['writable']->attach($stream);
    }

    private function handleConnectTimeout(string $token): void
    {
        if (! isset($this->pending[$token])) {
            return;
        }

        $entry = $this->pending[$token];
        unset($this->pending[$token]);

        $message = sprintf(
            'Worker did not connect to the parallel transport within %.1fs',
            $this->connectTimeout,
        );

        $stderr = $entry['channel']->readStderr();
        if ($stderr !== '') {
            $message .= ': '.$stderr;
        }

        $entry['channel']->emit('error', [$message]);

        $entry['readable']->close();
        $entry['writable']->close();
        $entry['process']->terminate();
    }
}
