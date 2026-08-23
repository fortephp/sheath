<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Blade\Php\ValidPhpSyntaxRule;

describe('blade-valid-php-syntax', function (): void {
    it('accepts valid PHP regions', function (string $code): void {
        $this->getRuleTester()->run(new ValidPhpSyntaxRule, ['valid' => [$code]]);
    })->with([
        'block statements' => ['@php $total = 1 + 2; echo $total; @endphp'],
        'raw tag' => ['<?php $total = 1 + 2; ?>'],
        'unclosed raw tag at end of file' => ['<?php $total = 1 + 2;'],
        'short echo' => ['<?= $total ?>'],
        'comments and heredoc' => ["@php\n// comment\n\$value = <<<'TXT'\nhello\nTXT;\n@endphp"],
        'control flow spanning blocks' => ['@php if ($ok): @endphp<p>Yes</p>@php endif; @endphp'],
        'control flow spanning raw tags' => ['<?php if ($ok): ?><p>Yes</p><?php endif; ?>'],
        'raw opener closed by Blade' => ['<?php if ($ok): ?><p>Yes</p>@endif'],
        'Blade opener closed by raw PHP' => ['@if($ok)<p>Yes</p><?php endif; ?>'],
        '@php opener closed by Blade' => ['@php if ($ok): @endphp<p>Yes</p>@endif'],
    ]);

    it('reports invalid PHP regions', function (string $code): void {
        $this->getRuleTester()->run(new ValidPhpSyntaxRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'invalid assignment block' => ['@php $value = ; @endphp'],
        'unfinished condition block' => ['@php if ( @endphp'],
        'invalid echo block' => ['@php echo ; @endphp'],
        'invalid raw assignment' => ['<?php $value = ; ?>'],
        'invalid short echo' => ['<?= ; ?>'],
    ]);

    it('reports an unmatched opener on the PHP region that owns it', function (): void {
        $this->getRuleTester()->run(new ValidPhpSyntaxRule, [
            'invalid' => [[
                'code' => "@php\nif (true) {\n@endphp\ntext\n@php\n\$value = 1;\n@endphp",
                'errors' => [['line' => 1]],
            ]],
        ]);
    });
});
