<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\DefaultConfigFactory;
use Forte\Sheath\Configuration\RulePreset;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\RuleRegistry;

function directiveAwareLinter(): Linter
{
    $registry = new RuleRegistry;
    $registry->discoverRules(__DIR__.'/../../src/Rules');

    return new Linter($registry);
}

/** @param  array<string, string>  $ruleOverrides */
function violationsFor(string $code, string $ruleId, array $ruleOverrides = []): int
{
    $registry = new RuleRegistry;
    $registry->discoverRules(__DIR__.'/../../src/Rules');

    $config = DefaultConfigFactory::resolve(
        [
            'preset' => [RulePreset::STRICT->value, RulePreset::STYLISTIC->value],
            'rules' => $ruleOverrides,
        ],
        $registry
    );

    $count = 0;
    foreach ((new Linter($registry))->lint($code, 'test.blade.php', $config)->violations as $violation) {
        if ($violation->ruleId === $ruleId) {
            $count++;
        }
    }

    return $count;
}

describe('rules see through Blade control flow', function (): void {
    it('reports a defect whether or not a directive wraps it', function (string $ruleId, string $plain, string $wrapped, array $ruleOverrides = []): void {
        expect(violationsFor($plain, $ruleId, $ruleOverrides))->toBeGreaterThan(0, "not reported in plain markup: {$plain}")
            ->and(violationsFor($wrapped, $ruleId, $ruleOverrides))->toBeGreaterThan(0, "not reported inside a directive: {$wrapped}");
    })->with([
        'alt text in a loop' => [
            'a11y-alt-text',
            '<div><img src="a.png"></div>',
            '<div>@foreach ($images as $i)<img src="{{ $i }}">@endforeach</div>',
        ],
        'anchor content behind an if' => [
            'a11y-anchor-content',
            '<div><a href="/x"></a></div>',
            '<div>@if ($show)<a href="/x"></a>@endif</div>',
        ],
        'nested interactive behind an if' => [
            'best-practices-no-nested-interactive',
            '<a href="/x"><button type="button">b</button></a>',
            '<a href="/x">@if ($show)<button type="button">b</button>@endif</a>',
        ],
        'heading in a button behind an if' => [
            'a11y-no-heading-inside-button',
            '<button type="button"><h2>t</h2></button>',
            '<button type="button">@if ($show)<h2>t</h2>@endif</button>',
        ],
        'orphan li in a loop' => [
            'best-practices-require-li-container',
            '<div><li>x</li></div>',
            '<div>@foreach ($r as $i)<li>{{ $i }}</li>@endforeach</div>',
            ['a11y-list-semantics' => 'off'],
        ],
        'list semantics in a loop' => [
            'a11y-list-semantics',
            '<div><li>x</li></div>',
            '<div>@foreach ($r as $i)<li>{{ $i }}</li>@endforeach</div>',
        ],
        'target blank behind an if' => [
            'security-no-target-blank',
            '<a href="/x" target="_blank" rel="opener">y</a>',
            '@if ($ext)<a href="/x" target="_blank" rel="opener">y</a>@endif',
        ],
        'button type in a loop' => [
            'best-practices-button-type',
            '<button>go</button>',
            '@foreach ($b as $x)<button>{{ $x }}</button>@endforeach',
        ],
        'missing csrf behind an if' => [
            'security-csrf-field',
            '<form method="POST"><input name="a"></form>',
            '@if ($show)<form method="POST"><input name="a"></form>@endif',
        ],
        'duplicate id across an if' => [
            'best-practices-no-duplicate-id',
            '<div id="a"></div><div id="a"></div>',
            '@if ($x)<div id="a"></div>@endif<div id="a"></div>',
        ],
        'unlabelled input in a loop' => [
            'a11y-form-label',
            '<form><input type="text" name="a"></form>',
            '<form>@foreach ($f as $x)<input type="text" name="{{ $x }}">@endforeach</form>',
        ],
    ]);

    it('counts rows a loop renders when deciding a table is a data table', function (string $code, int $expected): void {
        expect(violationsFor($code, 'a11y-table-headers'))->toBe($expected);
    })->with([
        'single row' => ['<table><tr><td>a</td></tr></table>', 0],
        'two literal rows without headers' => ['<table><tr><td>a</td></tr><tr><td>b</td></tr></table>', 1],
        'rows from a loop without headers' => ['<table>@foreach ($r as $x)<tr><td>{{ $x }}</td></tr>@endforeach</table>', 1],
        'rows from a loop with a header row' => ['<table><tr><th scope="col">H</th></tr>@foreach ($r as $x)<tr><td>{{ $x }}</td></tr>@endforeach</table>', 0],
        'header inside the loop' => ['<table>@foreach ($r as $x)<tr><th scope="row">{{ $x }}</th><td>y</td></tr>@endforeach</table>', 0],
        'thead and tbody around a loop' => ['<table><thead><tr><th scope="col">H</th></tr></thead><tbody>@foreach ($r as $x)<tr><td>{{ $x }}</td></tr>@endforeach</tbody></table>', 0],
        'conditional second row' => ['<table><tr><td>a</td></tr>@if ($more)<tr><td>b</td></tr>@endif</table>', 1],
        'presentation role opts out' => ['<table role="presentation">@foreach ($r as $x)<tr><td>{{ $x }}</td></tr>@endforeach</table>', 0],
    ]);
});
