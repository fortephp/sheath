<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Blade\Components;

use Forte\Ast\Components\ComponentNode;
use Forte\Ast\DirectiveNode;
use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Analysis\ComponentRequiredPropsCache;
use Forte\Sheath\Contracts\ProvidesCacheContext;
use Forte\Sheath\Contracts\SharesRuleState;
use Forte\Sheath\Files\PathResolver;
use Forte\Sheath\Parsing\BladeParserOptions;
use Forte\Sheath\Parsing\PhpSource;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Illuminate\Support\Str;
use Illuminate\View\Compilers\BladeCompiler;
use Symfony\Component\Finder\Finder;
use Throwable;

/** @internal */
class ComponentRequiredPropsRule extends AbstractRule implements ProvidesCacheContext, SharesRuleState
{
    use DetectsOpaqueAttributes;

    public function __construct(
        private readonly ComponentRequiredPropsCache $requiredPropsCache = new ComponentRequiredPropsCache,
    ) {
        parent::__construct();
    }

    public function getId(): string
    {
        return 'blade-component-required-props';
    }

    public function getDescription(): string
    {
        return 'Statically resolved local anonymous component calls must provide every @props entry that has no default.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::ERROR;
    }

    public function cacheContext(array $options): array|string
    {
        $projectRoot = PathResolver::projectRoot();
        if ($projectRoot === '') {
            return 'component-root-unavailable';
        }

        $hash = hash_init('xxh128');
        $count = 0;
        $roots = [
            'anonymous' => [
                $projectRoot.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'views'
                    .DIRECTORY_SEPARATOR.'components',
                '*.blade.php',
            ],
            'classes' => [
                $projectRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'View'
                    .DIRECTORY_SEPARATOR.'Components',
                '*.php',
            ],
        ];

        foreach ($roots as $kind => [$root, $pattern]) {
            if (! is_dir($root)) {
                continue;
            }

            $finder = (new Finder)->files()->in($root)->name($pattern)->sortByName();

            foreach ($finder as $file) {
                $relativePath = $kind.'/'.str_replace('\\', '/', $file->getRelativePathname());
                hash_update($hash, $relativePath."\0");

                $contents = file_get_contents($file->getPathname());
                hash_update($hash, $contents === false ? "\0unreadable\0" : $contents);
                hash_update($hash, "\0");
                $count++;
            }
        }

        [$aliases, $namespaces] = $this->registeredClassComponents();

        return [
            'schema' => 1,
            'files' => $count,
            'treeHash' => hash_final($hash),
            'aliases' => $aliases,
            'namespaces' => $namespaces,
        ];
    }

    public function check(Document $document, RuleContext $context): void
    {
        $viewsRoot = $this->resolveViewsRoot($context->getFilePath());
        if ($viewsRoot === null) {
            return;
        }

        foreach ($document->queryComponents() as $component) {
            $this->checkComponent($component, $viewsRoot, $context);
        }
    }

    private function checkComponent(ElementNode $component, string $viewsRoot, RuleContext $context): void
    {
        $tag = strtolower($component->tagNameText());
        $name = str_starts_with($tag, 'x-') ? substr($tag, 2) : '';

        if (! $this->shouldInspectAnonymousComponent($component, $name, $viewsRoot)) {
            return;
        }

        $requiredProps = $this->requiredPropsFor($viewsRoot, $name);
        if ($requiredProps === null || $requiredProps === []) {
            return;
        }

        $missing = [];
        foreach ($requiredProps as $prop) {
            $names = array_values(array_unique([$prop, Str::kebab($prop)]));

            if (! $this->everyAttributeRenderPathIsSatisfied($component, $names)
                && ! $this->hasSatisfyingNamedSlot($component, $names)) {
                $missing[] = Str::kebab($prop);
            }
        }

        if ($missing === []) {
            return;
        }

        $label = count($missing) === 1 ? 'required prop' : 'required props';
        $context->report(
            $component,
            "Component <{$tag}> is missing {$label}: ".implode(', ', $missing).'.',
        );
    }

    private function shouldInspectAnonymousComponent(
        ElementNode $component,
        string $name,
        string $viewsRoot,
    ): bool {
        if ($name === '' || $name === 'dynamic-component' || str_contains($name, '::')) {
            return false;
        }

        if (preg_match('/^[a-z0-9][a-z0-9-]*(?:\.[a-z0-9][a-z0-9-]*)*$/D', $name) !== 1) {
            return false;
        }

        return ! $this->mayResolveToClassComponent($name, $viewsRoot)
            && ! $this->hasOpaqueCallSiteAttributes($component);
    }

    /** @param list<string> $names */
    private function hasSatisfyingNamedSlot(ElementNode $component, array $names): bool
    {
        if (! $component instanceof ComponentNode) {
            return false;
        }

        $canonicalNames = array_map($this->canonicalPropName(...), $names);

        foreach ($component->namedSlots() as $slot) {
            if ($slot->isDynamic()) {
                // A dynamic slot name may provide this value at runtime.
                return true;
            }

            $slotName = $slot->baseSlotName();
            if ($slotName !== null
                && in_array($this->canonicalPropName($slotName), $canonicalNames, true)) {
                return true;
            }
        }

        return false;
    }

    private function hasOpaqueCallSiteAttributes(ElementNode $component): bool
    {
        if ($this->elementHasOpaqueAttributes($component)) {
            return true;
        }

        foreach ($component->attributes() as $attribute) {
            if (str_starts_with(ltrim((string) $attribute), '...')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string>|null */
    private function requiredPropsFor(string $viewsRoot, string $componentName): ?array
    {
        $componentRoot = $viewsRoot.DIRECTORY_SEPARATOR.'components';
        $relative = str_replace('.', DIRECTORY_SEPARATOR, $componentName);
        $nameParts = explode('.', $componentName);
        $lastSegment = $nameParts[array_key_last($nameParts)];
        $candidates = [
            $componentRoot.DIRECTORY_SEPARATOR.$relative.'.blade.php',
            $componentRoot.DIRECTORY_SEPARATOR.$relative.DIRECTORY_SEPARATOR.'index.blade.php',
            $componentRoot.DIRECTORY_SEPARATOR.$relative.DIRECTORY_SEPARATOR.$lastSegment.'.blade.php',
        ];
        $realRoot = realpath($componentRoot);
        if ($realRoot === false) {
            return null;
        }

        $rootPrefix = rtrim(str_replace('\\', '/', $realRoot), '/').'/';
        $realPath = null;

        foreach ($candidates as $candidate) {
            if (! is_file($candidate)) {
                continue;
            }

            $resolved = realpath($candidate);
            if ($resolved === false) {
                return null;
            }

            $normalizedPath = str_replace('\\', '/', $resolved);
            if (! str_starts_with(strtolower($normalizedPath), strtolower($rootPrefix))) {
                // Do not fall through to a lower-precedence component when the
                // view Laravel would select cannot be inspected safely.
                return null;
            }

            $realPath = $resolved;

            break;
        }

        if ($realPath === null) {
            return null;
        }

        $source = file_get_contents($realPath);
        if ($source === false) {
            return null;
        }

        return $this->requiredPropsCache->remember(
            $realPath,
            $source,
            fn (): ?array => $this->parseRequiredPropsSource($realPath, $source),
        );
    }

    /** @return list<string>|null */
    private function parseRequiredPropsSource(string $realPath, string $source): ?array
    {
        $definition = Document::parse($source, BladeParserOptions::normalize(null))->setFilePath($realPath);
        if ($definition->hasErrors()) {
            return null;
        }

        $propsDirectives = [];
        foreach ($definition->queryDirectives() as $directive) {
            if (strtolower($directive->nameText()) === 'props') {
                $propsDirectives[] = $directive;
            }
        }

        if (count($propsDirectives) !== 1) {
            return null;
        }

        if ($propsDirectives[0]->getParent() !== null) {
            return null;
        }

        return $this->parseRequiredProps($propsDirectives[0]);
    }

    /** @return list<string>|null */
    private function parseRequiredProps(DirectiveNode $directive): ?array
    {
        $inner = PhpSource::innerArguments($directive->arguments());
        if ($inner === null) {
            return null;
        }

        $trimmed = trim($inner);
        if (str_starts_with($trimmed, '[') && str_ends_with($trimmed, ']')) {
            $arrayBody = substr($trimmed, 1, -1);
        } elseif (preg_match('/^array\s*\((.*)\)$/is', $trimmed, $matches) === 1) {
            $arrayBody = $matches[1];
        } else {
            return null;
        }

        if (trim($arrayBody) === '') {
            return [];
        }

        $entries = PhpSource::splitTopLevel($arrayBody);
        if ($entries === null) {
            return null;
        }

        $required = [];
        $optional = [];

        foreach ($entries as $entry) {
            $keyValue = $this->splitTopLevelDefault($entry);
            if ($keyValue === false) {
                $name = PhpSource::literalString($entry);
                if ($name === null || ! $this->isValidPropName($name)) {
                    return null;
                }

                $required[$this->canonicalPropName($name)] = $name;

                continue;
            }

            if ($keyValue === null) {
                return null;
            }

            $key = PhpSource::literalString($keyValue[0]);
            if ($this->isDefinitelyIntegerArrayKey($keyValue[0], $key)) {
                $name = PhpSource::literalString($keyValue[1]);
                if ($name === null || ! $this->isValidPropName($name)) {
                    return null;
                }

                $required[$this->canonicalPropName($name)] = $name;

                continue;
            }

            if ($key === null
                || ! $this->isValidPropName($key)
                || trim($keyValue[1]) === ''
                || ! PhpSource::parses($keyValue[1])) {
                return null;
            }

            $optional[$this->canonicalPropName($key)] = true;
        }

        foreach (array_keys($optional) as $name) {
            unset($required[$name]);
        }

        return array_values($required);
    }

    /**
     * @return array{string, string}|false|null False means no default; null is ambiguous.
     */
    private function splitTopLevelDefault(string $entry): array|false|null
    {
        $tokens = PhpSource::tokenize($entry);
        if ($tokens === null) {
            return null;
        }

        $depth = 0;
        $left = '';
        $found = false;
        $right = '';

        foreach ($tokens as $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_DOUBLE_ARROW && $depth === 0) {
                if ($found) {
                    return null;
                }

                $found = true;

                continue;
            }

            if ($found) {
                $right .= $text;
            } else {
                $left .= $text;
            }

            $depth += PhpSource::nestingDelta($token);
            if ($depth < 0) {
                return null;
            }
        }

        if ($depth !== 0) {
            return null;
        }

        return $found ? [trim($left), trim($right)] : false;
    }

    private function isValidPropName(string $name): bool
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $name) === 1;
    }

    private function canonicalPropName(string $name): string
    {
        return strtolower(str_replace(['-', '_'], '', $name));
    }

    private function isDefinitelyIntegerArrayKey(string $expression, ?string $literalString): bool
    {
        if ($literalString !== null) {
            return preg_match('/^(?:0|-?[1-9][0-9]*)$/D', $literalString) === 1
                && filter_var($literalString, FILTER_VALIDATE_INT) !== false;
        }

        $tokens = PhpSource::tokenize($expression);
        if ($tokens === null) {
            return false;
        }

        $tokens = array_values(array_filter(
            $tokens,
            static fn (array|string $token): bool => ! is_array($token)
                || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        return (count($tokens) === 1 && is_array($tokens[0]) && $tokens[0][0] === T_LNUMBER)
            || (count($tokens) === 2
                && in_array($tokens[0], ['+', '-'], true)
                && is_array($tokens[1])
                && $tokens[1][0] === T_LNUMBER);
    }

    private function resolveViewsRoot(string $filePath): ?string
    {
        $normalized = str_replace('\\', '/', $filePath);
        $isUnc = str_starts_with($normalized, '//');
        $marker = '/resources/views/';
        $position = stripos('/'.ltrim($normalized, '/'), $marker);
        if ($position === false) {
            return null;
        }

        $withLeadingSlash = '/'.ltrim($normalized, '/');
        $prefix = substr($withLeadingSlash, 0, $position);
        $viewsRoot = ltrim($prefix.$marker, '/');

        // Restore the leading slash for POSIX absolute paths. Windows drive and
        // relative paths do not need it.
        if ($isUnc) {
            $viewsRoot = '//'.$viewsRoot;
        } elseif (str_starts_with($normalized, '/')) {
            $viewsRoot = '/'.$viewsRoot;
        }

        return rtrim(str_replace('/', DIRECTORY_SEPARATOR, $viewsRoot), DIRECTORY_SEPARATOR);
    }

    private function mayResolveToClassComponent(string $componentName, string $viewsRoot): bool
    {
        $parts = array_map(Str::studly(...), explode('.', $componentName));
        $relativeClassPath = implode(DIRECTORY_SEPARATOR, $parts);
        $classRoot = dirname($viewsRoot, 2).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'View'
            .DIRECTORY_SEPARATOR.'Components';
        $classCandidates = [
            $classRoot.DIRECTORY_SEPARATOR.$relativeClassPath.'.php',
            $classRoot.DIRECTORY_SEPARATOR.$relativeClassPath.DIRECTORY_SEPARATOR.$parts[array_key_last($parts)].'.php',
        ];

        foreach ($classCandidates as $candidate) {
            if (is_file($candidate)) {
                return true;
            }
        }

        try {
            $compiler = app('blade.compiler');
            if (! $compiler instanceof BladeCompiler) {
                return false;
            }

            // A registered alias wins before anonymous component lookup. Its
            // mere presence is enough to make static anonymous resolution
            // ambiguous; never autoload or execute the referenced class.
            if (array_key_exists($componentName, $compiler->getClassComponentAliases())) {
                return true;
            }

            return false;
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{array<string, string>, array<string, string>} */
    private function registeredClassComponents(): array
    {
        try {
            $compiler = app('blade.compiler');
            if (! $compiler instanceof BladeCompiler) {
                return [[], []];
            }

            $aliases = [];
            foreach ($compiler->getClassComponentAliases() as $alias => $class) {
                if (is_string($alias) && is_string($class)) {
                    $aliases[$alias] = $class;
                }
            }

            $namespaces = [];
            foreach ($compiler->getClassComponentNamespaces() as $prefix => $namespace) {
                if (is_string($prefix) && is_string($namespace)) {
                    $namespaces[$prefix] = $namespace;
                }
            }

            ksort($aliases);
            ksort($namespaces);

            return [$aliases, $namespaces];
        } catch (Throwable) {
            return [[], []];
        }
    }
}
