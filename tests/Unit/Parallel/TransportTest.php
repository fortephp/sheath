<?php

declare(strict_types=1);

use Evenement\EventEmitter;
use Forte\Sheath\Parallel\Transport\DeferredWritableStream;
use Forte\Sheath\Parallel\Transport\PipesTransport;
use Forte\Sheath\Parallel\Transport\SocketTransport;
use Forte\Sheath\Parallel\Transport\TransportSelector;
use React\EventLoop\StreamSelectLoop;
use React\Stream\WritableStreamInterface;

class CollectingWritableStream extends EventEmitter implements WritableStreamInterface
{
    /** @var array<string> */
    public array $chunks = [];

    public bool $ended = false;

    public bool $closed = false;

    public function isWritable(): bool
    {
        return ! $this->closed && ! $this->ended;
    }

    public function write(mixed $data): bool
    {
        $this->chunks[] = is_string($data) ? $data : '';

        return true;
    }

    public function end(mixed $data = null): void
    {
        if ($data !== null) {
            $this->write($data);
        }

        $this->ended = true;
    }

    public function close(): void
    {
        $this->closed = true;
        $this->emit('close');
    }
}

function inlinePhpCommand(string $code): string
{
    if (PHP_OS_FAMILY === 'Windows') {
        return sprintf('"%s" -r "%s"', PHP_BINARY, $code);
    }

    return sprintf('%s -r %s', escapeshellarg(PHP_BINARY), escapeshellarg($code));
}

afterEach(function (): void {
    putenv(TransportSelector::ENV_TRANSPORT);
});

describe('TransportSelector', function (): void {
    it('defaults to pipes on POSIX systems', function (): void {
        expect(TransportSelector::select(null, 'Linux'))->toBeInstanceOf(PipesTransport::class);
        expect(TransportSelector::select(null, 'Darwin'))->toBeInstanceOf(PipesTransport::class);
    });

    it('defaults to sockets on Windows', function (): void {
        expect(TransportSelector::select(null, 'Windows'))->toBeInstanceOf(SocketTransport::class);
    });

    it('selects the platform default for the current OS', function (): void {
        $expected = PHP_OS_FAMILY === 'Windows' ? SocketTransport::class : PipesTransport::class;

        expect(TransportSelector::select())->toBeInstanceOf($expected);
    });

    it('honours an explicit override argument over the OS default', function (): void {
        expect(TransportSelector::select('socket', 'Linux'))->toBeInstanceOf(SocketTransport::class);
        expect(TransportSelector::select('pipes', 'Windows'))->toBeInstanceOf(PipesTransport::class);
    });

    it('honours the environment override', function (): void {
        putenv(TransportSelector::ENV_TRANSPORT.'=socket');
        expect(TransportSelector::select(null, 'Linux'))->toBeInstanceOf(SocketTransport::class);

        putenv(TransportSelector::ENV_TRANSPORT.'=pipes');
        expect(TransportSelector::select(null, 'Windows'))->toBeInstanceOf(PipesTransport::class);
    });

    it('rejects invalid override values', function (): void {
        expect(fn () => TransportSelector::select('smoke-signals'))
            ->toThrow(InvalidArgumentException::class);

        putenv(TransportSelector::ENV_TRANSPORT.'=carrier-pigeon');
        expect(fn () => TransportSelector::select())
            ->toThrow(InvalidArgumentException::class);
    });

    it('ignores an empty environment value', function (): void {
        putenv(TransportSelector::ENV_TRANSPORT.'=');

        $expected = PHP_OS_FAMILY === 'Windows' ? SocketTransport::class : PipesTransport::class;
        expect(TransportSelector::select())->toBeInstanceOf($expected);
    });
});

describe('SocketTransport environment', function (): void {
    it('merges the parent environment instead of replacing it', function (): void {
        putenv('SHEATH_TEST_SENTINEL=parent-value');

        try {
            $env = (new SocketTransport)->buildEnvironment('tcp://127.0.0.1:9', 'token-abc');

            expect($env['SHEATH_TEST_SENTINEL'])->toBe('parent-value');

            $keys = array_map(strtoupper(...), array_keys($env));
            expect($keys)->toContain('PATH');

            expect($env[SocketTransport::ENV_SOCKET])->toBe('tcp://127.0.0.1:9');
            expect($env[SocketTransport::ENV_TOKEN])->toBe('token-abc');
        } finally {
            putenv('SHEATH_TEST_SENTINEL');
        }
    });
});

describe('DeferredWritableStream', function (): void {
    it('buffers writes until a stream is attached, then flushes in order', function (): void {
        $deferred = new DeferredWritableStream;

        expect($deferred->write('one'))->toBeTrue();
        expect($deferred->write('two'))->toBeTrue();

        $inner = new CollectingWritableStream;
        expect($inner->chunks)->toBe([]);

        $deferred->attach($inner);
        expect($inner->chunks)->toBe(['one', 'two']);

        $deferred->write('three');
        expect($inner->chunks)->toBe(['one', 'two', 'three']);
    });

    it('forwards a pre-attach end after flushing the buffer', function (): void {
        $deferred = new DeferredWritableStream;
        $deferred->write('payload');
        $deferred->end();

        expect($deferred->isWritable())->toBeFalse();

        $inner = new CollectingWritableStream;
        $deferred->attach($inner);

        expect($inner->chunks)->toBe(['payload']);
        expect($inner->ended)->toBeTrue();
    });

    it('closes with the attached stream and refuses further writes', function (): void {
        $deferred = new DeferredWritableStream;
        $inner = new CollectingWritableStream;
        $deferred->attach($inner);

        $closed = false;
        $deferred->on('close', function () use (&$closed): void {
            $closed = true;
        });

        $inner->close();

        expect($closed)->toBeTrue();
        expect($deferred->isWritable())->toBeFalse();
        expect($deferred->write('late'))->toBeFalse();
    });
});

describe('SocketTransport handshake', function (): void {
    it('drops connections that present an unknown token', function (): void {
        $loop = new StreamSelectLoop;
        $transport = new SocketTransport(connectTimeout: 30.0);

        $channel = $transport->spawn(inlinePhpCommand('usleep(1500000);'), $loop);

        $received = [];
        $channel->getOutput()->on('data', function (mixed $data) use (&$received): void {
            $received[] = $data;
        });

        $address = $transport->getAddress();
        expect($address)->toStartWith('tcp://127.0.0.1:');

        $client = stream_socket_client((string) $address, $errno, $errstr, 5.0);
        expect($client)->not->toBeFalse();

        fwrite($client, json_encode(['hello' => 'not-the-token'])."\n");
        fflush($client);

        $loop->addTimer(0.5, fn () => $loop->stop());
        $loop->run();

        stream_set_blocking($client, false);
        expect(fread($client, 1024))->toBe('');
        expect(feof($client))->toBeTrue();

        expect($received)->toBe([]);

        fclose($client);
        $channel->getProcess()->terminate();
        $transport->close();
    });

    it('drops connections that send garbage instead of a handshake', function (): void {
        $loop = new StreamSelectLoop;
        $transport = new SocketTransport(connectTimeout: 30.0);

        $channel = $transport->spawn(inlinePhpCommand('usleep(1500000);'), $loop);

        $client = stream_socket_client((string) $transport->getAddress(), $errno, $errstr, 5.0);
        expect($client)->not->toBeFalse();

        fwrite($client, "this is not json\n");
        fflush($client);

        $loop->addTimer(0.5, fn () => $loop->stop());
        $loop->run();

        stream_set_blocking($client, false);
        expect(fread($client, 1024))->toBe('');
        expect(feof($client))->toBeTrue();

        fclose($client);
        $channel->getProcess()->terminate();
        $transport->close();
    });

    it('terminates and reports workers that never connect back', function (): void {
        $loop = new StreamSelectLoop;
        $transport = new SocketTransport(connectTimeout: 0.4);

        $channel = $transport->spawn(inlinePhpCommand('usleep(5000000);'), $loop);

        $errors = [];
        $channel->on('error', function (mixed $message) use (&$errors): void {
            $errors[] = $message;
        });

        $loop->addTimer(1.5, fn () => $loop->stop());
        $loop->run();

        expect($errors)->toHaveCount(1);

        $deadline = microtime(true) + 3.0;
        while ($channel->getProcess()->isRunning() && microtime(true) < $deadline) {
            usleep(50_000);
        }
        expect($channel->getProcess()->isRunning())->toBeFalse();

        $transport->close();
    });
});

describe('PipesTransport on Windows', function (): void {
    it('cannot spawn workers because react refuses pipes on Windows', function (): void {
        $loop = new StreamSelectLoop;

        expect(fn () => (new PipesTransport)->spawn(inlinePhpCommand('exit(0);'), $loop))
            ->toThrow(LogicException::class);
    })->skip(PHP_OS_FAMILY !== 'Windows', 'Pins down why sockets are the Windows default; pipes work elsewhere.');
});
