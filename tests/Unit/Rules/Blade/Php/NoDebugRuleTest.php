<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Php\NoDebugRule;

describe('NoDebugRule', function (): void {
    it('ignores non-debug calls and non-executable references to debug names', function (): void {
        $this->getRuleTester()->run(new NoDebugRule, [
            'valid' => [
                '<div>{{ $variable }}</div>',
                '<div>@if($condition) Content @endif</div>',
                '<div>{!! $html !!}</div>',
                '@foreach($items as $item) {{ $item }} @endforeach',
                '<div :title="dd(value)"></div>',
                '{{ date("Y-m-d") }}',
                '{{ strtoupper($name) }}',
                '{{ json_encode($data) }}',
                '{{ $renderer->dump() }}',
                '{{ $renderer?->ray($value) }}',
                '{{ DebugFormatter::print_r($value) }}',
                '@php $instance = new dump(); @endphp',
                '@php function dump($value) { return $value; } @endphp',
                '{{ App\\Support\\dump($value) }}',
                '@if($renderer->dump()) Content @endif',
                '@if($label === "call dump() later") Content @endif',
            ],
        ]);
    });

    it('detects every default debug directive and function', function (): void {
        $this->getRuleTester()->run(new NoDebugRule, [
            'invalid' => [
                ['code' => '@dd($variable)', 'errors' => 1],
                ['code' => '@dump($variable)', 'errors' => 1],
                ['code' => '{{ ray($variable) }}', 'errors' => 1],
                ['code' => '{{ dd($variable) }}', 'errors' => 1],
                ['code' => '{{ dump($variable) }}', 'errors' => 1],
                ['code' => '{{ var_dump($variable) }}', 'errors' => 1],
                ['code' => '{!! print_r($array, true) !!}', 'errors' => 1],
                ['code' => '{{ \\dump($value) }}', 'errors' => 1],
            ],
        ]);
    });

    it('detects debug functions inside directive arguments', function (string $code): void {
        $this->getRuleTester()->run(new NoDebugRule, [
            'invalid' => [['code' => $code, 'errors' => 1]],
        ]);
    })->with([
        'conditional' => '@if(dump($value)) yes @endif',
        'loop' => '@foreach(print_r($items, true) as $item) {{ $item }} @endforeach',
        'authorization' => "@can('view', dd(\$post)) yes @endcan",
    ]);

    it('reports multiple debug statements', function (): void {
        $this->getRuleTester()->run(new NoDebugRule, [
            'invalid' => [['code' => '@dd($a) @dump($b)', 'errors' => 2]],
        ]);
    });

    it('finds executable debug calls embedded in attributes', function (string $code): void {
        $this->getRuleTester()->run(new NoDebugRule, [
            'invalid' => [['code' => $code, 'errors' => 1]],
        ]);
    })->with([
        'escaped echo' => '<div title="{{ dd($value) }}"></div>',
        'raw echo' => '<div title="{!! dd($value) !!}"></div>',
        'bound component value' => '<x-card :title="dd($value)" />',
    ]);

    it('lets projects replace the debug directives and functions', function (): void {
        $rule = new NoDebugRule;
        $rule->setOptions([
            'directives' => ['csrf'],
            'functions' => ['inspect'],
        ]);

        $this->getRuleTester()->run($rule, [
            'valid' => ['@dd($value) {{ dump($value) }}'],
            'invalid' => [
                ['code' => '@csrf', 'errors' => 1],
                ['code' => '{{ inspect($value) }}', 'errors' => 1],
            ],
        ]);
    });

    it('normalizes an explicitly global configured function name before the source guard', function (): void {
        $rule = new NoDebugRule;
        $rule->setOptions([
            'directives' => [],
            'functions' => ['\\inspect'],
        ]);

        $this->getRuleTester()->run($rule, [
            'invalid' => [['code' => '{{ inspect($value) }}', 'errors' => 1]],
        ]);
    });

    it('detects debug calls inside PHP blocks', function (string $code): void {
        $this->getRuleTester()->run(new NoDebugRule, [
            'invalid' => [['code' => $code, 'errors' => 1]],
        ]);
    })->with([
        'dd' => '@php dd($user); @endphp',
        'dump' => '@php dump($items); @endphp',
        'var_dump' => "@php\n    var_dump(\$data);\n@endphp",
        'ray' => '@php ray($order)->color("red"); @endphp',
        'raw PHP tag' => '<?php var_dump($x); ?>',
    ]);

    it('ignores debug names in PHP strings, comments, and clean blocks', function (): void {
        $this->getRuleTester()->run(new NoDebugRule, [
            'valid' => [
                "@php \$dump = 'dd'; @endphp",
                '@php $label = "call dd() later"; @endphp',
                "@php // dd(\$user)\n\$x = 1; @endphp",
                '@php /* var_dump($x) */ $x = 1; @endphp',
                "{{ \$labels['dd('] ?? 'dump(' }}",
                '@php $total = $items->sum(); @endphp',
                '@php \Log::debug("User data", ["user" => $user]); @endphp',
            ],
        ]);
    });
});
