<?php

declare(strict_types=1);

namespace Forte\Sheath;

use Forte\Ast\Document\Document;
use Forte\Parser\ParserOptions;
use Forte\Sheath\Analysis\AnalysisStore;
use Forte\Sheath\Components\ComponentSemanticRewriter;
use Forte\Sheath\Components\SemanticDocumentMap;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Configuration\Suppressions;
use Forte\Sheath\Contracts\ProvidesRuleDocument;
use Forte\Sheath\Contracts\Rule;
use Forte\Sheath\Exceptions\RuleNotFoundException;
use Forte\Sheath\Files\PathMatcher;
use Forte\Sheath\Packages\Dependencies;
use Forte\Sheath\Packages\PackageRequirementMode;
use Forte\Sheath\Parsing\BladeParserOptions;
use Forte\Sheath\Parsing\IgnoredRegionRegistry;
use Forte\Sheath\Parsing\IgnoredRegions;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Position;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Results\Violation;
use Forte\Sheath\Results\ViolationCollector;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Rules\RuleExecutionPlan;
use Forte\Sheath\Rules\RuleRegistry;
use RuntimeException;

readonly class Linter
{
    private RuleExecutionPlan $executionPlan;

    public function __construct(
        private RuleRegistry $registry,
        private ?Dependencies $dependencies = null,
        private ?ParserOptions $parserOptions = null,
        private ?IgnoredRegionRegistry $ignoredRegionRegistry = null,
    ) {
        $this->executionPlan = new RuleExecutionPlan;
    }

    public function lint(string $content, string $filePath, Config $config, ?ParserOptions $parserOptions = null): LintResult
    {
        $this->registry->setPackageRequirementMode($config->getPackageRequirementMode());
        $this->assertKnownConfiguredRules($config);

        $normalizedParserOptions = BladeParserOptions::normalize($parserOptions ?? $this->parserOptions);
        $ignoredRegions = $this->ignoredRegionRegistry?->regions($content, $filePath) ?? [];
        $parsedContent = IgnoredRegions::mask($content, $ignoredRegions);

        try {
            $document = Document::parse(
                $parsedContent,
                $normalizedParserOptions,
            )->setFilePath($filePath);
        } catch (RuntimeException $exception) {
            if (! $this->isParserDepthLimitException($exception)) {
                throw $exception;
            }

            $position = new Position(0, 1, 1);

            return new LintResult(
                filePath: $filePath,
                violations: [new Violation(
                    ruleId: 'parse-error',
                    message: $exception->getMessage(),
                    severity: Severity::ERROR,
                    filePath: $filePath,
                    start: $position,
                    end: $position,
                )],
                hasParseErrors: true,
                sourceHash: hash('xxh128', $content),
            );
        }
        $hasParseErrors = $document->hasErrors();

        if ($hasParseErrors) {
            return $this->parseErrorResult($document, $filePath, $content);
        }

        $collector = new ViolationCollector;

        $semanticDocumentMap = $config->getComponentMappings() === []
            ? null
            : ComponentSemanticRewriter::rewrite(
                $document,
                $config->getComponentMappings(),
                $normalizedParserOptions,
            );
        $semanticDocument = $semanticDocumentMap?->semanticDocument() ?? $document;
        $analysisStore = new AnalysisStore;
        /** @var array<string, Document> $ruleDocuments */
        $ruleDocuments = [];

        foreach ($this->getEnabledRules($config, $filePath) as $rule) {
            $usesSemanticDocument = $semanticDocument !== $document
                && RuleCategory::nameFor($rule->getCategory()) !== RuleCategory::BLADE->value;
            $ruleDocument = $usesSemanticDocument ? $semanticDocument : $document;
            if ($rule instanceof ProvidesRuleDocument) {
                $key = $rule->ruleDocumentKey();
                if ($key === '') {
                    throw new RuntimeException('Rule document keys cannot be empty.');
                }

                $inputDocument = $ruleDocument;
                // The same normalization key may be used against both the
                // authored and component-semantic documents. Scope sharing to
                // the actual input document so one view cannot leak into the
                // other.
                $documentKey = spl_object_id($inputDocument).':'.$key;
                $ruleDocument = $ruleDocuments[$documentKey] ??= $rule->ruleDocument(
                    $inputDocument,
                    $normalizedParserOptions,
                );
                if (strlen($ruleDocument->source()) !== strlen($inputDocument->source())) {
                    throw new RuntimeException("Rule document [{$key}] must preserve source byte length.");
                }
                if ($ruleDocument->hasErrors()) {
                    return $this->parseErrorResult(
                        $ruleDocument,
                        $filePath,
                        $content,
                        $usesSemanticDocument ? $semanticDocumentMap : null,
                    );
                }
            }

            $this->executeRule(
                $rule,
                $ruleDocument,
                $filePath,
                $config,
                $collector,
                $usesSemanticDocument ? $semanticDocumentMap : null,
                $content,
                $analysisStore,
            );
        }

        $collector->sort();

        $violations = IgnoredRegions::protectFixes($collector->all(), $ignoredRegions);

        if ($violations !== [] && $config->respectsInlineSuppressions()) {
            $violations = Suppressions::fromDocument($document)->filter($violations);
        }

        return new LintResult(
            filePath: $filePath,
            violations: $violations,
            hasParseErrors: $hasParseErrors,
            sourceHash: hash('xxh128', $content),
        );
    }

    private function parseErrorResult(
        Document $document,
        string $filePath,
        string $content,
        ?SemanticDocumentMap $semanticDocumentMap = null,
    ): LintResult {
        $collector = new ViolationCollector;

        foreach ($document->diagnostics()->errors() as $error) {
            $collector->add(new Violation(
                ruleId: 'parse-error',
                message: $this->humanizeParseErrorMessage($error->message),
                severity: Severity::ERROR,
                filePath: $filePath,
                start: $semanticDocumentMap?->originalPosition($error->start)
                    ?? Position::fromOffset($document, $error->start),
                end: $semanticDocumentMap?->originalPosition($error->end)
                    ?? Position::fromOffset($document, $error->end),
            ));
        }

        $collector->sort();

        return new LintResult(
            filePath: $filePath,
            violations: $collector->all(),
            hasParseErrors: true,
            sourceHash: hash('xxh128', $content),
        );
    }

    private function assertKnownConfiguredRules(Config $config): void
    {
        foreach (array_keys($config->getRules()) as $ruleId) {
            if (! $this->registry->hasKnown($ruleId)) {
                throw RuleNotFoundException::forId($ruleId);
            }
        }
    }

    private function humanizeParseErrorMessage(string $message): string
    {
        if (preg_match('/^Lexer error at offset \d+: (?<reason>\w+) while in state (?<state>\w+)$/', $message, $matches) !== 1) {
            return $message;
        }

        $friendly = match ($matches['reason'].':'.$matches['state']) {
            'UnexpectedEof:EchoContent' => "Unexpected end of file inside a Blade echo. Is a '}}' missing?",
            'UnexpectedEof:RawEchoContent' => "Unexpected end of file inside a raw Blade echo. Is a '!!}' missing?",
            'UnexpectedEof:TripleEchoContent' => "Unexpected end of file inside a triple Blade echo. Is a '}}}' missing?",
            'UnclosedComment:BladeComment' => "Unclosed Blade comment. Is a '--}}' missing?",
            'UnexpectedEof:Comment' => "Unclosed HTML comment. Is a '-->' missing?",
            default => null,
        };

        if ($friendly === null) {
            return $message;
        }

        return "{$friendly} ({$message})";
    }

    private function isParserDepthLimitException(RuntimeException $exception): bool
    {
        return preg_match(
            '/^Maximum (?:element|directive|condition) nesting depth \(\d+\) exceeded\.$/',
            $exception->getMessage()
        ) === 1;
    }

    /**
     * @return array<Rule>
     */
    private function getEnabledRules(Config $config, string $filePath): array
    {
        $this->prepareRules($config);
        $rules = [];

        foreach ($this->executionPlan->entries() as $entry) {
            $exclusions = $entry['exclusions'];
            if ($exclusions !== [] && PathMatcher::matchesAny($exclusions, $filePath)) {
                continue;
            }

            $rules[] = $this->createExecutionRule($entry['prototype']);
        }

        return $rules;
    }

    private function prepareRules(Config $config): void
    {
        $configRevision = $config->revision();
        $registryRevision = $this->registry->revision();
        if ($this->executionPlan->matches($config, $configRevision, $registryRevision)) {
            return;
        }

        $prepared = [];
        $ignorePackageRequirements = $config->getPackageRequirementMode() === PackageRequirementMode::IGNORE;

        foreach ($config->getRules() as $ruleId => $_) {
            if (! $ignorePackageRequirements && $this->registry->isDisabledDueToPackages($ruleId)) {
                continue;
            }

            $severity = $config->getRuleSeverity($ruleId);
            if ($severity === Severity::OFF) {
                continue;
            }

            $rule = $this->registry->find($ruleId);
            if ($rule === null) {
                continue;
            }

            $prototype = $this->createExecutionRule($rule);
            $prototype->setSeverity($severity ?? $prototype->getDefaultSeverity());
            $prototype->setOptions($config->getRuleOptions($ruleId));

            if (! $prototype->isEnabled()) {
                continue;
            }

            $prepared[] = [
                'prototype' => $prototype,
                'exclusions' => array_values($config->getRuleExclusions($ruleId)),
            ];
        }

        $this->executionPlan->replace($config, $configRevision, $registryRevision, $prepared);
    }

    private function createExecutionRule(Rule $rule): Rule
    {
        return clone $rule;
    }

    private function executeRule(
        Rule $rule,
        Document $document,
        string $filePath,
        Config $config,
        ViolationCollector $collector,
        ?SemanticDocumentMap $semanticDocumentMap = null,
        ?string $originalSource = null,
        ?AnalysisStore $analysisStore = null,
    ): void {
        $context = new RuleContext(
            document: $document,
            filePath: $filePath,
            config: $config,
            collector: $collector,
            ruleSeverity: $rule->getSeverity(),
            ruleId: $rule->getId(),
            dependencies: $this->dependencies,
            suppressFixes: $config->shouldNeverFix($rule->getId())
                || $config->shouldNeverFix($rule::class),
            semanticDocumentMap: $semanticDocumentMap,
            originalSource: $originalSource,
            analysisStore: $analysisStore ?? new AnalysisStore,
        );

        $rule->check($document, $context);
    }
}
