<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Php\NoPhpTagRule;
use Illuminate\Container\Container;

describe('NoPhpTagRule', function (): void {
    afterEach(function (): void {
        Container::getInstance()->offsetUnset('Livewire\\Volt\\MountedDirectories');
    });

    it('passes for templates using @php directive', function (): void {
        $this->getRuleTester()->run(new NoPhpTagRule, [
            'valid' => [
                '@php $x = 1; @endphp',
                '@php($x = 1)',
                '<div>{{ $variable }}</div>',
            ],
        ]);
    });

    it('fails for templates with <?php ?> tags', function (): void {
        $this->getRuleTester()->run(new NoPhpTagRule, [
            'invalid' => [
                [
                    'code' => '<?php $x = 1; ?>',
                    'errors' => 1,
                ],
                [
                    'code' => '<?= $x ?>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('detects multiple PHP tags', function (): void {
        $this->getRuleTester()->run(new NoPhpTagRule, [
            'invalid' => [
                [
                    'code' => '<?php $x = 1; ?><div><?php echo $x; ?></div>',
                    'errors' => 2,
                ],
            ],
        ]);
    });

    it('allows the leading class preamble of a statically identifiable Livewire component', function (): void {
        $this->getRuleTester()->run(new NoPhpTagRule, [
            'valid' => [
                <<<'BLADE'
                <?php

                use Livewire\Component;

                // The first executable new expression is the component class.
                new class extends Component {};
                ?>

                <div>Component</div>
                BLADE,
                <<<'BLADE'
                <?php

                return new class extends \Livewire\Component {};
                ?>

                <div>Component</div>
                BLADE,
                '<?php new class extends Livewire\\Component {}; ?><div>Component</div>',
                <<<'BLADE'
                <?php

                use Livewire\Component as LivewireComponent;

                new #[SomeAttribute] readonly class extends LivewireComponent {};
                ?>

                <div>Component</div>
                BLADE,
                '<?php use Livewire as LW; new class extends LW\\Component {}; ?><div>Component</div>',
                <<<'BLADE'
                <?php

                use Livewire\{Component as BaseComponent, Attributes\Layout};

                new #[Layout('layouts.app')] class extends BaseComponent {};
                ?>

                <div>Component</div>
                BLADE,
                "\xEF\xBB\xBF\n  <?php use Livewire\\Component; new class extends Component {}; ?>\n<div>Component</div>",
            ],
        ]);
    });

    it('does not infer a Livewire component from weak names or references', function (): void {
        $this->getRuleTester()->run(new NoPhpTagRule, [
            'invalid' => [
                [
                    'code' => '<?php use Livewire\\Component; $value = 1; ?><div wire:click="save"></div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<?php new class extends Component {}; ?><div></div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<?php new class extends Illuminate\\View\\Component {}; ?><div></div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<?php use Livewire\\Component; $factory = fn () => new class extends Component {}; ?><div></div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<?php use Livewire\\Component; $service = new Service; new class extends Component {}; ?><div></div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<?php namespace App; use Livewire\\Component; new class extends Component {}; ?><div></div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<!-- not leading --><?php use Livewire\\Component; new class extends Component {}; ?><div></div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<?php $value = 1; ?><div wire:click="save"></div>',
                    'path' => 'resources/views/pages/⚡settings.blade.php',
                    'errors' => 1,
                ],
                [
                    'code' => '<?= new class extends \\Livewire\\Component {} ?><div></div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('continues reporting later raw PHP inside a Livewire component view', function (): void {
        $this->getRuleTester()->run(new NoPhpTagRule, [
            'invalid' => [[
                'code' => <<<'BLADE'
                <?php

                use Livewire\Component;

                new class extends Component {};
                ?>

                <div><?php echo 'still raw'; ?></div>
                BLADE,
                'errors' => [[
                    'line' => 8,
                    'column' => 6,
                ]],
            ]],
        ]);
    });

    it('allows standard PHP blocks consumed by statically identifiable Volt components', function (): void {
        $this->getRuleTester()->run(new NoPhpTagRule, [
            'valid' => [
                <<<'BLADE'
                <?php

                use function Livewire\Volt\state;

                state(['count' => 0]);
                ?>

                <div>{{ $count }}</div>
                BLADE,
                <<<'BLADE'
                <?php use function Livewire\Volt\{mount, state}; ?>
                <div>
                    <?php state(['name' => 'Taylor']); ?>
                    <?php mount(fn () => null); ?>
                    {{ $name }}
                </div>
                BLADE,
                '<?php use Livewire\Volt\{function state}; ?><div>{{ state([\'ready\' => true]) }}</div>',
                <<<'BLADE'
                <?php

                $value = true;
                $initialize = function () use ($value) {
                    \Livewire\Volt\state(['ready' => $value]);
                };
                ?>

                <div>Nested API reference</div>
                BLADE,
                <<<'BLADE'
                <?php \Livewire\Volt\state(['count' => 0]); ?>
                <div>{{ $count }}</div>
                BLADE,
                <<<'BLADE'
                <?php

                use Livewire\Volt\Component;

                new class extends Component {};
                ?>

                <div>Class Volt component</div>
                BLADE,
                <<<'BLADE'
                <div>Template first</div>

                <?php

                use function Livewire\Volt\state;

                state(['ready' => true]);
                BLADE,
            ],
        ]);
    });

    it('does not infer a Volt component from namespace references alone', function (): void {
        $this->getRuleTester()->run(new NoPhpTagRule, [
            'invalid' => [
                [
                    'code' => '<?php use Livewire\\Volt\\Support; $value = 1; ?><div></div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<?php use function App\\Support\\state; $value = 1; ?><div></div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<?php use function Livewire\\Volt; $value = 1; ?><div></div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<?php $state = Livewire\\Volt\\state; ?><div></div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('uses the booted Volt mounted-directory registry as the authoritative boundary', function (): void {
        $mountedRoot = base_path('resources/views/volt-components');
        Container::getInstance()->instance(
            'Livewire\\Volt\\MountedDirectories',
            new class([$mountedRoot])
            {
                /** @param list<string> $paths */
                public function __construct(private readonly array $paths) {}

                /** @return list<object{path: string}> */
                public function paths(): array
                {
                    return array_map(
                        static fn (string $path): object => (object) ['path' => $path],
                        $this->paths,
                    );
                }
            },
        );

        $this->getRuleTester()->run(new NoPhpTagRule, [
            'valid' => [
                [
                    'code' => '<?php use App\\Models\\User; $save = fn () => User::first(); ?><div>Volt</div>',
                    'path' => $mountedRoot.'/profile.blade.php',
                ],
                [
                    'code' => '<?php $value = 1; ?><div>Volt prefix behavior</div>',
                    'path' => $mountedRoot.'-extra/also-consumed-by-volt.blade.php',
                ],
            ],
            'invalid' => [
                [
                    'code' => '<?php use function Livewire\\Volt\\state; state([\'x\' => 1]); ?><div></div>',
                    'path' => base_path('resources/views/ordinary.blade.php'),
                    'errors' => 1,
                ],
                [
                    'code' => '<?php $value = 1; ?><div></div>',
                    'path' => base_path('resources/views/not-volt/component.blade.php'),
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('continues reporting short echo tags inside Volt templates', function (): void {
        $mountedRoot = base_path('resources/views/volt-components');
        Container::getInstance()->instance(
            'Livewire\\Volt\\MountedDirectories',
            new class($mountedRoot)
            {
                public function __construct(private readonly string $path) {}

                /** @return list<object{path: string}> */
                public function paths(): array
                {
                    return [(object) ['path' => $this->path]];
                }
            },
        );

        $this->getRuleTester()->run(new NoPhpTagRule, [
            'invalid' => [[
                'code' => '<?= $value ?><div>Volt</div>',
                'path' => $mountedRoot.'/short-echo.blade.php',
                'errors' => 1,
            ]],
        ]);
    });

    it('fingerprints the sorted Volt mount registry for cached runs', function (): void {
        Container::getInstance()->instance(
            'Livewire\\Volt\\MountedDirectories',
            new class
            {
                /** @return list<object{path: string}> */
                public function paths(): array
                {
                    return [
                        (object) ['path' => base_path('resources/views/z-volt')],
                        (object) ['path' => base_path('resources/views/a-volt')],
                    ];
                }
            },
        );

        expect((new NoPhpTagRule)->cacheContext([]))->toMatchArray([
            'schema' => 1,
            'voltMountedPaths' => [
                str_replace('\\', '/', base_path('resources/views/a-volt')),
                str_replace('\\', '/', base_path('resources/views/z-volt')),
            ],
        ]);
    });
});
