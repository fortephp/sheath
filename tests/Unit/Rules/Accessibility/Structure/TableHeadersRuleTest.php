<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Structure\TableHeadersRule;

describe('TableHeadersRule', function (): void {
    it('correlates exact predicates across separate table blocks', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'valid' => [
                '<table>@if($x)<tr><td>A</td></tr><tr><td>B</td></tr>@endif @if($x)<tr><th>H</th></tr>@endif</table>',
            ],
            'invalid' => [[
                'code' => '<table>@if($x)<tr><td>A</td></tr><tr><td>B</td></tr>@endif @if($y)<tr><th>H</th></tr>@endif</table>',
                'errors' => 1,
            ]],
        ]);
    });

    it('handles conditional role paths and capture boundaries', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'valid' => [
                '<table @if($x) role="presentation" @else role="none" @endif><tr><td>A</td></tr><tr><td>B</td></tr></table>',
                '<table role="presentation" @if($x) tabindex="0" @endif><tr><th>A</th></tr><tr><td>B</td></tr></table>',
            ],
            'invalid' => [[
                'code' => '<table><tr><th @if($x) role="cell" @else role="gridcell" @endif>A</th></tr><tr><td>B</td></tr></table>',
                'errors' => 1,
            ]],
        ]);

        $rule = new TableHeadersRule;
        $rule->setOptions(['requireScope' => true]);
        $this->getRuleTester()->run($rule, [
            'valid' => ['<table><tr><th scope="col">A</th></tr><tr><td>B</td></tr>@push("x")<tr><th>Captured</th></tr>@endpush</table>'],
        ]);
    });
    it('passes for tables with th elements', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'valid' => [
                '<table><tr><th>Name</th><th>Age</th></tr><tr><td>John</td><td>30</td></tr></table>',
                '<table><thead><tr><th>Col 1</th></tr></thead><tbody><tr><td>Data</td></tr></tbody></table>',
            ],
        ]);
    });

    it('uses the effective ARIA role when deciding whether th is a header', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'valid' => [
                '<table><tr><th role="columnheader">Name</th></tr><tr><td>Ada</td></tr></table>',
                '<table><tr><th role="rowheader">Name</th></tr><tr><td>Ada</td></tr></table>',
                '<table><tr><th role="bogus columnheader">Name</th></tr><tr><td>Ada</td></tr></table>',
                '<table><tr><th :role="$role">Name</th></tr><tr><td>Ada</td></tr></table>',
            ],
            'invalid' => [[
                'code' => '<table><tr><th role="cell">Name</th></tr><tr><td>Ada</td></tr></table>',
                'errors' => 1,
            ], [
                'code' => '<table><tr><th role="gridcell">Name</th></tr><tr><td>Ada</td></tr></table>',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for presentation tables (layout)', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'valid' => [
                '<table role="presentation"><tr><td>Layout</td></tr><tr><td>Content</td></tr></table>',
                '<table role="none"><tr><td>Layout</td></tr><tr><td>Content</td></tr></table>',
                '<table role="PRESENTATION"><tr><td>Layout</td></tr><tr><td>Content</td></tr></table>',
                '<table role="none presentation"><tr><td>Layout</td></tr><tr><td>Content</td></tr></table>',
                '<table role="presentation" aria-foo="x"><tr><td>Layout</td></tr><tr><td>Content</td></tr></table>',
                '<table role="presentation" contenteditable="false"><tr><td>Layout</td></tr><tr><td>Content</td></tr></table>',
                '<table role="presentation" contenteditable="bogus"><tr><td>Layout</td></tr><tr><td>Content</td></tr></table>',
                '<div contenteditable="true"><table role="presentation" contenteditable="false"><tr><td>Layout</td></tr><tr><td>Content</td></tr></table></div>',
                '<div contenteditable="true"><section contenteditable="false"><table role="presentation"><tr><td>Layout</td></tr><tr><td>Content</td></tr></table></section></div>',
                '<table @if($x) role="presentation" @else role="none" @endif contenteditable="false"><tr><td>Layout</td></tr><tr><td>Content</td></tr></table>',
                '<table @if($x) role="presentation" @else role="none" @endif contenteditable="bogus"><tr><td>Layout</td></tr><tr><td>Content</td></tr></table>',
            ],
        ]);
    });

    it('passes for single-row tables (likely not data tables)', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'valid' => [
                '<table><tr><td>Single row</td></tr></table>',
            ],
        ]);
    });

    it('does not sum rows from mutually exclusive branches', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'valid' => [
                '<table>@if($x)<tr><td>A</td></tr>@else<tr><td>B</td></tr>@endif</table>',
                '<table>@if($x)<tr><th>H</th></tr><tr><td>A</td></tr>@else<tr><td>Only</td></tr>@endif</table>',
                '<table>@foreach($rows as $row)<tr><td>{{ $row }}</td></tr>@break @endforeach</table>',
                '<table><tr><td>A</td></tr><tr><td>B</td></tr>@section("rows")<tr><th>H</th></tr>@show</table>',
            ],
        ]);
    });

    it('fails for data tables without th elements', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'invalid' => [
                [
                    'code' => '<table><tr><td>Name</td><td>Age</td></tr><tr><td>John</td><td>30</td></tr></table>',
                    'errors' => 1,
                ],
                [
                    'code' => '<table>@forelse($rows as $row)<tr><td>{{ $row }}</td></tr>@empty<tr><td>None</td></tr>@endforelse</table>',
                    'errors' => 1,
                ],
                [
                    'code' => '<table>@switch($view) @case("short")<tr><td>A</td></tr><tr><td>B</td></tr>@break @default<tr><td>C</td></tr><tr><td>D</td></tr>@endswitch</table>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('uses the first recognized role token', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'invalid' => [[
                'code' => '<table role="table presentation"><tr><td>Name</td></tr><tr><td>John</td></tr></table>',
                'errors' => 1,
            ]],
        ]);
    });

    it('requires a header on every conditional render path', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'valid' => [
                '<table>@if($compact)<tr><th>Short</th></tr>@else<tr><th>Full</th></tr>@endif<tr><td>A</td></tr><tr><td>B</td></tr></table>',
            ],
            'invalid' => [[
                'code' => '<table>@if($showHeader)<tr><th>Name</th></tr>@endif<tr><td>A</td></tr><tr><td>B</td></tr></table>',
                'errors' => 1,
            ]],
        ]);
    });

    it('requires repeated rows to contain a header on every iteration path', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'invalid' => [[
                'code' => '<table>@foreach($rows as $row)<tr>@if($show)<th>H</th>@endif<td>D</td></tr>@endforeach</table>',
                'errors' => 1,
            ]],
        ]);
    });

    it('applies presentational-role conflict resolution', function (string $code): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'focusable presentation table' => '<table role="presentation" tabindex="0"><tr><td>A</td></tr><tr><td>B</td></tr></table>',
        'integer-prefix tabindex' => '<table role="presentation" tabindex="0foo"><tr><td>A</td></tr></table>',
        'signed integer-prefix tabindex' => '<table role="presentation" tabindex="  +1x"><tr><td>A</td></tr></table>',
        'globally named none table' => '<table role="none" aria-label="Data"><tr><td>A</td></tr><tr><td>B</td></tr></table>',
        'editable presentation table' => '<table role="presentation" contenteditable="true"><tr><td>A</td></tr><tr><td>B</td></tr></table>',
        'conditionally editable presentation table' => '<table role="presentation" @if($x) contenteditable="false" @else contenteditable="true" @endif><tr><td>A</td></tr><tr><td>B</td></tr></table>',
        'inherited editable presentation table' => '<div contenteditable="true"><table role="presentation"><tr><td>A</td></tr><tr><td>B</td></tr></table></div>',
    ]);

    it('respects the configured data-table row threshold', function (): void {
        $rule = new TableHeadersRule;
        $rule->setOptions(['minRows' => 3]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<table><tr><td>One</td></tr><tr><td>Two</td></tr></table>',
            ],
            'invalid' => [[
                'code' => '<table><tr><td>One</td></tr><tr><td>Two</td></tr><tr><td>Three</td></tr></table>',
                'errors' => 1,
            ]],
        ]);
    });

    it('reports headers without scope when requireScope is enabled', function (): void {
        $rule = new TableHeadersRule;
        $rule->setOptions(['requireScope' => true]);

        $this->getRuleTester()->run($rule, [
            'invalid' => [
                [
                    'code' => '<table><tr><th>Name</th></tr><tr><td>John</td></tr></table>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('passes with scope when requireScope is enabled', function (): void {
        $rule = new TableHeadersRule;
        $rule->setOptions(['requireScope' => true]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<table><tr><th scope="col">Name</th></tr><tr><td>John</td></tr></table>',
                '<table><tr><th scope="row">Row 1</th><td>Data</td></tr><tr><th scope="row">Row 2</th><td>Data</td></tr></table>',
                '<table><tr><th scope="col" @if($x) scope="bad" @endif>Name</th></tr><tr><td>John</td></tr></table>',
                '<table><tr><th scope="col">Name</th></tr><tr><td>John</td></tr>@foreach($rows as $row)@break<tr><th>Unreachable</th></tr>@endforeach</table>',
                '<table>@if($full)<tr><th scope="col">Name</th></tr><tr><td>John</td></tr>@else<tr><th>Name</th></tr>@endif</table>',
            ],
        ]);
    });

    it('passes with runtime scope when requireScope is enabled', function (): void {
        $rule = new TableHeadersRule;
        $rule->setOptions(['requireScope' => true]);

        $this->getRuleTester()->run($rule, [
            'valid' => [
                '<table><tr><th scope="{{ $scope }}">Name</th></tr><tr><td>John</td></tr></table>',
                '<table><tr><th :scope="$scope">Name</th></tr><tr><td>John</td></tr></table>',
            ],
        ]);
    });

    it('reports invalid static scope values when requireScope is enabled', function (string $code): void {
        $rule = new TableHeadersRule;
        $rule->setOptions(['requireScope' => true]);

        $this->getRuleTester()->run($rule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'bare scope' => '<table><tr><th scope>Name</th></tr><tr><td>John</td></tr></table>',
        'empty scope' => '<table><tr><th scope="">Name</th></tr><tr><td>John</td></tr></table>',
        'unknown scope' => '<table><tr><th scope="column">Name</th></tr><tr><td>John</td></tr></table>',
    ]);

    it('handles nested tables correctly', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'valid' => [
                '<table><tr><th>Outer</th></tr><tr><td><table><tr><th>Inner</th></tr><tr><td>Data</td></tr></table></td></tr></table>',
            ],
        ]);
    });

    it('does not let a nested table provide headers or rows for its parent', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'valid' => [
                '<table><tr><td><table><tr><th>Inner</th></tr><tr><td>Data</td></tr></table></td></tr></table>',
            ],
            'invalid' => [[
                'code' => '<table><tr><td><table><tr><th>Inner</th></tr></table></td></tr><tr><td>Outer row</td></tr></table>',
                'errors' => 1,
            ]],
        ]);
    });

    it('does not report a nested scope issue again through its parent table', function (): void {
        $rule = new TableHeadersRule;
        $rule->setOptions(['requireScope' => true]);

        $this->getRuleTester()->run($rule, [
            'invalid' => [[
                'code' => '<table><tr><th scope="col">Outer</th></tr><tr><td><table><tr><th>Inner</th></tr><tr><td>Data</td></tr></table></td></tr></table>',
                'errors' => 1,
            ]],
        ]);
    });

    it('handles deeply nested elements when finding th elements', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'valid' => [
                '<table><thead><tr><th>Header</th></tr></thead><tbody><tr><td>Data</td></tr></tbody></table>',
                '<table><colgroup><col></colgroup><thead><tr><th scope="col">Col 1</th><th scope="col">Col 2</th></tr></thead><tbody><tr><td>A</td><td>B</td></tr></tbody></table>',
            ],
        ]);
    });

    it('finds th elements at various nesting depths', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'valid' => [
                '<table><tr><th>H1</th></tr><tr><td>D1</td></tr></table>',
                '<table><thead><tr><th>H1</th></tr></thead><tr><td>D1</td></tr></table>',
                '<table><thead><tr><th>H1</th></tr></thead><tbody><tr><td>D1</td></tr><tr><td>D2</td></tr></tbody></table>',
            ],
            'invalid' => [
                [
                    'code' => '<table><thead><tr><td>Not a header</td></tr></thead><tbody><tr><td>Data 1</td></tr><tr><td>Data 2</td></tr></tbody></table>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('counts tr elements at various depths correctly', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'valid' => [
                '<table><tbody><tr><th>H</th></tr><tr><td>D</td></tr></tbody></table>',
            ],
            'invalid' => [
                [
                    'code' => '<table><tbody><tr><td>R1</td></tr><tr><td>R2</td></tr></tbody></table>',
                    'errors' => 1,
                ],
            ],
        ]);
    });
});

describe('accessibility-tree table analysis', function (): void {
    it('does not count hidden headers', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'invalid' => [[
                'code' => '<table><tr><th aria-hidden="true">Name</th></tr><tr><td>A</td></tr></table>',
                'errors' => 1,
            ]],
        ]);
    });

    it('ignores tables and rows excluded from the accessibility tree', function (string $code): void {
        $this->getRuleTester()->run(new TableHeadersRule, ['valid' => [$code]]);
    })->with([
        '<table hidden><tr><td>A</td></tr><tr><td>B</td></tr></table>',
        '<table><tr hidden><td>A</td></tr><tr><td>B</td></tr></table>',
    ]);

    it('retains native th semantics when presentation conflicts', function (string $code): void {
        $this->getRuleTester()->run(new TableHeadersRule, ['valid' => [$code]]);
    })->with([
        '<table><tr><th role="none" tabindex="0">Name</th></tr><tr><td>A</td></tr></table>',
        '<table><tr><th role="presentation" aria-label="Name">Name</th></tr><tr><td>A</td></tr></table>',
    ]);

    it('stands down when conditional header or row exposure cannot be safely correlated', function (string $code): void {
        $this->getRuleTester()->run(new TableHeadersRule, ['valid' => [$code]]);
    })->with([
        '<table><tr><th @if($hide) hidden @endif>Name</th></tr><tr><td>A</td></tr></table>',
        '<table><tr @if($hide) aria-hidden="true" @endif><td>A</td></tr><tr><td>B</td></tr></table>',
    ]);
});

describe('reactive table analysis', function (): void {
    it('requires a durable header across Livewire and Alpine visibility states', function (string $code): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'Alpine show' => '<table><tr><th x-show="visible">Name</th></tr><tr><td>A</td></tr></table>',
        'Livewire show' => '<table><tr><th wire:show="visible">Name</th></tr><tr><td>A</td></tr></table>',
        'Livewire loading' => '<table><tr><th wire:loading>Name</th></tr><tr><td>A</td></tr></table>',
        'visibility on row' => '<table><tr wire:dirty><th>Name</th></tr><tr><td>A</td></tr><tr><td>B</td></tr></table>',
    ]);

    it('does not confuse reactive class mutations with visibility', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'valid' => [
                '<table><tr><th wire:loading.class="opacity-50">Name</th></tr><tr><td>A</td></tr></table>',
                '<table><tr><th x-show="true">Name</th></tr><tr><td>A</td></tr></table>',
                '<table><tr x-show="open"><th>Name</th></tr><tr x-show="open"><td>A</td></tr><tr x-show="open"><td>B</td></tr></table>',
                '<table><template x-if="open"><tbody><tr><th>Name</th></tr><tr><td>A</td></tr><tr><td>B</td></tr></tbody></template></table>',
            ],
        ]);
    });

    it('models zero, one, and repeated Alpine x-for table rows', function (): void {
        $this->getRuleTester()->run(new TableHeadersRule, [
            'valid' => [
                '<table><tr><th>Name</th></tr><template x-for="item in items"><tr><td x-text="item.name"></td></tr></template></table>',
                '<table><template x-for="item in items"><tr><th x-text="item.name"></th></tr></template></table>',
            ],
            'invalid' => [[
                'code' => '<table><template x-for="item in items"><tr><td x-text="item.name"></td></tr></template></table>',
                'errors' => 1,
            ]],
        ]);
    });
});
