<?php

declare(strict_types=1);

namespace Forte\Sheath\Reporters;

use Closure;
use Forte\Sheath\Contracts\Reporter;
use Forte\Sheath\Exceptions\ReporterNotFoundException;
use InvalidArgumentException;

class ReporterRegistry
{
    /**
     * @var array<string, class-string<Reporter>>
     */
    protected array $reporters = [
        'stylish' => StylishReporter::class,
        'json' => JsonReporter::class,
        'compact' => CompactReporter::class,
        'unix' => UnixReporter::class,
        'checkstyle' => CheckstyleReporter::class,
        'github' => GitHubReporter::class,
        'agent' => AgentReporter::class,
    ];

    /**
     * @var array<string, Reporter>
     */
    protected array $instances = [];

    /**
     * @var (Closure(class-string<Reporter>): Reporter)|null
     */
    private ?Closure $resolver = null;

    /**
     * @param  Closure(class-string<Reporter>): Reporter  $resolver
     */
    public function setResolver(Closure $resolver): void
    {
        $this->resolver = $resolver;
    }

    /**
     * @throws ReporterNotFoundException
     */
    public function get(string $name): Reporter
    {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        if (! isset($this->reporters[$name])) {
            throw ReporterNotFoundException::forName($name, $this->getAvailableReporters());
        }

        return $this->resolve($this->reporters[$name]);
    }

    /**
     * @param  class-string<Reporter>|Reporter  $reporter
     */
    public function register(string $name, string|Reporter $reporter): void
    {
        if ($reporter instanceof Reporter) {
            $this->reporters[$name] = $reporter::class;
            $this->instances[$name] = $reporter;

            return;
        }

        if (! is_subclass_of($reporter, Reporter::class)) {
            throw new InvalidArgumentException('Reporter must implement '.Reporter::class);
        }

        unset($this->instances[$name]);
        $this->reporters[$name] = $reporter;
    }

    /**
     * @param  class-string<Reporter>  $reporterClass
     */
    private function resolve(string $reporterClass): Reporter
    {
        if ($this->resolver !== null) {
            return ($this->resolver)($reporterClass);
        }

        return new $reporterClass;
    }

    /**
     * @return array<string>
     */
    public function getAvailableReporters(): array
    {
        return array_keys($this->reporters);
    }

    public function has(string $name): bool
    {
        return isset($this->reporters[$name]);
    }
}
