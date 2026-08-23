<?php

declare(strict_types=1);

use Forte\Sheath\Rules\RuleRegistry;

it('reports invalid package modes through configuration validation', function (mixed $mode): void {
    config()->set('sheath.packageRequirementMode', $mode);
    app()->forgetInstance(RuleRegistry::class);

    $this->artisan('sheath:lint', ['--print-config' => true])
        ->assertFailed();
})->with([
    'wrong type' => true,
    'unknown value' => 'sometimes',
]);
