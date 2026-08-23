<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Directives\ForelseEmptyArgumentsRule;

describe('ForelseEmptyArgumentsRule', function (): void {
    it('passes for a bare @empty branch', function (): void {
        $this->getRuleTester()->run(new ForelseEmptyArgumentsRule, [
            'valid' => [
                "@forelse(\$users as \$user)\n<li>{{ \$user }}</li>\n@empty\n<li>No users</li>\n@endforelse",
            ],
        ]);
    });

    it('passes for a forelse without an empty branch', function (): void {
        $this->getRuleTester()->run(new ForelseEmptyArgumentsRule, [
            'valid' => [
                "@forelse(\$users as \$user)\n<li>{{ \$user }}</li>\n@endforelse",
            ],
        ]);
    });

    it('passes for standalone @empty conditionals outside forelse', function (): void {
        $this->getRuleTester()->run(new ForelseEmptyArgumentsRule, [
            'valid' => [
                "@empty(\$records)\n<p>Nothing recorded.</p>\n@endempty",
            ],
        ]);
    });

    it('fails for @empty with arguments inside forelse', function (): void {
        $this->getRuleTester()->run(new ForelseEmptyArgumentsRule, [
            'invalid' => [
                [
                    'code' => "@forelse(\$users as \$user)\n<li>{{ \$user }}</li>\n@empty(\$users)\n<li>No users</li>\n@endforelse",
                    'errors' => [
                        [
                            'line' => 3,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('fails for @empty with a space before the arguments', function (): void {
        $this->getRuleTester()->run(new ForelseEmptyArgumentsRule, [
            'invalid' => [
                [
                    'code' => "@forelse(\$users as \$user)\n<li>{{ \$user }}</li>\n@empty (\$users)\n<li>No users</li>\n@endforelse",
                    'errors' => [
                        ['line' => 3],
                    ],
                ],
            ],
        ]);
    });

    it('provides a dangerous fix rewriting to a bare @empty', function (): void {
        $this->getRuleTester()->run(new ForelseEmptyArgumentsRule, [
            'invalid' => [
                [
                    'code' => "@forelse(\$users as \$user)\n<li>{{ \$user }}</li>\n@empty(\$users)\n<li>No users</li>\n@endforelse",
                    'errors' => [
                        ['hasFixAvailable' => true, 'hasDangerousFix' => true],
                    ],
                    'output' => "@forelse(\$users as \$user)\n<li>{{ \$user }}</li>\n@empty\n<li>No users</li>\n@endforelse",
                ],
                [
                    'code' => "@forelse(\$users as \$user)\nx\n@empty (\$users)\ny\n@endforelse",
                    'errors' => [
                        ['hasFixAvailable' => true, 'hasDangerousFix' => true],
                    ],
                    'output' => "@forelse(\$users as \$user)\nx\n@empty\ny\n@endforelse",
                ],
            ],
        ]);
    });

    it('checks each forelse independently', function (): void {
        $this->getRuleTester()->run(new ForelseEmptyArgumentsRule, [
            'invalid' => [
                [
                    'code' => "@forelse(\$a as \$x)\nx\n@empty\nnone\n@endforelse\n@forelse(\$b as \$y)\ny\n@empty(\$b)\nnone\n@endforelse",
                    'errors' => [
                        ['line' => 8],
                    ],
                ],
            ],
        ]);
    });
});
