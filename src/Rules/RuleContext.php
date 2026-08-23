<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\ElementNode;
use Forte\Ast\Node;
use Forte\Sheath\Analysis\AnalysisStore;
use Forte\Sheath\Analysis\DocumentElements;
use Forte\Sheath\Components\SemanticDocumentMap;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Packages\Dependencies;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;
use Forte\Sheath\Results\ViolationCollector;
use Illuminate\Support\Collection;

readonly class RuleContext
{
    public function __construct(
        private Document $document,
        private string $filePath,
        private Config $config,
        private ViolationCollector $collector,
        private Severity $ruleSeverity,
        private string $ruleId,
        private ?Dependencies $dependencies = null,
        private bool $suppressFixes = false,
        private ?SemanticDocumentMap $semanticDocumentMap = null,
        private ?string $originalSource = null,
        private AnalysisStore $analysisStore = new AnalysisStore,
    ) {}

    public function getDocument(): Document
    {
        return $this->document;
    }

    public function getFilePath(): string
    {
        return $this->filePath;
    }

    public function getConfig(): Config
    {
        return $this->config;
    }

    /**
     * Build a typed analysis once and share it with every rule inspecting the
     * same document during this lint pass.
     *
     * @template T of object
     *
     * @param  class-string<T>  $type
     * @param  callable(): T  $factory
     * @return T
     */
    public function analysis(string $type, callable $factory): object
    {
        return $this->analysisStore->remember($this->document, $type, $factory);
    }

    /**
     * Return the ordinary elements in source order, materialized once for all
     * rules inspecting this document during the current lint pass.
     *
     * @return Collection<int, ElementNode>
     */
    public function elements(): Collection
    {
        /** @var DocumentElements $elements */
        $elements = $this->analysis(
            DocumentElements::class,
            fn (): DocumentElements => DocumentElements::fromDocument($this->document),
        );

        return $elements->all();
    }

    public function report(Node $node, string $message, ?Fix $fix = null): void
    {
        $fix = $this->filterFix($fix, $node);
        $endOffset = $this->diagnosticEndOffset($node);

        if ($this->semanticDocumentMap !== null) {
            if ($fix !== null) {
                $fix = $this->semanticDocumentMap->originalFix($fix);
            }

            $violation = new Violation(
                $this->ruleId,
                $message,
                $this->ruleSeverity,
                $this->filePath,
                $this->semanticDocumentMap->originalPosition($node->startOffset()),
                $this->semanticDocumentMap->originalPosition($endOffset),
                $fix,
            );
            $this->collector->add($violation);

            return;
        }

        $violation = new Violation(
            $this->ruleId,
            $message,
            $this->ruleSeverity,
            $this->filePath,
            Position::fromOffset($node->getDocument(), $node->startOffset()),
            Position::fromOffset($node->getDocument(), $endOffset),
            $fix,
        );

        $this->collector->add($violation);
    }

    /**
     * Element findings conventionally identify the opening tag. Using the
     * element node's end offset would include its children and closing tag,
     * making a missing-attribute diagnostic appear to blame the whole subtree.
     */
    private function diagnosticEndOffset(Node $node): int
    {
        if (! $node instanceof ElementNode || $node->startOffset() < 0) {
            return $node->endOffset();
        }

        $document = $node->getDocument();
        $afterOpeningTag = $document->findOpeningTagEndPosition($node->index());

        if ($afterOpeningTag <= $node->startOffset()
            || $afterOpeningTag > strlen($document->source())
            || ($document->source()[$afterOpeningTag - 1] ?? '') !== '>') {
            return $node->endOffset();
        }

        return $afterOpeningTag;
    }

    public function reportAt(Position $start, Position $end, string $message, ?Fix $fix = null): void
    {
        $fix = $this->filterFix($fix);

        if ($this->semanticDocumentMap !== null) {
            if ($fix !== null) {
                $fix = $this->semanticDocumentMap->originalFix($fix);
            }

            $start = $this->semanticDocumentMap->originalPosition($start->offset);
            $end = $this->semanticDocumentMap->originalPosition($end->offset);
        }

        $violation = new Violation(
            $this->ruleId,
            $message,
            $this->ruleSeverity,
            $this->filePath,
            $start,
            $end,
            $fix
        );

        $this->collector->add($violation);
    }

    private function filterFix(?Fix $fix, ?Node $node = null): ?Fix
    {
        if ($fix === null) {
            return null;
        }

        if ($this->suppressFixes) {
            return null;
        }

        if ($this->semanticDocumentMap === null) {
            return $fix;
        }

        if ($this->semanticDocumentMap->fixTouchesMappedComponentOpening($fix)) {
            return null;
        }

        // A native-element rule can rewrite more than the opening tag while
        // reporting the mapped element itself. Keep the broader conservative
        // stand-down for those node-based reports.
        if ($node instanceof ElementNode
            && $this->semanticDocumentMap->isMappedComponent($node)) {
            return null;
        }

        return $fix;
    }

    public function getSourceForNode(Node $node): string
    {
        return $node->getDocumentContent();
    }

    public function getSourceAt(int $startOffset, int $endOffset): string
    {
        return $this->document->getText($startOffset, $endOffset);
    }

    /** Return the original, unmasked template source. */
    public function getOriginalSource(): string
    {
        return $this->originalSource ?? $this->document->source();
    }

    /** Return a half-open byte range from the original, unmasked template source. */
    public function getOriginalSourceAt(int $startOffset, int $endOffset): string
    {
        return substr($this->getOriginalSource(), $startOffset, $endOffset - $startOffset);
    }

    public function getDependencies(): ?Dependencies
    {
        return $this->dependencies;
    }

    public function hasPackage(string $package): bool
    {
        return $this->dependencies?->has($package) ?? false;
    }

    public function packageSatisfies(string $package, string $constraint): bool
    {
        return $this->dependencies?->satisfies($package, $constraint) ?? false;
    }
}
