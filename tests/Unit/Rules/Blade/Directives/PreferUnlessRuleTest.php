<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Directives\PreferUnlessRule;

describe('PreferUnlessRule', function (): void {
    it('passes for regular @if conditions without negation', function (): void {
        $this->getRuleTester()->run(new PreferUnlessRule, [
            'valid' => [
                '@if($isAdmin) Admin content @endif',
                '@if($user->isActive()) Active @endif',
                '@if($count > 0) Has items @endif',
                '@if($a && $b) Both true @endif',
            ],
        ]);
    });

    it('passes for @unless blocks', function (): void {
        $this->getRuleTester()->run(new PreferUnlessRule, [
            'valid' => [
                '@unless($isAdmin) Not admin @endunless',
                '@unless($disabled) Enabled @endunless',
            ],
        ]);
    });

    it('passes for @if with != or !== comparisons', function (): void {
        $this->getRuleTester()->run(new PreferUnlessRule, [
            'valid' => [
                '@if($status != "active") Inactive @endif',
                '@if($value !== null) Has value @endif',
                '@if($a != $b) Different @endif',
            ],
        ]);
    });

    it('fails for @if with simple negation', function (): void {
        $this->getRuleTester()->run(new PreferUnlessRule, [
            'invalid' => [
                [
                    'code' => '@if(!$isAdmin) Not admin @endif',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for @if with negation and method call', function (): void {
        $this->getRuleTester()->run(new PreferUnlessRule, [
            'invalid' => [
                [
                    'code' => '@if(!$user->isActive()) Inactive @endif',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for @if with negation inside parentheses', function (): void {
        $this->getRuleTester()->run(new PreferUnlessRule, [
            'invalid' => [
                [
                    'code' => '@if((!$disabled)) Enabled @endif',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('fails for @if with negation and space', function (): void {
        $this->getRuleTester()->run(new PreferUnlessRule, [
            'invalid' => [
                [
                    'code' => '@if( !$condition ) Content @endif',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('passes when the negation covers only part of the condition', function (string $code): void {
        $this->getRuleTester()->run(new PreferUnlessRule, ['valid' => [$code]]);
    })->with([
        'negated first of &&' => '@if (!$a && $b) x @endif',
        'negated first of ||' => '@if (!$a || $b) x @endif',
        'negated first of and' => '@if (!$a and $b) x @endif',
        'negated first of or' => '@if (!$a or $b) x @endif',
        'ternary after negation' => '@if (!$a ? $b : $c) x @endif',
        'null coalesce after negation' => '@if (!$a ?? true) x @endif',
        'parenthesized arm then &&' => '@if ((!$a) && $b) x @endif',
        'string containing operator' => '@if (!str_contains($x, "a && b") && $y) x @endif',
    ]);

    it('fails when the negation covers the entire condition', function (string $code): void {
        $this->getRuleTester()->run(new PreferUnlessRule, [
            'invalid' => [
                [
                    'code' => $code,
                    'errors' => 1,
                ],
            ],
        ]);
    })->with([
        'negated parenthesized group' => '@if (!($a && $b)) x @endif',
        'negated function call' => '@if (!in_array($x, ["a && b"])) x @endif',
        'negated nullsafe chain' => '@if (!$user?->isAdmin()) x @endif',
        'negated array access' => '@if (!$flags["active"]) x @endif',
    ]);
});
