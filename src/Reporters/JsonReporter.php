<?php

declare(strict_types=1);

namespace Forte\Sheath\Reporters;

use Forte\Sheath\Results\LintResult;

/** @internal */
class JsonReporter extends AbstractReporter
{
    public const SCHEMA_VERSION = 1;

    public function format(LintResult $result): string
    {
        return $this->formatMany([$result]);
    }

    /**
     * @param  array<LintResult>  $results
     */
    public function formatMany(array $results): string
    {
        return json_encode(
            $this->report($results),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }

    /**
     * @param  array<LintResult>  $results
     * @return array{
     *     schemaVersion: int,
     *     results: list<array<string, mixed>>,
     *     summary: array{errors: int, warnings: int, infos: int, files: int, fixable: int}
     * }
     */
    public function report(array $results): array
    {
        return [
            'schemaVersion' => self::SCHEMA_VERSION,
            'results' => array_values(array_map($this->describe(...), $results)),
            'summary' => $this->calculateTotals($results),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(LintResult $result): array
    {
        $data = $result->toArray();
        unset($data['sourceHash']);
        $data['filePath'] = $this->displayPath($result->filePath);

        if (is_array($data['violations'])) {
            foreach ($data['violations'] as &$violation) {
                if (is_array($violation) && isset($violation['filePath']) && is_string($violation['filePath'])) {
                    $violation['filePath'] = $this->displayPath($violation['filePath']);
                }

                if (is_array($violation)) {
                    $this->encodeBinaryFixReplacement($violation);
                }
            }
            unset($violation);
        }

        return $data;
    }

    /** @param array<mixed, mixed> $violation */
    private function encodeBinaryFixReplacement(array &$violation): void
    {
        $fix = $violation['fix'] ?? null;
        if (! is_array($fix)) {
            return;
        }

        $replacement = $fix['replacement'] ?? null;
        if (! is_string($replacement) || preg_match('//u', $replacement) === 1) {
            return;
        }

        $fix['replacement'] = base64_encode($replacement);
        $fix['replacementEncoding'] = 'base64';
        $violation['fix'] = $fix;
    }
}
