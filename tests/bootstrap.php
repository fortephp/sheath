<?php

declare(strict_types=1);
use Illuminate\Filesystem\Filesystem;

require_once __DIR__.'/../vendor/autoload.php';

// Every PHPUnit/ParaTest process needs its own manifests before Testbench boots.
// Override inherited values too: child test processes must not reuse a parent's cache.
$cacheDirectory = sys_get_temp_dir().'/sheath-test-cache-'.getmypid().'-'.bin2hex(random_bytes(8));

if (! mkdir($cacheDirectory, 0755, true)) {
    throw new RuntimeException('Unable to create test cache directory: '.$cacheDirectory);
}

foreach (['APP_SERVICES_CACHE' => 'services.php', 'APP_PACKAGES_CACHE' => 'packages.php'] as $name => $file) {
    $path = $cacheDirectory.'/'.$file;
    $_ENV[$name] = $path;
    $_SERVER[$name] = $path;
    putenv($name.'='.$path);
}

register_shutdown_function(static function () use ($cacheDirectory): void {
    (new Filesystem)->deleteDirectory($cacheDirectory);
});
