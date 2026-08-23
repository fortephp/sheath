<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Focus\NoPositiveTabindexRule;

describe('NoPositiveTabindexRule', function (): void {
    it('passes for zero or negative tabindex', function (string $code): void {
        $this->getRuleTester()->run(new NoPositiveTabindexRule, ['valid' => [$code]]);
    })->with([
        'zero on div' => '<div tabindex="0">Focusable</div>',
        'negative one' => '<div tabindex="-1">Programmatically focusable</div>',
    ]);

    it('uses the first duplicate tabindex attribute on each render path', function (): void {
        $this->getRuleTester()->run(new NoPositiveTabindexRule, [
            'valid' => ['<div tabindex="-1" @if($x) tabindex="1" @endif>Content</div>'],
        ]);
    });

    it('passes when tabindex has no parseable integer prefix', function (): void {
        $this->getRuleTester()->run(new NoPositiveTabindexRule, [
            'valid' => ['<div tabindex="abc">Content</div>'],
        ]);
    });

    it('skips dynamic tabindex attributes', function (string $code): void {
        $this->getRuleTester()->run(new NoPositiveTabindexRule, ['valid' => [$code]]);
    })->with([
        'alpine bound' => '<div :tabindex="tabIndex">Content</div>',
        'blade ternary' => '<div tabindex="{{ $active ? 0 : -1 }}">Content</div>',
    ]);

    it('fails for positive tabindex values', function (string $code): void {
        $this->getRuleTester()->run(new NoPositiveTabindexRule, [
            'invalid' => [['code' => $code, 'errors' => 1]],
        ]);
    })->with([
        'integer' => ['<div tabindex="1">Content</div>'],
        'leading plus sign' => ['<div tabindex="+5">Content</div>'],
        'decimal prefix' => ['<div tabindex="1.5">Content</div>'],
        'exponent prefix' => ['<div tabindex="1e2">Content</div>'],
        'leading ASCII whitespace' => ["<div tabindex=\"\t 7junk\">Content</div>"],
    ]);

    it('detects multiple positive tabindex values', function (): void {
        $this->getRuleTester()->run(new NoPositiveTabindexRule, [
            'invalid' => [[
                'code' => '<div tabindex="1">First</div><div tabindex="2">Second</div>',
                'errors' => 2,
            ]],
        ]);
    });

    it('provides fix to change positive tabindex to zero', function (string $code): void {
        $this->getRuleTester()->run(new NoPositiveTabindexRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [['hasDangerousFix' => true]],
                'hasFix' => true,
            ]],
        ]);
    })->with([
        'double quotes' => ['<div tabindex="1">Content</div>'],
        'single quotes' => ["<div tabindex='2'>Content</div>"],
    ]);

    it('withholds tab-order changes unless dangerous fixes are enabled', function (): void {
        $code = '<div tabindex="3">Content</div>';
        $tester = $this->getRuleTester();

        expect($tester->fix(new NoPositiveTabindexRule, $code))->toBe($code)
            ->and($tester->fix(new NoPositiveTabindexRule, $code, dangerous: true))
            ->toBe('<div tabindex="0">Content</div>');
    });
});
