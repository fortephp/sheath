<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Headings\NoHeadingInsideButtonRule;

describe('NoHeadingInsideButtonRule', function (): void {
    it('fails for h1 inside button', function (): void {
        $this->getRuleTester()->run(new NoHeadingInsideButtonRule, [
            'invalid' => [
                [
                    'code' => '<button><h1>Click me</h1></button>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('reports uppercase HTML button tags exactly once', function (): void {
        $this->getRuleTester()->run(new NoHeadingInsideButtonRule, [
            'invalid' => [[
                'code' => '<BUTTON><H2>Click me</H2></BUTTON>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for heading inside element with role="button"', function (): void {
        $this->getRuleTester()->run(new NoHeadingInsideButtonRule, [
            'invalid' => [
                [
                    'code' => '<div role="button"><h2>Click</h2></div>',
                    'errors' => 1,
                ],
                [
                    'code' => '<div role="future-role button checkbox"><h2>Click</h2></div>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('reports headings when a conditional static role can make the ancestor a button', function (): void {
        $this->getRuleTester()->run(new NoHeadingInsideButtonRule, [
            'invalid' => [[
                'code' => '<div @if($acts) role="button" @endif><h2>Action</h2></div>',
                'errors' => 1,
            ]],
        ]);
    });

    it('does not split fallback roles on vertical tab', function (): void {
        $this->getRuleTester()->run(new NoHeadingInsideButtonRule, [
            'valid' => ["<div role=\"future-role\x0Bbutton\"><h2>Action</h2></div>"],
        ]);
    });

    it('fails for deeply nested heading inside button', function (): void {
        $this->getRuleTester()->run(new NoHeadingInsideButtonRule, [
            'invalid' => [
                [
                    'code' => '<button><span><div><h3>Nested</h3></div></span></button>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('reports each effective heading only once across nested buttons', function (): void {
        $this->getRuleTester()->run(new NoHeadingInsideButtonRule, [
            'invalid' => [[
                'code' => '<button><div role="button"><h2>Title</h2></div></button>',
                'errors' => 1,
            ]],
        ]);
    });

    it('ignores inert template contents inside buttons', function (): void {
        $this->getRuleTester()->run(new NoHeadingInsideButtonRule, [
            'valid' => ['<button><template><h2>Title</h2></template>Save</button>'],
        ]);
    });

    it('reports authored aria headings inside buttons', function (): void {
        $this->getRuleTester()->run(new NoHeadingInsideButtonRule, [
            'invalid' => [
                [
                    'code' => '<button><span role="heading" aria-level="2">Title</span></button>',
                    'errors' => 1,
                ],
                [
                    'code' => '<button><h2 role="heading" :aria-level="$level">Title</h2></button>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('ignores exhaustive conditional presentational-role paths inside buttons', function (): void {
        $this->getRuleTester()->run(new NoHeadingInsideButtonRule, [
            'valid' => [
                '<button><h4 @if($x) role="none" @else role="presentation" @endif>B</h4></button>',
                '<button><h4 @if($x) hidden @else inert @endif>B</h4></button>',
            ],
        ]);
    });

    it('uses the browser-effective first role and respects capture boundaries', function (): void {
        $this->getRuleTester()->run(new NoHeadingInsideButtonRule, [
            'valid' => [
                '<div role="checkbox" role="button"><h2>Title</h2></div>',
                '<button>@push("x")<h2>Captured</h2>@endpush Save</button>',
            ],
        ]);
    });
});
