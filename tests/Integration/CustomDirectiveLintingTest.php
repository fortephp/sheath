<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Illuminate\Support\Facades\Blade;

function lintWithAppDirectives(string $template): array
{
    $linter = app(Linter::class);

    $config = Config::make([
        'preset' => 'empty',
        'rules' => ['blade-unclosed-directives' => 'error'],
    ]);

    return $linter->lint($template, 'test.blade.php', $config)->violations;
}

it('does not report a closed custom block directive as unclosed', function (): void {
    Blade::if('subscribed', fn () => true);

    expect(lintWithAppDirectives('@subscribed <p>Thanks</p> @endsubscribed'))->toBeEmpty();
});

it('reports an unclosed custom block directive', function (): void {
    Blade::if('premium', fn () => true);

    $violations = lintWithAppDirectives('@premium <p>Members only</p>');

    expect($violations)->toHaveCount(1);
});

it('validates malformed arguments when a custom directive remains uncompiled', function (): void {
    Blade::directive('greet', fn (string $expression): string => $expression);

    $linter = app(Linter::class);
    $config = Config::make([
        'preset' => 'empty',
        'rules' => ['blade-valid-directive-arguments' => 'error'],
    ]);

    $violations = $linter->lint(
        '@greet(name:)',
        'test.blade.php',
        $config,
    )->violations;
    $valid = $linter->lint('@greet("<?php")', 'test.blade.php', $config);

    expect($violations)->toHaveCount(1)
        ->and($valid->violations)->toBeEmpty();
});
