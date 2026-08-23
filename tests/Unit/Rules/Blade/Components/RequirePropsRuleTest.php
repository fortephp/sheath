<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Components\RequirePropsRule;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Component;

class StandaloneAlertComponent extends Component
{
    public function render(): string
    {
        return 'components.standalone-alert';
    }
}

describe('RequirePropsRule', function (): void {
    it('passes for non-component files', function (): void {
        $this->getRuleTester()->run(new RequirePropsRule, [
            'valid' => [
                '<div>Regular view content</div>',
                [
                    'code' => '<div>{{ $slot }}</div>',
                    'path' => 'resources/views/notcomponents/card.blade.php',
                ],
                [
                    'code' => '<div>{{ $slot }}</div>',
                    'path' => 'resources/views/my-components/card.blade.php',
                ],
                [
                    'code' => '<div>{{ $slot }}</div>',
                    'path' => 'resources/views/components-old/card.blade.php',
                ],
            ],
        ]);
    });

    it('passes for component files with @props', function (): void {
        $this->getRuleTester()->run(new RequirePropsRule, [
            'valid' => [
                [
                    'code' => '@props([\'type\' => \'button\'])<button type="{{ $type }}">{{ $slot }}</button>',
                    'path' => 'resources/views/components/button.blade.php',
                ],
                [
                    'code' => "@props(['class' => '', 'disabled' => false])\n<div {{ \$attributes->class([\$class]) }}>{{ \$slot }}</div>",
                    'path' => 'resources/views/components/card.blade.php',
                ],
            ],
        ]);
    });

    it('recognizes conventionally discovered class components without a booted container', function (): void {
        if (! class_exists('App\\View\\Components\\StandaloneAlert', false)) {
            class_alias(StandaloneAlertComponent::class, 'App\\View\\Components\\StandaloneAlert');
        }

        $container = Container::getInstance();
        Container::setInstance();

        try {
            $this->getRuleTester()->run(new RequirePropsRule, [
                'valid' => [[
                    'code' => '<div>{{ $slot }}</div>',
                    'path' => 'resources/views/components/standalone-alert.blade.php',
                ]],
            ]);
        } finally {
            Container::setInstance($container);
        }
    });

    it('uses the booted application namespace for conventional class components', function (): void {
        if (! class_exists('Acme\\View\\Components\\StandaloneAlert', false)) {
            class_alias(StandaloneAlertComponent::class, 'Acme\\View\\Components\\StandaloneAlert');
        }

        $application = app();
        $namespacedApplication = Mockery::mock(ApplicationContract::class);
        $namespacedApplication->shouldReceive('getNamespace')->andReturn('Acme\\');
        $application->instance(ApplicationContract::class, $namespacedApplication);

        try {
            $this->getRuleTester()->run(new RequirePropsRule, [
                'valid' => [[
                    'code' => '<div>{{ $slot }}</div>',
                    'path' => 'resources/views/components/standalone-alert.blade.php',
                ]],
            ]);
        } finally {
            $application->instance(ApplicationContract::class, $application);
        }
    });

    it('recognizes component aliases from a booted Blade compiler', function (): void {
        /** @var BladeCompiler $bladeCompiler */
        $bladeCompiler = app('blade.compiler');
        $bladeCompiler->component(StandaloneAlertComponent::class, 'registered-alert');

        $this->getRuleTester()->run(new RequirePropsRule, [
            'valid' => [[
                'code' => '<div>{{ $slot }}</div>',
                'path' => 'resources/views/components/registered-alert.blade.php',
            ]],
        ]);
    });

    it('fails for component files without @props', function (): void {
        $this->getRuleTester()->run(new RequirePropsRule, [
            'invalid' => [
                [
                    'code' => '<div class="{{ $class }}">{{ $slot }}</div>',
                    'path' => 'resources/views/components/card.blade.php',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for an empty anonymous component file', function (): void {
        $this->getRuleTester()->run(new RequirePropsRule, [
            'invalid' => [[
                'code' => '',
                'path' => 'resources/views/components/empty.blade.php',
                'errors' => [[

                    'line' => 1,
                    'column' => 1,
                ]],
            ]],
        ]);
    });

    it('fails for component files in Components directory', function (): void {
        $this->getRuleTester()->run(new RequirePropsRule, [
            'invalid' => [
                [
                    'code' => '<button>{{ $slot }}</button>',
                    'path' => 'resources/views/Components/button.blade.php',
                    'errors' => 1,
                ],
                [
                    'code' => '<button>{{ $slot }}</button>',
                    'path' => 'resources\\views\\components\\button.blade.php',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('respects custom componentPaths option', function (): void {
        $rule = new RequirePropsRule;
        $rule->setOptions(['componentPaths' => ['custom-components/']]);

        $this->getRuleTester()->run($rule, [
            'invalid' => [
                [
                    'code' => '<div>{{ $slot }}</div>',
                    'path' => 'views/custom-components/card.blade.php',
                    'errors' => 1,
                ],
            ],
            'valid' => [
                [
                    'code' => '<div>{{ $slot }}</div>',
                    'path' => 'resources/views/components/card.blade.php',
                ],
                [
                    'code' => '<div>{{ $slot }}</div>',
                    'path' => 'views/notcustom-components/card.blade.php',
                ],
            ],
        ]);
    });

    it('matches custom nested component paths on directory boundaries', function (): void {
        $rule = new RequirePropsRule;
        $rule->setOptions(['componentPaths' => ['partials\\components']]);

        $this->getRuleTester()->run($rule, [
            'invalid' => [[
                'code' => '<div>{{ $slot }}</div>',
                'path' => 'resources/views/partials/components/card.blade.php',
                'errors' => 1,
            ]],
            'valid' => [[
                'code' => '<div>{{ $slot }}</div>',
                'path' => 'resources/views/partials/components-old/card.blade.php',
            ]],
        ]);
    });
});
