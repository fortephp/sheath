<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

it('does not treat unavailable Livewire attributes as Alpine owners or conflicts', function (): void {
    $process = new Process([
        PHP_BINARY,
        dirname(__DIR__, 4).'/Fixtures/no-livewire-mode-probe.php',
    ]);
    $process->mustRun();

    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

    expect($result)
        ->toBe([
            'livewireInstalled' => false,
            'modelableCount' => 1,
            'conflictCount' => 0,
        ]);
});
