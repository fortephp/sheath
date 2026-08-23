<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Focus\NoAccesskeyRule;

describe('NoAccesskeyRule', function (): void {
    it('reports multiple elements with accesskey', function (): void {
        $this->getRuleTester()->run(new NoAccesskeyRule, [
            'invalid' => [[
                'code' => '<a accesskey="h" href="/">Home</a><a accesskey="c" href="/contact">Contact</a>',
                'errors' => 2,
            ]],
        ]);
    });

    it('provides a fix to remove a static accesskey attribute', function (): void {
        $this->getRuleTester()->run(new NoAccesskeyRule, [
            'invalid' => [[
                'code' => '<a href="/home" accesskey="h">Home</a>',
                'errors' => 1,
                'hasFix' => true,
            ]],
        ]);
    });

    it('reports dynamic accesskey values without offering a fix', function (string $code): void {
        $this->getRuleTester()->run(new NoAccesskeyRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
                'hasFix' => false,
            ]],
        ]);
    })->with([
        'blade echo' => '<button accesskey="{{ $key }}" type="button">Submit</button>',
        'echo beside static text' => '<a href="/home" accesskey="{{ $prefix }}h">Home</a>',
    ]);
});
