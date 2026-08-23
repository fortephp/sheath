<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\DefaultConfigFactory;
use Forte\Sheath\Configuration\RulePreset;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\RuleRegistry;

describe('starter-kit component idioms', function (): void {
    it('stays quiet on shipped starter-kit components under recommended', function (string $template): void {
        $registry = new RuleRegistry;
        $registry->discoverRules(__DIR__.'/../../src/Rules');

        $config = DefaultConfigFactory::resolve(
            ['preset' => RulePreset::RECOMMENDED->value],
            $registry
        );

        $result = (new Linter($registry))->lint($template, 'component.blade.php', $config);

        expect($result->violations)->toBe([]);
    })->with([
        'breeze primary button' => <<<'BLADE'
<button {{ $attributes->merge(['type' => 'submit', 'class' => 'inline-flex items-center px-4 py-2 bg-gray-800 rounded-md font-semibold text-xs text-white uppercase tracking-widest']) }}>
    {{ $slot }}
</button>
BLADE,
        'breeze secondary button' => <<<'BLADE'
<button {{ $attributes->merge(['type' => 'button', 'class' => 'inline-flex items-center px-4 py-2 bg-white border border-gray-300 rounded-md']) }}>
    {{ $slot }}
</button>
BLADE,
        'breeze text input' => <<<'BLADE'
@props(['disabled' => false])

<input @disabled($disabled) {{ $attributes->merge(['class' => 'border-gray-300 focus:border-indigo-500 rounded-md shadow-sm']) }}>
BLADE,
        'breeze input label' => <<<'BLADE'
@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-medium text-sm text-gray-700']) }}>
    {{ $value ?? $slot }}
</label>
BLADE,
        'breeze dropdown link' => <<<'BLADE'
<a {{ $attributes->merge(['class' => 'block w-full px-4 py-2 text-start text-sm leading-5 text-gray-700']) }}>{{ $slot }}</a>
BLADE,
        'jetstream input with raw bag' => <<<'BLADE'
@props(['disabled' => false])

<input {{ $disabled ? 'disabled' : '' }} {!! $attributes->merge(['class' => 'border-gray-300 rounded-md shadow-sm']) !!}>
BLADE,
        'form component deferring its body to the slot' => <<<'BLADE'
<form method="POST" {{ $attributes }}>
    {{ $slot }}
</form>
BLADE,
    ]);
});
