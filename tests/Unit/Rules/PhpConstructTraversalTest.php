<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Php\NoDebugRule;
use Forte\Sheath\Rules\Blade\Php\NoLogicInViewsRule;
use Forte\Sheath\Rules\Blade\Php\NoPhpTagRule;
use Forte\Sheath\Rules\Blade\Php\ValidPhpSyntaxRule;

describe('PHP constructs inside opening tags', function (): void {
    it('applies PHP rules to constructs embedded between attributes', function (object $rule, string $code): void {
        $this->getRuleTester()->run($rule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'debug PHP tag' => [
            new NoDebugRule,
            '<div <?php dump($value); ?> class="panel"></div>',
        ],
        'debug PHP block' => [
            new NoDebugRule,
            '<div @php dd($value); @endphp class="panel"></div>',
        ],
        'complex PHP block' => [
            new NoLogicInViewsRule,
            '<div @php foreach ($items as $item) {} @endphp class="panel"></div>',
        ],
        'raw PHP tag' => [
            new NoPhpTagRule,
            '<div <?php echo $attributes; ?> class="panel"></div>',
        ],
        'invalid PHP tag' => [
            new ValidPhpSyntaxRule,
            '<div <?php if ( ?> class="panel"></div>',
        ],
    ]);
});
