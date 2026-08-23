<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;
use Illuminate\View\Factory;

function compiled(string $template): string
{
    return trim(preg_replace('/\s+/', ' ', Blade::compileString($template)) ?? '');
}

/** @param array<string, mixed> $data */
function renderBladeString(string $template, array $data): string
{
    $php = Blade::compileString($template);

    extract($data + ['__env' => app(Factory::class)]);

    ob_start();
    try {
        eval('?>'.$php);

        return trim((string) ob_get_clean());
    } catch (Throwable $exception) {
        ob_end_clean();

        throw $exception;
    }
}

describe('claims the rule pages make about Blade', function (): void {
    it('blade-no-triple-echo: both forms compile to the same escaped echo', function (): void {
        expect(compiled('{{{ $name }}}'))->toBe(compiled('{{ $name }}'))
            ->and(compiled('{{ $name }}'))->toBe('<?php echo e($name); ?>');
    });

    it('security-no-raw-echo: {{ }} escapes and {!! !!} does not', function (): void {
        expect(compiled('{{ $v }}'))->toContain('e($v)')
            ->and(compiled('{!! $v !!}'))->toBe('<?php echo $v; ?>');
    });

    it('blade-no-php-echo: @php echo and {!! !!} both compile to a bare echo', function (): void {
        expect(compiled('@php echo $v; @endphp'))->toContain('echo $v;')
            ->and(compiled('@php echo $v; @endphp'))->not->toContain('e($v)')
            ->and(compiled('{!! $v !!}'))->not->toContain('e($v)');
    });

    it('blade-prefer-lang-helper: @lang does not escape, {{ __() }} does', function (): void {
        expect(compiled("@lang('k')"))->not->toContain('e(')
            ->and(compiled("{{ __('k') }}"))->toContain("e(__('k'))");
    });

    it('blade-prefer-endsection: every spelling of the terminator compiles alike', function (): void {
        $expected = compiled("@section('a') x @endsection");

        foreach (['@stop', '@Stop', '@STOP', '@stop()', '@endSection', '@ENDSECTION'] as $terminator) {
            expect(compiled("@section('a') x {$terminator}"))
                ->toBe($expected, "{$terminator} does not compile like @endsection");
        }
    });

    it('blade-prefer-endsection: a terminator with no space before it is not a directive', function (): void {
        expect(compiled("@section('a')x@stop"))->not->toContain('stopSection');
    });

    it('blade-prefer-endsection: @stop and @endsection compile identically, @show does not', function (): void {
        $stop = compiled("@section('a') x @stop");
        $endsection = compiled("@section('a') x @endsection");
        $show = compiled("@section('a') x @show");

        expect($stop)->toBe($endsection)
            ->and($stop)->toContain('stopSection()')
            ->and($show)->not->toBe($stop)
            ->and($show)->toContain('yieldSection()');
    });

    it('blade-no-php-tag: @php compiles to a raw PHP tag', function (): void {
        expect(compiled('@php $a = 1; @endphp'))->toContain('<?php')
            ->and(compiled('@php $a = 1; @endphp'))->toContain('$a = 1;');
    });

    it('blade-prefer-unless: @unless parenthesises its condition before negating', function (): void {
        expect(compiled('@unless ($a && $b) x @endunless'))->toContain('! ($a && $b)')
            ->and(compiled('@if (! $a && $b) x @endif'))->toContain('! $a && $b');
    });

    it('blade-prefer-forelse: emptiness is tracked by a flag, not a count', function (): void {
        $forelse = compiled('@forelse ($r as $x) a @empty b @endforelse');

        expect($forelse)->toContain('foreach')
            ->and($forelse)->toContain('$__empty_1')
            ->and($forelse)->not->toContain('count(');
    });

    it('blade-prefer-forelse: works on a generator, where count() raises a TypeError', function (): void {
        $generator = fn (): Generator => (function () {
            yield 'a';
            yield 'b';
        })();

        expect(renderBladeString('@forelse ($r as $x){{ $x }}@empty EMPTY @endforelse', ['r' => $generator()]))
            ->toBe('ab')
            ->and(renderBladeString('@forelse ($r as $x){{ $x }}@empty EMPTY @endforelse', ['r' => (function () {
                yield from [];
            })()]))
            ->toBe('EMPTY');

        expect(fn () => count($generator()))->toThrow(TypeError::class);
    });

    it('blade-prefer-forelse: PHP object truthiness is not collection emptiness', function (): void {
        $empty = collect([]);
        $truthyGuard = '@if ($r)@foreach ($r as $x){{ $x }}@endforeach @else EMPTY @endif';
        $forelse = '@forelse ($r as $x){{ $x }}@empty EMPTY @endforelse';

        expect(renderBladeString($truthyGuard, ['r' => $empty]))->toBe('')
            ->and(renderBladeString($forelse, ['r' => $empty]))->toBe('EMPTY');
    });

    it('blade-prefer-forelse: nullsafe guards and foreach have different null behavior', function (): void {
        $guard = '@if ($r?->isNotEmpty())@foreach ($r as $x){{ $x }}@endforeach @else EMPTY @endif';
        $forelse = '@forelse ($r as $x){{ $x }}@empty EMPTY @endforelse';

        expect(renderBladeString($guard, ['r' => null]))->toBe('EMPTY');

        set_error_handler(static fn (int $severity, string $message): never => throw new ErrorException($message, 0, $severity));

        try {
            expect(fn (): string => renderBladeString($forelse, ['r' => null]))->toThrow(ErrorException::class);
        } finally {
            restore_error_handler();
        }
    });

    it('blade-valid-directive-arguments: bare and next-line @vite calls omit the required entrypoint', function (): void {
        $bare = compiled('@vite');
        $nextLine = compiled("@vite\n(['resources/js/app.js'])");
        $sameLine = compiled("@vite('resources/js/app.js')");

        expect($bare)->toContain("app('Illuminate\\Foundation\\Vite')()")
            ->and($nextLine)->toContain("app('Illuminate\\Foundation\\Vite')()")
            ->and($nextLine)->toContain("(['resources/js/app.js'])")
            ->and($sameLine)->toContain("app('Illuminate\\Foundation\\Vite')('resources/js/app.js')")
            ->and(fn () => renderBladeString('@vite', []))->toThrow(ArgumentCountError::class);
    });

    it('blade-no-debug: @dd and @dump are real directives that call through', function (): void {
        expect(compiled('@dd($v)'))->toContain('dd($v)')
            ->and(compiled('@dump($v)'))->toContain('dump($v)');
    });

    it('blade-method-field: @method writes a hidden _method field', function (): void {
        expect(compiled("@method('DELETE')"))->toContain('method_field')
            ->and(compiled('@csrf'))->toContain('csrf_field');
    });

    it('blade-prefer-json-in-script: HTML escaping does not contain JavaScript comments', function (): void {
        $rendered = renderBladeString(
            '<script>/* {{ $note }} */</script>',
            ['note' => '*/ globalThis.pwned = true; /*'],
        );

        expect($rendered)->toBe('<script>/* */ globalThis.pwned = true; /* */</script>');
    });

    it('blade-unclosed-directives: a missing @endif leaves PHP that will not parse', function (): void {
        $broken = Blade::compileString('@if ($a) <p>x</p>');

        $parses = true;
        try {
            @token_get_all($broken, TOKEN_PARSE);
        } catch (Throwable) {
            $parses = false;
        }

        expect($parses)->toBeFalse()
            ->and($broken)->toContain('if($a):');
    });

    it('blade-component-self-closing: the two component forms compile alike', function (): void {
        expect(compiled('<x-mail::message />'))->toContain('mail::message')
            ->and(compiled('<x-mail::message></x-mail::message>'))->toContain('mail::message');
    });

    it('blade-component-tag-integrity: Alpine bindings on components require the double-colon escape', function (): void {
        $unescaped = Blade::compileString('<x-mail::message :class="{ danger: isDeleting }" />');
        $escaped = Blade::compileString('<x-mail::message ::class="{ danger: isDeleting }" />');
        $parses = static function (string $php): bool {
            try {
                token_get_all($php, TOKEN_PARSE);

                return true;
            } catch (Throwable) {
                return false;
            }
        };

        expect($parses($unescaped))->toBeFalse()
            ->and($parses($escaped))->toBeTrue()
            ->and($escaped)->toContain("':class' => '{ danger: isDeleting }'");
    });
});
