<?php

declare(strict_types=1);

use Forte\Sheath\Configuration\Config;
use Forte\Sheath\Linter;
use Forte\Sheath\Rules\Accessibility\Aria\NoAriaHiddenOnFocusableRule;
use Forte\Sheath\Rules\RuleRegistry;

describe('NoAriaHiddenOnFocusableRule', function (): void {
    it('correlates exact predicates across ancestors and descendants', function (): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, [
            'valid' => [
                '<div @if($x) hidden @endif><button @if($x) aria-hidden="true" @endif></button></div>',
                '<fieldset @if($x) disabled @endif><button @if($x) aria-hidden="true" @endif>Save</button></fieldset>',
                '<div @if($x) aria-hidden="true" @endif><button @if($x) tabindex="-1" @endif>Save</button></div>',
                '<div @if($x) aria-hidden="true" @endif>@if(!$x)<button>Save</button>@endif</div>',
            ],
            'invalid' => [[
                'code' => '<div @if($x) aria-hidden="true" @endif><button @if($y) tabindex="-1" @endif>Save</button></div>',
                'errors' => 1,
            ]],
        ]);
    });

    it('recognizes exhaustive hidden and inert alternatives', function (): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, [
            'valid' => [
                '<button @if($x) hidden @else inert @endif aria-hidden="true">X</button>',
                '<div @if($x) hidden @else inert @endif><button aria-hidden="true">X</button></div>',
            ],
        ]);
    });
    it('passes for aria-hidden on non-focusable elements', function (string $code): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, ['valid' => [$code]]);
    })->with([
        'div' => '<div aria-hidden="true">Hidden content</div>',
        'span' => '<span aria-hidden="true">Hidden text</span>',
        'paragraph' => '<p aria-hidden="true">Hidden paragraph</p>',
        'image' => '<img aria-hidden="true" src="decorative.png">',
    ]);

    it('passes for focusable elements without aria-hidden', function (string $code): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, ['valid' => [$code]]);
    })->with([
        'button' => '<button>Click me</button>',
        'anchor' => '<a href="/link">Link</a>',
        'input' => '<input type="text">',
        'select' => '<select><option>Option</option></select>',
        'textarea' => '<textarea></textarea>',
    ]);

    it('passes for aria-hidden="false" on focusable elements', function (string $code): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, ['valid' => [$code]]);
    })->with([
        'button' => '<button aria-hidden="false">Click me</button>',
        'anchor' => '<a href="/link" aria-hidden="false">Link</a>',
    ]);

    it('uses the first duplicate accessibility attributes on each render path', function (string $code): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, ['valid' => [$code]]);
    })->with([
        'aria-hidden' => '<button aria-hidden="false" @if($x) aria-hidden="true" @endif>Action</button>',
        'tabindex' => '<button tabindex="-1" @if($x) tabindex="0" @endif aria-hidden="true">Action</button>',
    ]);

    it('passes when element is not focusable', function (string $code): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, ['valid' => [$code]]);
    })->with([
        'disabled button' => '<button aria-hidden="true" disabled>Disabled</button>',
        'disabled input' => '<input type="text" aria-hidden="true" disabled>',
        'disabled select' => '<select aria-hidden="true" disabled><option>Option</option></select>',
        'disabled button with tabindex' => '<button aria-hidden="true" disabled tabindex="0">Disabled</button>',
        'disabled input with positive tabindex' => '<input aria-hidden="true" disabled tabindex="2">',
        'disabled select with tabindex' => '<select aria-hidden="true" disabled tabindex="0"><option>Option</option></select>',
        'disabled textarea with tabindex' => '<textarea aria-hidden="true" disabled tabindex="0"></textarea>',
        'anchor without href' => '<a aria-hidden="true">Not a real link</a>',
        'area without href' => '<map name="nav"><area aria-hidden="true" alt="Home"></map>',
        'negative tabindex' => '<div tabindex="-1" aria-hidden="true">Hidden</div>',
        'hidden input' => '<input type="hidden" aria-hidden="true">',
        'hidden input with tabindex' => '<input type="hidden" tabindex="0" aria-hidden="true">',
        'invalid tabindex' => '<div tabindex="not-a-number" aria-hidden="true">Hidden</div>',
        'standalone summary' => '<summary aria-hidden="true">Ordinary content</summary>',
        'details with summary removed from tab order' => '<details aria-hidden="true"><summary tabindex="-1">Details</summary></details>',
        'later summary child' => '<details><summary>First</summary><summary aria-hidden="true">Second</summary></details>',
        'hidden button' => '<button hidden aria-hidden="true">Hidden</button>',
        'button inside hidden subtree' => '<div hidden><button aria-hidden="true">Hidden</button></div>',
        'inert button' => '<button inert aria-hidden="true">Inert</button>',
        'button inside inert subtree' => '<div inert><button aria-hidden="true">Inert</button></div>',
        'button disabled by fieldset' => '<fieldset disabled><button aria-hidden="true">Disabled</button></fieldset>',
        'input disabled by nested fieldset' => '<fieldset disabled><div><input aria-hidden="true"></div></fieldset>',
        'button in second legend is disabled' => '<fieldset disabled><legend>First</legend><legend><button aria-hidden="true">Disabled</button></legend></fieldset>',
    ]);

    it('keeps controls in the first legend of a disabled fieldset focusable', function (): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, [
            'invalid' => [[
                'code' => '<fieldset disabled><legend><button aria-hidden="true">Focusable</button></legend></fieldset>',
                'errors' => 1,
            ]],
        ]);
    });

    it('keeps a later legend potentially focusable when an earlier legend is conditional', function (): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, [
            'invalid' => [[
                'code' => '<fieldset disabled>@if($x)<legend>Optional</legend>@endif<legend><button aria-hidden="true">Focusable</button></legend></fieldset>',
                'errors' => 1,
            ]],
        ]);
    });

    it('does not exempt a later legend when an earlier legend is guaranteed', function (string $code): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, ['valid' => [$code]]);
    })->with([
        'section shown in place' => '<fieldset disabled>@section("legend")<legend>First</legend>@show<legend><button aria-hidden="true">X</button></legend></fieldset>',
        'same conditional arm' => '<fieldset disabled>@if($x)<legend>First</legend><legend><button aria-hidden="true">X</button></legend>@endif</fieldset>',
    ]);

    it('correlates aria-hidden with focusability attributes on the same render path', function (): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, [
            'valid' => [
                '<a @if($hidden) aria-hidden="true" @else href="/account" @endif>Account</a>',
            ],
            'invalid' => [[
                'code' => '<a @if($hidden) href="/account" aria-hidden="true" @endif>Account</a>',
                'errors' => 1,
            ]],
        ]);
    });

    it('does not let unrelated conditional attributes exhaust focusability paths', function (): void {
        $noise = implode(' ', array_map(
            static fn (int $index): string => "@if(\$condition{$index}) data-noise-{$index}=\"x\" @endif",
            range(1, 8),
        ));

        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, [
            'invalid' => [[
                'code' => "<a {$noise} @if(\$hidden) href=\"/account\" aria-hidden=\"true\" @endif>Account</a>",
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for native-focusable elements remediated with a negative tabindex', function (string $code): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, ['valid' => [$code]]);
    })->with([
        'button' => '<button aria-hidden="true" tabindex="-1">Decorative</button>',
        'anchor' => '<a href="/link" aria-hidden="true" tabindex="-1">Hidden link</a>',
        'input' => '<input type="text" aria-hidden="true" tabindex="-1">',
        'tabindex -2' => '<button aria-hidden="true" tabindex="-2">Decorative</button>',
    ]);

    it('fails for focusable elements with aria-hidden="true"', function (string $code): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'button' => '<button aria-hidden="true">Click</button>',
        'anchor' => '<a href="/link" aria-hidden="true">Link</a>',
        'input' => '<input type="text" aria-hidden="true">',
        'tabindex 0' => '<div tabindex="0" aria-hidden="true">Focusable div</div>',
        'tabindex numeric prefix' => '<div tabindex="1.5" aria-hidden="true">Focusable div</div>',
        'contenteditable' => '<div contenteditable="true" aria-hidden="true">Editable</div>',
        'plaintext-only contenteditable' => '<div contenteditable="plaintext-only" aria-hidden="true">Editable</div>',
        'disabled does not disable links' => '<a href="/link" disabled aria-hidden="true">Link</a>',
        'image-map area' => '<map name="nav"><area href="/home" aria-hidden="true" alt="Home"></map><img usemap="#nav" src="nav.png" alt="">',
        'bound disabled may be false' => '<button :disabled="$disabled" aria-hidden="true">Button</button>',
        'uppercase element and value' => '<BUTTON ARIA-HIDDEN="TRUE">Click</BUTTON>',
        'first summary child' => '<details><summary aria-hidden="true">Details</summary><p>Body</p></details>',
        'summary with explicit tabindex' => '<summary tabindex="0" aria-hidden="true">Focusable</summary>',
        'possible first summary after conditional sibling' => '<details>@if($custom)<summary>Custom</summary>@endif<summary aria-hidden="true">Fallback</summary></details>',
        'details with generated summary' => '<details aria-hidden="true"><p>Body</p></details>',
    ]);

    it('fails when aria-hidden contains a focusable descendant', function (string $code): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'button' => '<div aria-hidden="true"><button>Focusable ghost</button></div>',
        'link' => '<section aria-hidden="true"><a href="/account">Account</a></section>',
        'tabindex' => '<div aria-hidden="true"><p tabindex="0">Focusable text</p></div>',
        'aria-hidden false cannot reset ancestor' => '<div aria-hidden="true"><div aria-hidden="false"><button>Still hidden</button></div></div>',
        'image-map area' => '<div aria-hidden="true"><map name="nav"><area href="/home" alt="Home"></map><img usemap="#nav" src="nav.png" alt=""></div>',
    ]);

    it('passes when every descendant is outside sequential focus navigation', function (string $code): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, ['valid' => [$code]]);
    })->with([
        'disabled button' => '<div aria-hidden="true"><button disabled>Disabled</button></div>',
        'negative tabindex' => '<div aria-hidden="true"><a href="/" tabindex="-1">Hidden link</a></div>',
    ]);

    it('does not propagate focusability across the template-content boundary', function (): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, [
            'valid' => ['<div aria-hidden="true"><template><button>X</button></template></div>'],
            'invalid' => [[
                'code' => '<template><div aria-hidden="true"><button>X</button></div></template>',
                'errors' => 1,
            ]],
        ]);
    });

    it('reports multiple violations', function (): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, [
            'invalid' => [[
                'code' => '<button aria-hidden="true">A</button><a href="/" aria-hidden="true">B</a>',
                'errors' => 2,
            ]],
        ]);
    });

    it('provides fix to remove aria-hidden', function (string $code): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
                'hasFix' => true,
            ]],
        ]);
    })->with([
        'button' => '<button aria-hidden="true">Click</button>',
        'anchor' => '<a href="/link" aria-hidden="true">Link</a>',
        'input' => '<input type="text" aria-hidden="true">',
        'tabindex' => '<div tabindex="0" aria-hidden="true">Focusable div</div>',
        'contenteditable' => '<div contenteditable="true" aria-hidden="true">Editable</div>',
        'single quotes' => "<button aria-hidden='true'>Click</button>",
        'select' => '<select aria-hidden="true"><option>Option</option></select>',
        'textarea' => '<textarea aria-hidden="true"></textarea>',
    ]);

    it('skips dynamic attributes', function (string $code): void {
        $this->getRuleTester()->run(new NoAriaHiddenOnFocusableRule, ['valid' => [$code]]);
    })->with([
        'bound aria-hidden' => '<button :aria-hidden="isHidden">Click</button>',
        'blade aria-hidden' => '<button aria-hidden="{{ $hidden }}">Click</button>',
        'blade ternary aria-hidden' => '<a href="/" aria-hidden="{{ $hide ? \'true\' : \'false\' }}">Link</a>',
        'dynamic tabindex' => '<div :tabindex="tabIndex" aria-hidden="true">Content</div>',
        'blade tabindex' => '<div tabindex="{{ $index }}" aria-hidden="true">Content</div>',
        'dynamic contenteditable' => '<div :contenteditable="isEditable" aria-hidden="true">Content</div>',
        'blade contenteditable' => '<div contenteditable="{{ $editable }}" aria-hidden="true">Content</div>',
    ]);

    it('handles deeply nested hidden subtrees', function (): void {
        $rule = new NoAriaHiddenOnFocusableRule;
        $registry = new RuleRegistry;
        $registry->register($rule);
        $config = Config::make();
        $config->setRule($rule->getId(), $rule->getDefaultSeverity()->value);
        $source = str_repeat('<div aria-hidden="true">', 1000).str_repeat('</div>', 1000);

        $result = (new Linter($registry))->lint($source, 'nested.blade.php', $config);

        expect($result->violations)->toBe([]);
    });
});
