<?php

declare(strict_types=1);

use Forte\Sheath\Parsing\PhpSource;

describe('tokenize', function (): void {
    it('returns tokens that rebuild the expression byte for byte', function (string $expression): void {
        $tokens = PhpSource::tokenize($expression);

        expect($tokens)->not->toBeNull();

        $rebuilt = '';

        foreach ($tokens as $token) {
            $rebuilt .= is_array($token) ? $token[1] : $token;
        }

        expect($rebuilt)->toBe($expression);
    })->with([
        'plain call' => "route('home', ['id' => 1])",
        'string with comma and paren' => "'a, (b'",
        'interpolation braces' => '"prefix {$name} suffix"',
        'whitespace preserved' => "  \$a  ,\n  \$b  ",
        'attribute syntax' => '#[Attr] fn () => 1',
    ]);

    it('refuses an expression carrying a close tag', function (): void {
        expect(PhpSource::tokenize('$a ?> <?php $b'))->toBeNull();
    });

    it('returns an empty token list for an empty expression', function (): void {
        expect(PhpSource::tokenize(''))->toBe([]);
    });
});

describe('parses', function (): void {
    it('accepts a valid fragment', function (): void {
        expect(PhpSource::parses('echo $a'))->toBeTrue();
    });

    it('rejects an unterminated string', function (): void {
        expect(PhpSource::parses("echo 'abc"))->toBeFalse();
    });

    it('rejects unbalanced parentheses', function (): void {
        expect(PhpSource::parses('foo(1, 2'))->toBeFalse();
    });
});

describe('firstGlobalFunctionCall', function (): void {
    it('finds unqualified and explicitly global calls case-insensitively', function (): void {
        expect(PhpSource::firstGlobalFunctionCall('before(); DUMP($value); after();', ['dump']))
            ->toBe('dump')
            ->and(PhpSource::firstGlobalFunctionCall('\\csrf_field()', ['csrf_field']))
            ->toBe('csrf_field');
    });

    it('ignores non-call uses and calls in non-global contexts', function (string $code): void {
        expect(PhpSource::firstGlobalFunctionCall($code, ['dump']))->toBeNull();
    })->with([
        'instance method' => '$renderer->dump()',
        'nullsafe method' => '$renderer?->dump()',
        'static method' => 'Renderer::dump()',
        'constructor' => 'new dump()',
        'declaration' => 'function dump() {}',
        'qualified function' => 'App\\Support\\dump()',
        'fully qualified function' => '\\App\\Support\\dump()',
        'function name in a string' => "'dump()'",
        'function name in a comment' => '/* dump() */ value()',
        'bare constant' => 'dump;',
    ]);

    it('returns null for untokenizable input or an empty target list', function (): void {
        expect(PhpSource::firstGlobalFunctionCall('$a ?>', ['dump']))->toBeNull()
            ->and(PhpSource::firstGlobalFunctionCall('dump()', []))->toBeNull();
    });
});

describe('parseError', function (): void {
    it('returns null for a snippet that parses', function (): void {
        expect(PhpSource::parseError('<?php echo 1;'))->toBeNull();
    });

    it('returns the parser message for a snippet that does not', function (): void {
        expect(PhpSource::parseError('<?php echo (;'))->toBeString();
    });

    it('reports an empty stream as invalid', function (): void {
        expect(PhpSource::parseError(''))->not->toBeNull();
    });
});

describe('nestingDelta', function (): void {
    it('counts plain brackets', function (): void {
        expect(PhpSource::nestingDelta('('))->toBe(1)
            ->and(PhpSource::nestingDelta('['))->toBe(1)
            ->and(PhpSource::nestingDelta('{'))->toBe(1)
            ->and(PhpSource::nestingDelta(')'))->toBe(-1)
            ->and(PhpSource::nestingDelta(']'))->toBe(-1)
            ->and(PhpSource::nestingDelta('}'))->toBe(-1)
            ->and(PhpSource::nestingDelta(','))->toBe(0);
    });

    it('counts interpolation and attribute openers, which close with a plain brace', function (): void {
        expect(PhpSource::nestingDelta([T_CURLY_OPEN, '{', 1]))->toBe(1)
            ->and(PhpSource::nestingDelta([T_DOLLAR_OPEN_CURLY_BRACES, '${', 1]))->toBe(1)
            ->and(PhpSource::nestingDelta([T_ATTRIBUTE, '#[', 1]))->toBe(1)
            ->and(PhpSource::nestingDelta([T_STRING, 'foo', 1]))->toBe(0);
    });
});

describe('innerArguments', function (): void {
    it('strips the outer parentheses and surrounding whitespace', function (): void {
        expect(PhpSource::innerArguments("('home', ['id' => 1])"))->toBe("'home', ['id' => 1]")
            ->and(PhpSource::innerArguments("  ( 'home' )  "))->toBe("'home'");
    });

    it('returns unparenthesized text trimmed', function (): void {
        expect(PhpSource::innerArguments("'home'"))->toBe("'home'")
            ->and(PhpSource::innerArguments('(a'))->toBe('(a')
            ->and(PhpSource::innerArguments('a)'))->toBe('a)');
    });

    it('returns null when there is nothing inside', function (): void {
        expect(PhpSource::innerArguments(null))->toBeNull()
            ->and(PhpSource::innerArguments(''))->toBeNull()
            ->and(PhpSource::innerArguments('   '))->toBeNull()
            ->and(PhpSource::innerArguments('()'))->toBeNull()
            ->and(PhpSource::innerArguments('(  )'))->toBeNull();
    });
});

describe('splitTopLevel', function (): void {
    it('splits only on top-level commas', function (): void {
        expect(PhpSource::splitTopLevel("'a,b', [1, 2], call(3, 4)"))
            ->toBe(["'a,b'", '[1, 2]', 'call(3, 4)']);
    });

    it('keeps each part as written apart from surrounding whitespace', function (): void {
        expect(PhpSource::splitTopLevel("  \$a . 'x'  ,  \$b  "))
            ->toBe(["\$a . 'x'", '$b']);
    });

    it('is not fooled by interpolation braces closing with a plain brace', function (): void {
        expect(PhpSource::splitTopLevel('"{$a}", $b'))->toBe(['"{$a}"', '$b']);
    });

    it('returns null for unbalanced input', function (string $expression): void {
        expect(PhpSource::splitTopLevel($expression))->toBeNull();
    })->with([
        'stray closer' => 'foo), $b',
        'unclosed opener' => 'foo(1, 2',
    ]);

    it('keeps an unterminated string as one part, comma and all', function (): void {
        expect(PhpSource::splitTopLevel("'abc, def"))->toBe(["'abc, def"]);
    });
});

describe('literalString', function (): void {
    it('reads a plain quoted string', function (): void {
        expect(PhpSource::literalString("'hello'"))->toBe('hello')
            ->and(PhpSource::literalString('"hello"'))->toBe('hello');
    });

    it('unescapes only what single quotes escape', function (): void {
        expect(PhpSource::literalString("'it\\'s'"))->toBe("it's")
            ->and(PhpSource::literalString("'a\\\\b'"))->toBe('a\\b')
            ->and(PhpSource::literalString("'a\\nb'"))->toBe('a\\nb');
    });

    it('ignores surrounding whitespace and comments', function (): void {
        expect(PhpSource::literalString("  'x' /* note */"))->toBe('x');
    });

    it('returns null for anything computed', function (string $expression): void {
        expect(PhpSource::literalString($expression))->toBeNull();
    })->with([
        'concatenation' => "'a' . \$b",
        'variable' => '$name',
        'double-quoted with variable' => '"a $b"',
        'double-quoted with escape' => '"a\\n"',
        'two strings' => "'a' 'b'",
    ]);
});

describe('stripLiterals', function (): void {
    it('blanks string bodies but keeps the code around them', function (): void {
        expect(PhpSource::stripLiterals("if (\$a == 'for while dd') { run(); }"))
            ->toBe("if (\$a == '') { run(); }");
    });

    it('drops comments while keeping tokens apart', function (): void {
        expect(PhpSource::stripLiterals('for/* x */($i = 0;;) {}'))
            ->toBe('for ($i = 0;;) {}');
    });

    it('preserves the line count of dropped tokens', function (): void {
        $code = "\$a = 'one\ntwo';\n\$b = 1;";

        expect(substr_count(PhpSource::stripLiterals($code), "\n"))
            ->toBe(substr_count($code, "\n"));
    });

    it('keeps interpolated expressions visible', function (): void {
        expect(PhpSource::stripLiterals('$a = "x {$b->c} y";'))
            ->toContain('{$b->c}');
    });
});
