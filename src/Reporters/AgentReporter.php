<?php

declare(strict_types=1);

namespace Forte\Sheath\Reporters;

use Forte\Sheath\Contracts\ReceivesRunContext;
use Forte\Sheath\Results\LintResult;
use Forte\Sheath\Results\Violation;
use JsonException;

/** @internal */
class AgentReporter extends AbstractReporter implements ReceivesRunContext
{
    private int $maxWarnings = -1;

    public function setMaxWarnings(int $maxWarnings): static
    {
        $this->maxWarnings = $maxWarnings;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function receiveRunContext(array $context): void
    {
        if (isset($context['maxWarnings']) && is_int($context['maxWarnings'])) {
            $this->setMaxWarnings($context['maxWarnings']);
        }
    }

    public function format(LintResult $result): string
    {
        return $this->formatMany([$result]);
    }

    /**
     * @param  array<LintResult>  $results
     */
    public function formatMany(array $results): string
    {
        $files = [];

        foreach ($results as $result) {
            if (! $result->hasViolations()) {
                continue;
            }

            $files[] = [
                'path' => $this->displayPath($result->filePath),
                'violations' => array_map($this->describe(...), $result->violations),
            ];
        }

        if ($files === []) {
            return $this->encode(['tool' => 'sheath', 'result' => 'passed']);
        }

        $totals = $this->calculateTotals($results);

        return $this->encode([
            'tool' => 'sheath',
            'result' => $this->fails($totals) ? 'fail' : 'passed',

            'errors' => $totals['errors'],
            'warnings' => $totals['warnings'],
            'infos' => $totals['infos'],
            'fixable' => $totals['fixable'],
            'files' => $files,
        ]);
    }

    /**
     * @param  array{files: int, errors: int, warnings: int, infos: int, fixable: int}  $totals
     */
    private function fails(array $totals): bool
    {
        return $totals['errors'] > 0
            || ($this->maxWarnings >= 0 && $totals['warnings'] > $this->maxWarnings);
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Violation $violation): array
    {
        $described = [
            'line' => $violation->getLine(),
            'col' => $violation->getColumn(),
            'severity' => $violation->severity->value,
            'rule' => $violation->ruleId,
            'message' => $violation->message,
        ];

        if ($violation->fix !== null) {
            $described['fixable'] = $violation->fix->dangerous ? 'dangerous' : true;
        }

        return $described;
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    private function encode(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        );
    }
}
