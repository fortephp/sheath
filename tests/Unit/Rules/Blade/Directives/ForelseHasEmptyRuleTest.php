<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Directives\ForelseHasEmptyRule;

describe('ForelseHasEmptyRule', function (): void {
    it('passes for forelse with @empty clause', function (): void {
        $this->getRuleTester()->run(new ForelseHasEmptyRule, [
            'valid' => [
                '@forelse($items as $item)<li>{{ $item }}</li>@empty<p>No items</p>@endforelse',
                '@forelse ($users as $user){{ $user->name }}@empty No users found @endforelse',
            ],
        ]);
    });

    it('passes for regular foreach without @empty', function (): void {
        $this->getRuleTester()->run(new ForelseHasEmptyRule, [
            'valid' => [
                '@foreach($items as $item){{ $item }}@endforeach',
            ],
        ]);
    });

    it('fails for forelse without @empty clause', function (): void {
        $this->getRuleTester()->run(new ForelseHasEmptyRule, [
            'invalid' => [
                [
                    'code' => '@forelse($items as $item)<li>{{ $item }}</li>@endforelse',
                    'errors' => 1,
                ],
            ],
        ]);
    });
});
