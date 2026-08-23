<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Accessibility\Aria\NoAbstractRolesRule;

describe('NoAbstractRolesRule', function (): void {
    it('passes for concrete roles', function (string $code): void {
        $this->getRuleTester()->run(new NoAbstractRolesRule, ['valid' => [$code]]);
    })->with([
        'button role' => '<button role="button">Click</button>',
        'navigation role' => '<div role="navigation">Nav</div>',
        'main role' => '<div role="main">Main content</div>',
        'alert role' => '<div role="alert">Alert</div>',
        'dialog role' => '<div role="dialog">Dialog</div>',
    ]);

    it('does not treat vertical tab as HTML token-list whitespace', function (): void {
        $this->getRuleTester()->run(new NoAbstractRolesRule, [
            'valid' => ["<div role=\"button\x0Bwidget\"></div>"],
        ]);
    });

    it('uses the first duplicate role attribute on each render path', function (): void {
        $this->getRuleTester()->run(new NoAbstractRolesRule, [
            'valid' => ['<div role="button" @if($x) role="widget" @endif>Action</div>'],
        ]);
    });

    it('passes for elements without role', function (string $code): void {
        $this->getRuleTester()->run(new NoAbstractRolesRule, ['valid' => [$code]]);
    })->with([
        'div' => '<div>Content</div>',
        'button' => '<button>Click</button>',
    ]);

    it('skips dynamic role attributes', function (string $code): void {
        $this->getRuleTester()->run(new NoAbstractRolesRule, ['valid' => [$code]]);
    })->with([
        'alpine bound' => '<div :role="dynamicRole">Content</div>',
        'blade interpolation' => '<div role="{{ $role }}">Content</div>',
        'blade ternary' => '<div role="{{ $isWidget ? \'widget\' : \'button\' }}">Content</div>',
        'expression syntax' => '<div role="@php($role)">Content</div>',
    ]);

    it('fails for abstract roles', function (string $role): void {
        $this->getRuleTester()->run(new NoAbstractRolesRule, [
            'invalid' => [[
                'code' => "<div role=\"{$role}\">Content</div>",
                'errors' => 1,
            ]],
        ]);
    })->with([
        'command',
        'composite',
        'input',
        'landmark',
        'widget',
        'roletype',
        'structure',
        'section',
    ]);

    it('is case insensitive', function (): void {
        $this->getRuleTester()->run(new NoAbstractRolesRule, [
            'invalid' => [[
                'code' => '<div role="WIDGET">Widget</div>',
                'errors' => 1,
            ]],
        ]);
    });

    it('provides fix to remove abstract role attribute', function (string $code): void {
        $this->getRuleTester()->run(new NoAbstractRolesRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
                'hasFix' => true,
            ]],
        ]);
    })->with([
        'div with widget' => ['<div role="widget">Widget</div>'],
        'span with command' => ['<span role="command">Command</span>'],
        'mixed abstract and concrete' => ['<div role="widget button">Mixed</div>'],
        'navigation with widget' => ['<div role="navigation widget">Nav with abstract</div>'],
    ]);

    it('detects multiple abstract roles in same element', function (): void {
        $this->getRuleTester()->run(new NoAbstractRolesRule, [
            'invalid' => [[
                'code' => '<div role="widget command">Multiple abstract</div>',
                'errors' => 2,
            ]],
        ]);
    });

    it('re-encodes character references when preserving remaining roles', function (string $code, string $output): void {
        $this->getRuleTester()->run(new NoAbstractRolesRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
                'output' => $output,
            ]],
        ]);
    })->with([
        'double-quoted role' => [
            '<div role="command &quot;foo">x</div>',
            '<div role="&quot;foo">x</div>',
        ],
        'single-quoted role' => [
            "<div role='command &#39;foo'>x</div>",
            "<div role='&#39;foo'>x</div>",
        ],
    ]);
});
