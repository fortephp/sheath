<?php

declare(strict_types=1);

require __DIR__.'/../../../vendor/autoload.php';
require_once __DIR__.'/MarkerIgnoredRegionProvider.php';

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Parallel\LintWorkerProcessor;
use Forte\Sheath\Parsing\IgnoredRegionRegistry;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Tests\Fixtures\Parallel\MarkerIgnoredRegionProvider;

$configData = [];
if (isset($argv[1]) && is_string($argv[1])) {
    $decoded = json_decode(base64_decode($argv[1]), true);
    if (is_array($decoded)) {
        $configData = $decoded;
    }
}

$config = Config::fromArray($configData);

$registry = new RuleRegistry;
$registry->discoverRules(__DIR__.'/../../../src/Rules');
$ignoredRegions = new IgnoredRegionRegistry;
$ignoredRegions->register(new MarkerIgnoredRegionProvider);
$processor = new LintWorkerProcessor($registry, ignoredRegionRegistry: $ignoredRegions);

$address = getenv('SHEATH_WORKER_SOCKET');
$token = getenv('SHEATH_WORKER_TOKEN');

if (is_string($address) && $address !== '' && is_string($token) && $token !== '') {
    $stream = @stream_socket_client($address, $errno, $errstr, 10.0);

    if ($stream === false) {
        fwrite(STDERR, "lint worker: connect failed: {$errstr} ({$errno})\n");
        exit(1);
    }

    stream_set_blocking($stream, true);
    fwrite($stream, json_encode(['hello' => $token])."\n");
    fflush($stream);

    $input = $stream;
    $output = $stream;
} else {
    $input = fopen('php://stdin', 'r');
    if ($input === false) {
        fwrite(STDERR, "lint worker: failed to open stdin\n");
        exit(1);
    }
    stream_set_blocking($input, true);
    $output = STDOUT;
}

$write = static function (array $data) use ($output): void {
    fwrite($output, json_encode($data)."\n");
    fflush($output);
};

while (($line = fgets($input)) !== false) {
    $line = trim($line);

    if ($line === '') {
        continue;
    }

    $message = json_decode($line, true);

    if (! is_array($message)) {
        $write(['error' => 'lint worker: invalid message']);

        continue;
    }

    if (isset($message['files']) && is_array($message['files'])) {
        foreach ($message['files'] as $file) {
            if (! is_string($file)) {
                continue;
            }

            try {
                $result = $processor->process($file, $config);
                $write(['result' => $result->toArray()]);
            } catch (Throwable $e) {
                $write(['error' => $e->getMessage(), 'file' => $file]);
            }
        }
    }

    if (isset($message['shutdown']) && $message['shutdown'] === true) {
        break;
    }
}

$write(['complete' => true]);
exit(0);
