<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Directives\NoElseConditionRule;

describe('NoElseConditionRule', function (): void {
    it('passes for plain @else', function (): void {
        $this->getRuleTester()->run(new NoElseConditionRule, [
            'valid' => [
                "@if(\$a)\none\n@else\ntwo\n@endif",
            ],
        ]);
    });

    it('passes for @elseif', function (): void {
        $this->getRuleTester()->run(new NoElseConditionRule, [
            'valid' => [
                "@if(\$a)\none\n@elseif(\$b)\ntwo\n@endif",
            ],
        ]);
    });

    it('passes for prose after @else on the next line', function (): void {
        $this->getRuleTester()->run(new NoElseConditionRule, [
            'valid' => [
                "@if(\$a)\na\n@else\nif you want, read more\n@endif",
                "@if(\$a)\na\n@else\nif (something else applies) see the docs\n@endif",
            ],
        ]);
    });

    it('passes for @else followed by text that is not if(', function (): void {
        $this->getRuleTester()->run(new NoElseConditionRule, [
            'valid' => [
                "@if(\$a)\na\n@else show the fallback\n@endif",
            ],
        ]);
    });

    it('fails for @else with arguments', function (): void {
        $this->getRuleTester()->run(new NoElseConditionRule, [
            'invalid' => [
                [
                    'code' => "@if(\$a)\none\n@else(\$b)\ntwo\n@endif",
                    'errors' => [
                        [
                            'line' => 3,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('fails for @else if with a space', function (): void {
        $this->getRuleTester()->run(new NoElseConditionRule, [
            'invalid' => [
                [
                    'code' => "@if(\$a)\na\n@else if(\$b)\nb\n@endif",
                    'errors' => [
                        [
                            'line' => 3,
                        ],
                    ],
                ],
            ],
        ]);
    });

    it('fails for @else if with a space before the parentheses', function (): void {
        $this->getRuleTester()->run(new NoElseConditionRule, [
            'invalid' => [
                [
                    'code' => "@if(\$a)\na\n@else if (\$b)\nb\n@endif",
                    'errors' => [
                        ['line' => 3],
                    ],
                ],
            ],
        ]);
    });

    it('detects case-insensitive if after immediate HTML whitespace', function (string $code): void {
        $this->getRuleTester()->run(new NoElseConditionRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'uppercase' => "@if(\$a)\na\n@else IF(\$b)\nb\n@endif",
        'newline' => "@if(\$a)\na\n@else\nif(\$b)\nb\n@endif",
        'form feed' => "@if(\$a)\na\n@else\fIf (\$b)\nb\n@endif",
    ]);

    it('does not mistake identifiers, escaped directives, or invalid prose for an else-if', function (): void {
        $this->getRuleTester()->run(new NoElseConditionRule, [
            'valid' => [
                "@if(\$a)\na\n@else iffy(\$b) text\n@endif",
                "@if(\$a)\na\n@else @@if(\$b) text\n@endif",
                "@if(\$a)\na\n@else\nif (something else applies) see docs\n@endif",
            ],
        ]);
    });

    it('provides a dangerous fix rewriting @else(...) to @elseif(...)', function (): void {
        $this->getRuleTester()->run(new NoElseConditionRule, [
            'invalid' => [
                [
                    'code' => "@if(\$a)\none\n@else(\$b)\ntwo\n@endif",
                    'errors' => [
                        ['hasFixAvailable' => true, 'hasDangerousFix' => true],
                    ],
                    'output' => "@if(\$a)\none\n@elseif(\$b)\ntwo\n@endif",
                ],
            ],
        ]);
    });

    it('offers no fix for the @else if spelling', function (): void {
        $this->getRuleTester()->run(new NoElseConditionRule, [
            'invalid' => [
                [
                    'code' => "@if(\$a)\na\n@else if(\$b)\nb\n@endif",
                    'errors' => [
                        ['hasFixAvailable' => false],
                    ],
                ],
            ],
        ]);
    });
});
