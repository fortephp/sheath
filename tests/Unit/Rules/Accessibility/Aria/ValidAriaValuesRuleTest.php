<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Aria\ValidAriaValuesRule;

it('accepts valid static ARIA grammars, empty optionals, and dynamic values', function (string $code): void {
    $this->getRuleTester()->run(new ValidAriaValuesRule, ['valid' => [$code]]);
})->with([
    '<div aria-busy="true"></div>',
    '<div aria-expanded="undefined"></div>',
    '<div aria-checked="mixed"></div>',
    '<div aria-current="page"></div>',
    '<div aria-relevant="additions text"></div>',
    '<div aria-controls="one two"></div>',
    '<div aria-valuenow="-1.25e2"></div>',
    '<div aria-colcount="-1"></div>',
    '<div aria-rowspan="0"></div>',
    '<div aria-level="01"></div>',
    '<div aria-level="+001"></div>',
    '<div aria-rowspan="-0"></div>',
    '<div aria-level="999999999999999999999999999999"></div>',
    '<div aria-expanded=""></div>',
    '<div aria-expanded="{{ $expanded }}"></div>',
    '<div :aria-expanded="expanded"></div>',
    '<div x-bind:aria-expanded="expanded"></div>',
    '<div wire:bind:aria-expanded="expanded"></div>',
]);

it('rejects invalid static ARIA values and integer boundaries', function (string $code): void {
    $this->getRuleTester()->run(new ValidAriaValuesRule, [
        'invalid' => [['code' => $code, 'errors' => 1]],
    ]);
})->with([
    '<div aria-busy="yes"></div>',
    '<div aria-autocomplete="popup"></div>',
    '<div aria-relevant="additions bogus"></div>',
    '<div aria-details="one two"></div>',
    '<div aria-valuenow="INF"></div>',
    '<div aria-valuenow="NaN"></div>',
    '<div aria-level="0"></div>',
    '<div aria-colcount="0"></div>',
    '<div aria-colcount="-2"></div>',
    '<div aria-rowspan="-1"></div>',
    '<div aria-rowindex="1.5"></div>',
    '<div hidden aria-busy="yes"></div>',
]);
