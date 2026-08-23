<?php

declare(strict_types=1);

namespace Forte\Sheath\Contracts;

use Forte\Sheath\Results\LintResult;

interface Reporter
{
    public function format(LintResult $result): string;

    /**
     * @param  array<LintResult>  $results
     */
    public function formatMany(array $results): string;
}
