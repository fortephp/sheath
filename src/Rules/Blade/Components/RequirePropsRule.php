<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Components;

use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application as ApplicationContract;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Compilers\ComponentTagCompiler;
use Throwable;

/** @internal */
class RequirePropsRule extends AbstractRule
{
    /**
     * @var array<string>
     */
    private const DEFAULT_COMPONENT_PATHS = [
        'components/',
        'Components/',
        '/components/',
        '/Components/',
    ];

    protected array $options = [
        'componentPaths' => self::DEFAULT_COMPONENT_PATHS,
    ];

    public function getId(): string
    {
        return 'blade-require-props';
    }

    public function getDescription(): string
    {
        return 'Anonymous Blade components require @props.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::INFO;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $filePath = $document->getFilePath();

        if ($filePath === null) {
            return;
        }

        if (! $this->isComponentFile($filePath)) {
            return;
        }

        if ($this->isClassBasedComponent($filePath)) {
            return;
        }

        $hasProps = $this->hasPropsDirective($document);

        if (! $hasProps) {
            $firstChild = $document->firstChild();
            if ($firstChild === null) {
                $position = new Position(0, 1, 1);
                $context->reportAt(
                    $position,
                    $position,
                    'Anonymous Blade component is missing @props.'
                );

                return;
            }

            $context->report(
                $firstChild,
                'Anonymous Blade component is missing @props.'
            );
        }
    }

    private function isComponentFile(string $filePath): bool
    {
        $normalizedPath = '/'.trim(str_replace('\\', '/', $filePath), '/').'/';

        $componentPathsOption = $this->getOption('componentPaths', self::DEFAULT_COMPONENT_PATHS);
        /** @var array<string> $componentPaths */
        $componentPaths = is_array($componentPathsOption) ? $componentPathsOption : self::DEFAULT_COMPONENT_PATHS;

        foreach ($componentPaths as $componentPath) {
            $normalizedComponentPath = trim(str_replace('\\', '/', $componentPath), '/');

            if ($normalizedComponentPath === '') {
                continue;
            }

            if (str_contains($normalizedPath, '/'.$normalizedComponentPath.'/')) {
                return true;
            }
        }

        return false;
    }

    private function isClassBasedComponent(string $filePath): bool
    {
        $componentName = $this->extractComponentName($filePath);

        if ($componentName === null) {
            return false;
        }

        return $this->hasComponentClass($componentName);
    }

    private function extractComponentName(string $filePath): ?string
    {
        $normalizedPath = str_replace('\\', '/', $filePath);

        if (! preg_match('#/views/components/(.+)\.blade\.php$#i', $normalizedPath, $matches)) {
            return null;
        }

        return str_replace('/', '.', $matches[1]);
    }

    private function hasComponentClass(string $componentName): bool
    {
        if (! class_exists(ComponentTagCompiler::class)) {
            return false;
        }

        $applicationNamespace = 'App\\';

        try {
            $container = Container::getInstance();
            if ($container !== null && $container->bound(ApplicationContract::class)) {
                $application = $container->make(ApplicationContract::class);
                if ($application instanceof ApplicationContract) {
                    $applicationNamespace = rtrim($application->getNamespace(), '\\').'\\';
                }
            }
        } catch (Throwable) {
            // Standalone linting retains Laravel's conventional App namespace.
        }

        $defaultClass = $this->resolveClassName($applicationNamespace.'View\\Components', $componentName);
        if (class_exists($defaultClass)) {
            return true;
        }

        try {
            /** @var BladeCompiler $bladeCompiler */
            $bladeCompiler = app('blade.compiler');
            $aliases = $bladeCompiler->getClassComponentAliases();

            if (isset($aliases[$componentName])) {
                return true;
            }

            $namespaces = $bladeCompiler->getClassComponentNamespaces();

            foreach ($namespaces as $prefix => $namespace) {
                if (! is_string($prefix) || ! is_string($namespace)) {
                    continue;
                }

                if ($prefix === '' || str_starts_with($componentName, $prefix.'.')) {
                    $relativeName = $prefix === '' ? $componentName : substr($componentName, strlen($prefix) + 1);
                    $className = $this->resolveClassName($namespace, $relativeName);

                    if (class_exists($className)) {
                        return true;
                    }
                }
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    private function resolveClassName(string $namespace, string $componentName): string
    {
        $parts = explode('.', $componentName);
        $parts = array_map(fn (string $part): string => str_replace(' ', '', ucwords(str_replace('-', ' ', $part))), $parts);

        return $namespace.'\\'.implode('\\', $parts);
    }

    private function hasPropsDirective(Document $document): bool
    {
        $found = false;

        $document->queryDirectives()->each(function (DirectiveNode $directive) use (&$found): void {
            if ($directive->nameText() === 'props') {
                $found = true;
            }
        });

        return $found;
    }
}
