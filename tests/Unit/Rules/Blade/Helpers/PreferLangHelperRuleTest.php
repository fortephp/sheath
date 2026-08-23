<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Helpers\PreferLangHelperRule;

describe('PreferLangHelperRule attributes', function (): void {
    it('reports and dangerously fixes standalone @lang directives inside attributes', function (): void {
        $this->getRuleTester()->run(new PreferLangHelperRule, [
            'invalid' => [[
                'code' => '<div title="@lang(\'messages.title\')"></div>',
                'errors' => [['hasFixAvailable' => true, 'hasDangerousFix' => true]],
                'output' => '<div title="{{ __(\'messages.title\') }}"></div>',
            ]],
        ]);
    });
});
