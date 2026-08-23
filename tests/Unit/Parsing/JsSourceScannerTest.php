<?php

declare(strict_types=1);

use Forte\Sheath\Parsing\JsSourceScanner;

describe('JsSourceScanner', function (): void {

    $codePositionAt = function (string $script): bool {
        $until = strpos($script, '|');

        expect($until)->not->toBeFalse();

        $source = str_replace('|', '', $script);

        return JsSourceScanner::classifyPositions($source, 0, [(int) $until])[(int) $until];
    };

    it('reports a plain code position as code', function (string $script) use ($codePositionAt): void {
        expect($codePositionAt($script))->toBeTrue();
    })->with([
        'assignment value' => 'var data = |x;',
        'after a closed string' => "var a = 'done'; var b = |x;",
        'after a closed template' => 'var a = `done`; var b = |x;',
        'after a closed block comment' => '/* setup */ var b = |x;',
        'after a line comment ends' => "// note\nvar b = |x;",
        'division is not a comment' => 'var a = 1 / 2; var b = |x;',
    ]);

    it('reports positions inside literals and comments as not code', function (string $script) use ($codePositionAt): void {
        expect($codePositionAt($script))->toBeFalse();
    })->with([
        'single-quoted string' => "var a = 'value |here';",
        'double-quoted string' => 'var a = "value |here";',
        'template literal' => 'var a = `value |here`;',
        'line comment' => 'var a = 1; // |note',
        'block comment' => 'var a = 1; /* |note */',
    ]);

    it('keeps a string open across an escaped terminator', function () use ($codePositionAt): void {
        expect($codePositionAt("var a = 'it\\'s |still a string';"))->toBeFalse();
    });

    it('ends a broken quoted string at a newline', function () use ($codePositionAt): void {
        expect($codePositionAt("var a = 'unterminated\nvar b = |x;"))->toBeTrue();
    });

    it('does not end a template literal at a newline or an escaped backtick', function () use ($codePositionAt): void {
        expect($codePositionAt("var a = `line one\nline |two`;"))->toBeFalse()
            ->and($codePositionAt('var a = `an \\` escaped |tick`;'))->toBeFalse();
    });

    it('skips opaque spans instead of scanning their text', function (): void {
        $source = "var a = X'X; var b = x;";
        $spanStart = (int) strpos($source, "X'X");

        $position = strlen($source) - 3;

        expect(JsSourceScanner::classifyPositions($source, 0, [$position], [[$spanStart, $spanStart + 3]])[$position])
            ->toBeTrue();
    });

    it('classifies multiple positions with one scan', function (): void {
        $source = "const a = 'inside'; const b = 1; // comment\nconst c = 2;";
        $positions = [
            (int) strpos($source, 'inside'),
            (int) strpos($source, 'const b'),
            (int) strpos($source, 'comment'),
            (int) strrpos($source, 'const c'),
        ];

        expect(JsSourceScanner::classifyPositions($source, 0, array_reverse($positions)))->toBe([
            $positions[0] => false,
            $positions[1] => true,
            $positions[2] => false,
            $positions[3] => true,
        ]);
    });

    it('skips several opaque spans while classifying later positions', function (): void {
        $source = "const a = X'X; const b = Y`Y; const c = 2;";
        $firstSpan = (int) strpos($source, "X'X");
        $secondSpan = (int) strpos($source, 'Y`Y');
        $positions = [
            (int) strpos($source, 'const b'),
            (int) strpos($source, 'const c'),
        ];

        expect(JsSourceScanner::classifyPositions($source, 0, $positions, [
            [$secondSpan, $secondSpan + 3],
            [$firstSpan, $firstSpan + 3],
        ]))->toBe([
            $positions[0] => true,
            $positions[1] => true,
        ]);
    });
});
