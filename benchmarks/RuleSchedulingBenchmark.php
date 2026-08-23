<?php

declare(strict_types=1);

require __DIR__.'/bootstrap.php';

use Forte\Ast\Document\Document;
use Forte\Sheath\Analysis\AnalysisStore;
use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Sheath\Rules\RuleRegistry;

const SCHEDULING_FILES = 400;
const ANALYSIS_FILES = 200;
const ANALYSIS_RULES = 12;

final class SchedulingBenchmarkRule extends AbstractRule
{
    public function __construct(private readonly string $id)
    {
        parent::__construct();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getDescription(): string
    {
        return 'Benchmark rule.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::BLADE;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void {}
}

final readonly class SchedulingBenchmarkAnalysis
{
    public function __construct(public int $attributeBytes) {}

    public static function build(Document $document): self
    {
        $attributeBytes = 0;
        foreach ($document->queryElements() as $element) {
            foreach ($element->attributes()->all() as $attribute) {
                $attributeBytes += strlen($attribute->nameText()) + strlen($attribute->valueText());
            }
        }

        return new self($attributeBytes);
    }
}

$schedulingRegistry = new RuleRegistry;
$schedulingRules = [];
for ($index = 0; $index < 80; $index++) {
    $id = 'benchmark-scheduling-'.$index;
    $schedulingRegistry->register(new SchedulingBenchmarkRule($id));
    $schedulingRules[$id] = ['warning', ['marker' => $index]];
}
$schedulingConfig = Config::make(['rules' => $schedulingRules]);
$smallSource = '<main><p>Small template</p></main>';

$fresh = benchmarkMedianMilliseconds(static function () use ($schedulingRegistry, $schedulingConfig, $smallSource): callable {
    return static function () use ($schedulingRegistry, $schedulingConfig, $smallSource): void {
        for ($file = 0; $file < SCHEDULING_FILES; $file++) {
            (new Linter($schedulingRegistry))->lint($smallSource, "fresh-{$file}.blade.php", $schedulingConfig);
        }
    };
});

$prepared = benchmarkMedianMilliseconds(static function () use ($schedulingRegistry, $schedulingConfig, $smallSource): callable {
    $linter = new Linter($schedulingRegistry);

    return static function () use ($linter, $schedulingConfig, $smallSource): void {
        for ($file = 0; $file < SCHEDULING_FILES; $file++) {
            $linter->lint($smallSource, "prepared-{$file}.blade.php", $schedulingConfig);
        }
    };
});

printf("Rule preparation (%d tiny files, 80 enabled rules)\n", SCHEDULING_FILES);
printf("  fresh linter per file: %8.3f ms\n", $fresh);
printf("  reused run plan:       %8.3f ms\n", $prepared);

$analysisSource = '<main>'.str_repeat('<span class="item" data-state="ready">Text</span>', 600).'</main>';
$analysisDocument = Document::parse($analysisSource);
$analysisTimes = [
    'repeated' => benchmarkMedianMilliseconds(
        static fn (): callable => static function () use ($analysisDocument): void {
            for ($file = 0; $file < ANALYSIS_FILES; $file++) {
                for ($rule = 0; $rule < ANALYSIS_RULES; $rule++) {
                    SchedulingBenchmarkAnalysis::build($analysisDocument);
                }
            }
        },
    ),
    'shared' => benchmarkMedianMilliseconds(
        static fn (): callable => static function () use ($analysisDocument): void {
            for ($file = 0; $file < ANALYSIS_FILES; $file++) {
                $store = new AnalysisStore;
                for ($rule = 0; $rule < ANALYSIS_RULES; $rule++) {
                    $store->remember(
                        $analysisDocument,
                        SchedulingBenchmarkAnalysis::class,
                        static fn (): SchedulingBenchmarkAnalysis => SchedulingBenchmarkAnalysis::build($analysisDocument),
                    );
                }
            }
        },
    ),
];

printf("Shared document analysis (%d files, %d rules, 600 elements)\n", ANALYSIS_FILES, ANALYSIS_RULES);
printf("  repeated analysis:     %8.3f ms\n", $analysisTimes['repeated']);
printf("  shared analysis:       %8.3f ms\n", $analysisTimes['shared']);
