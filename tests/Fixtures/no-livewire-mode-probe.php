<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\RuleRegistry;
use Forte\Sheath\Support\ReactiveAttributeSemantics;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$registry = new RuleRegistry;
$registry->discoverRules(dirname(__DIR__, 2).'/src/Rules');
$linter = new Linter($registry);

$lint = (static fn (string $ruleId, string $source): array => $linter->lint(
    $source,
    'resources/views/reactive-no-livewire-probe.blade.php',
    Config::make([
        'preset' => 'empty',
        'rules' => [$ruleId => 'error'],
    ]),
)->violations);

$modelable = $lint(
    'blade-alpine-directive-integrity',
    '<input x-modelable="inner" wire:model="outer">',
);
$conflict = $lint(
    'blade-reactive-directive-conflicts',
    '<input x-model="inner" wire:model="outer">',
);

echo json_encode([
    'livewireInstalled' => ReactiveAttributeSemantics::livewireCompilerIsInstalled(),
    'modelableCount' => count($modelable),
    'conflictCount' => count($conflict),
], JSON_THROW_ON_ERROR);
