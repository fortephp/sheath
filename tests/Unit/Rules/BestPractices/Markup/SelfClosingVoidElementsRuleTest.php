<?php

declare(strict_types=1);

use Forte\Sheath\Rules\BestPractices\Markup\SelfClosingVoidElementsRule;

function neverStyleRule(): SelfClosingVoidElementsRule
{
    return new SelfClosingVoidElementsRule;
}

function alwaysStyleRule(): SelfClosingVoidElementsRule
{
    $rule = new SelfClosingVoidElementsRule;
    $rule->setOptions(['style' => SelfClosingVoidElementsRule::STYLE_ALWAYS]);

    return $rule;
}

describe('SelfClosingVoidElementsRule', function (): void {
    describe('default style (never)', function (): void {
        it('passes for void elements written without a trailing slash', function (string $code): void {
            $this->getRuleTester()->run(neverStyleRule(), ['valid' => [$code]]);
        })->with([
            'br' => '<br>',
            'hr' => '<hr>',
            'img' => '<img src="test.jpg" alt="Test">',
            'input' => '<input type="text">',
            'meta' => '<meta charset="utf-8">',
            'blade value' => '<input type="text" value="{{ $value }}">',
            'greater-than in value' => '<input type="text" data-info="a > b">',
            'blade directive attribute' => '<input @checked($on)>',
        ]);

        it('reports and strips a trailing slash', function (string $code, string $output): void {
            $this->getRuleTester()->run(neverStyleRule(), [
                'invalid' => [[
                    'code' => $code,
                    'errors' => [['hasFixAvailable' => true]],
                    'output' => $output,
                ]],
            ]);
        })->with([
            'spaced slash' => ['<hr />', '<hr>'],
            'tight slash' => ['<br/>', '<br>'],
            'with attributes' => ['<img src="a.jpg" alt="A" />', '<img src="a.jpg" alt="A">'],
            'blade echo value' => ['<img src="{{ $post->image }}" alt="A" />', '<img src="{{ $post->image }}" alt="A">'],
            'greater-than in value' => ['<input data-info="a > b" />', '<input data-info="a > b">'],
            'blade directive attribute' => ['<input @checked($on) />', '<input @checked($on)>'],
        ]);

    });

    describe('always style', function (): void {
        it('passes for self-closing void elements', function (string $code): void {
            $this->getRuleTester()->run(alwaysStyleRule(), ['valid' => [$code]]);
        })->with([
            'br' => '<br/>',
            'hr' => '<hr />',
            'img' => '<img src="test.jpg" alt="Test" />',
            'greater-than in value' => '<input type="text" data-info="a > b" />',
        ]);

        it('reports and adds a trailing slash', function (string $code, string $output): void {
            $this->getRuleTester()->run(alwaysStyleRule(), [
                'invalid' => [[
                    'code' => $code,
                    'errors' => [['hasFixAvailable' => true]],
                    'output' => $output,
                ]],
            ]);
        })->with([
            'br' => ['<br>', '<br />'],
            'trailing whitespace' => ['<br >', '<br />'],
            'multiple trailing spaces' => ['<img src="test.jpg"   >', '<img src="test.jpg"   />'],
            'trailing newline' => ["<input type=\"text\"\n>", "<input type=\"text\"\n/>"],
            'img' => ['<img src="test.jpg">', '<img src="test.jpg" />'],
            'blade echo value' => ['<img src="{{ $post->image }}">', '<img src="{{ $post->image }}" />'],
            'greater-than in value' => ['<input data-info="a > b">', '<input data-info="a > b" />'],
            'blade directive attribute' => ['<input @checked($on)>', '<input @checked($on) />'],
        ]);

        it('detects multiple void elements', function (): void {
            $this->getRuleTester()->run(alwaysStyleRule(), [
                'invalid' => [[
                    'code' => '<br><hr><img src="test.jpg">',
                    'errors' => 3,
                    'output' => '<br /><hr /><img src="test.jpg" />',
                ]],
            ]);
        });
    });

    it('ignores non-void elements under either style', function (): void {
        foreach ([neverStyleRule(), alwaysStyleRule()] as $rule) {
            $this->getRuleTester()->run($rule, [
                'valid' => [
                    '<div></div>',
                    '<p>content</p>',
                    '<span>text</span>',
                    '<x-icon />',
                ],
            ]);
        }
    });
});
