<?php

declare(strict_types=1);

use Forte\Sheath\Rules\Security\CsrfFieldRule;

describe('CsrfFieldRule', function (): void {
    it('does not require a native token when every POST path is durably intercepted', function (string $code): void {
        $this->getRuleTester()->run(new CsrfFieldRule, ['valid' => [$code]]);
    })->with([
        'Livewire submit' => '<form method="post" wire:submit="save"></form>',
        'Livewire modified submit' => '<form method="post" wire:submit.stop.debounce.250ms="save"></form>',
        'Alpine longhand' => '<form method="post" x-data x-on:submit.prevent="save()"></form>',
        'Alpine shorthand' => '<form method="post" x-data @submit.prevent="save()"></form>',
        'conditional POST interception and GET fallback' => '<form @if($save) method="post" wire:submit="save" @else method="get" @endif></form>',
        'intercepted submit-control override' => '<form method="get" wire:submit="save"><button type="submit" formmethod="post">Save</button></form>',
    ]);

    it('still requires a token when interception is absent or non-durable', function (string $code): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'Alpine no prevent' => '<form method="post" x-data @submit="save()"></form>',
        'Livewire once' => '<form method="post" wire:submit.once="save"></form>',
        'Livewire passive' => '<form method="post" wire:submit.passive="save"></form>',
        'Livewire outside' => '<form method="post" wire:submit.outside="save"></form>',
        'conditional interception' => '<form method="post" @if($reactive) wire:submit="save" @endif></form>',
        'ignored Alpine interception' => '<form x-ignore method="post" @submit.prevent="save()"></form>',
        'Alpine interception below ignored subtree' => '<div x-ignore><form method="post" @submit.prevent="save()"></form></div>',
        'Livewire interception below ignored subtree' => '<div x-ignore><form method="post" wire:submit="save"></form></div>',
    ]);

    it('keeps Livewire interception active when x-ignore is on the form itself', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'valid' => ['<form x-ignore method="post" wire:submit="save"></form>'],
        ]);
    });

    it('uses explicit submission attributes before a later opaque provider', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'valid' => [
                '<form {{ $attributes }} method="post" action="/save"></form>',
                '<form method="get"><button {{ $attributes }} type="submit" formmethod="post" formaction="/save">Save</button></form>',
                '<head><base href="https://payments.example/" {{ $attributes }}></head><form method="post" action="checkout"></form>',
            ],
            'invalid' => [[
                'code' => '<form method="post" action="/save" {{ $attributes }}></form>',
                'errors' => 1,
            ], [
                'code' => '<form method="get"><button type="submit" formmethod="post" formaction="/save" {{ $attributes }}>Save</button></form>',
                'errors' => 1,
            ]],
        ]);
    });

    it('passes for forms with @csrf directive', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'valid' => [
                '<form method="POST">@csrf</form>',
                '<form method="post">@csrf<input type="text"></form>',
                '<form action="/submit" method="POST">@csrf</form>',
            ],
        ]);
    });

    it('passes for GET forms without @csrf', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'valid' => [
                '<form method="GET"></form>',
                '<form method="get"></form>',
                '<form method="GET" action="/search"></form>',
            ],
        ]);
    });

    it('passes for POST submissions that do not target the application', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'valid' => [
                '<form method="POST" action="https://payments.example/checkout"></form>',
                '<form method="post" action="//identity.example/login"></form>',
                '<form method="post" action="mailto:ops@example.com"></form>',
                '<form method="get"><button formmethod="post" formaction="https://payments.example/checkout">Pay</button></form>',
                "<form method=\"post\" action=\"\x0C//identity.example/login\"></form>",
                "<form method=\"post\" action=\"\x1F//identity.example/login\"></form>",
            ],
        ]);
    });

    it('resolves relative submission targets against the first rendered base URL', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'valid' => [
                '<html><head><base href="https://payments.example/"></head><body><form method="post" action="checkout"></form></body></html>',
                '<html><head><base><base href="https://payments.example/"></head><body><form method="post" action="/checkout"></form></body></html>',
                '<html><head><base href="https://payments.example/"><base href="/local/"></head><body><form method="post" action="checkout"></form></body></html>',
                '<html><head>@if($a)<base href="https://one.example/">@else<base href="https://two.example/">@endif</head><body><form method="post" action="checkout"></form></body></html>',
                '<html><head><base href="https://payments.example/"></head><body><form method="get"><button formmethod="post" formaction="checkout">Pay</button></form></body></html>',
            ],
            'invalid' => [[
                'code' => '<html><head><base href="/root/"></head><body><form method="post" action="checkout"></form></body></html>',
                'errors' => [['hasFixAvailable' => true, 'hasDangerousFix' => true]],
            ], [
                'code' => '<html><head><base href="/root/"><base href="https://payments.example/"></head><body><form method="post" action="checkout"></form></body></html>',
                'errors' => [['hasFixAvailable' => true, 'hasDangerousFix' => true]],
            ]],
        ]);
    });

    it('withholds a CSRF fix when the effective base URL is not statically known', function (string $head): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [[
                'code' => "<html><head>{$head}</head><body><form method=\"post\" action=\"checkout\"></form></body></html>",
                'errors' => [['hasFixAvailable' => false]],
            ]],
        ]);
    })->with([
        'dynamic href' => '<base href="{{ $base }}">',
        'conditional first base' => '@if($external)<base href="https://payments.example/">@endif',
        'conditional href attribute' => '<base @if($external) href="https://payments.example/" @endif>',
    ]);

    it('reports uncertain POST destinations without offering a token-leaking fix', function (string $code): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [['hasFixAvailable' => false]],
            ]],
        ]);
    })->with([
        'whole-value echo' => '<form method="post" action="{{ $destination }}"></form>',
        'mixed literal and echo' => '<form method="post" action="/tenant/{{ $tenant }}"></form>',
        'bound action' => '<form method="post" :action="$destination"></form>',
        'longhand Alpine bound action' => '<form method="post" x-bind:action="destination"></form>',
        'Livewire bound action' => '<form method="post" wire:bind:action="destination"></form>',
        'uncertain submit target' => '<form method="get"><button formmethod="post" formaction="{{ $destination }}">Save</button></form>',
    ]);

    it('recognizes application URL helpers as local submission targets', function (string $action): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [[
                'code' => '<form method="post" action="{{ '.$action.' }}"></form>',
                'errors' => [['hasFixAvailable' => true, 'hasDangerousFix' => true]],
            ]],
        ]);
    })->with([
        'route' => "route('profile.update')",
        'url' => "url('/profile')",
        'secure_url' => "secure_url('/profile')",
        'action' => "action([ProfileController::class, 'update'])",
        'explicit global route helper' => "\\route('profile.update')",
    ]);

    it('uses app.url and applicationHosts to recognize absolute application URLs', function (): void {
        config()->set('app.url', 'https://app.example:8443');

        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [[
                'code' => '<form method="post" action="https://APP.EXAMPLE/profile"></form>',
                'errors' => [['hasFixAvailable' => true]],
            ]],
        ]);

        $rule = new CsrfFieldRule;
        $rule->setOptions(['applicationHosts' => ['admin.example']]);

        $this->getRuleTester()->run($rule, [
            'invalid' => [[
                'code' => '<form method="post" action="//ADMIN.EXAMPLE:9443/profile"></form>',
                'errors' => [['hasFixAvailable' => true]],
            ]],
        ]);
    });

    it('withholds the token fix when a POST form also has a non-application target', function (string $code): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [[

                    'hasFixAvailable' => false,
                ]],
            ]],
        ]);
    })->with([
        'local form with external control' => '<form method="post" action="/profile"><button formaction="https://identity.example/logout">Save</button></form>',
        'external form with local control' => '<form method="post" action="https://identity.example/logout"><button formaction="/profile">Save</button></form>',
        'external GET form with local POST control' => '<form method="get" action="https://identity.example/search"><button formmethod="post" formaction="/profile">Save</button></form>',
        'local form with unresolved control' => '<form method="post" action="/profile"><button formaction="{{ $destination }}">Save</button></form>',
    ]);

    it('passes for forms without a method attribute, which HTML defaults to GET', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'valid' => [
                '<form action="/submit"></form>',
                '<form></form>',
            ],
        ]);
    });

    it('uses the HTML GET default for empty and invalid method values', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'valid' => [
                '<form method=""></form>',
                '<form method="PUT"></form>',
                '<form method="invalid"></form>',
                '<form method=" POST "></form>',
            ],
        ]);
    });

    it('checks submit-control formmethod overrides', function (string $code): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'button override' => '<form method="get"><button type="submit" formmethod="post">Save</button></form>',
        'default button type' => '<form><button formmethod="POST">Save</button></form>',
        'submit input override' => '<form><input type="submit" formmethod="post"></form>',
        'image input override' => '<form><input type="image" formmethod="post"></form>',
        'invalid button type defaults to submit' => '<form method="get"><button type=" button " formmethod="post">Save</button></form>',
        'external associated control' => '<form id="profile"></form><button form="profile" formmethod="post">Save</button>',
        'dynamic override' => '<form><button formmethod="{{ $method }}">Save</button></form>',
    ]);

    it('uses GET for whitespace-wrapped formmethod keywords', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'valid' => [
                '<form><button type="submit" formmethod=" POST ">Search</button></form>',
                '<form method="get"><input type="submit" formmethod=" post "></form>',
            ],
        ]);
    });

    it('ignores formmethod on controls that cannot submit the form', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'valid' => [
                '<form><button type="button" formmethod="post">Save</button></form>',
                '<form><button type="reset" formmethod="post">Reset</button></form>',
                '<form><input type="text" formmethod="post"></form>',
                '<form><button type="submit" formmethod="get">Search</button></form>',
                '<form id="a"></form><button form="b" formmethod="post">Save</button>',
                '<form method="get"><input type=" submit " formmethod="post"></form>',
            ],
        ]);
    });

    it('passes when the token is supplied without the @csrf directive', function (string $code): void {
        $this->getRuleTester()->run(new CsrfFieldRule, ['valid' => [$code]]);
    })->with([
        'csrf_field helper' => '<form method="POST">{{ csrf_field() }}</form>',
        'raw token input' => '<form method="POST"><input type="hidden" name="_token" value="{{ csrf_token() }}"></form>',
        'invalid token input type defaults to text' => '<form method="POST"><input type=" button " name="_token" value="{{ csrf_token() }}"></form>',
        'nested @csrf' => '<form method="POST"><div><fieldset>@csrf</fieldset></div></form>',
        '@csrf in every conditional branch' => '<form method="POST">@if($guard)@csrf @else @csrf @endif</form>',
        '@csrf in both forelse outcomes' => '<form method="POST">@forelse($items as $item)@csrf @empty @csrf @endforelse</form>',
        '@csrf in every exhaustive switch branch' => '<form method="POST">@switch($x)@case(1)@csrf @break @default @csrf @endswitch</form>',
        '@csrf after stacked fallthrough cases' => '<form method="POST">@switch($x)@case(1)@case(2)@csrf @break @default @csrf @endswitch</form>',
    ]);

    it('uses successful-control ownership when recognizing manual tokens', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'valid' => [
                '<form id="profile" method="POST"></form><input form="profile" type="hidden" name="_token" value="{{ csrf_token() }}">',
                '<form id="profile" method="POST"></form><input form="pro&#102;ile" type="hidden" name="_token" value="{{ csrf_token() }}">',
                '<form method="POST"><fieldset disabled><legend>@csrf</legend></fieldset></form>',
                '<template><div id="profile"></div></template><form id="profile" method="POST"></form><input form="profile" type="hidden" name="_token" value="{{ csrf_token() }}">',
            ],
            'invalid' => [
                [
                    'code' => '<form method="POST"><template>@csrf</template></form>',
                    'errors' => [['hasFixAvailable' => true]],
                ],
                [
                    'code' => '<form method="POST"><datalist>@csrf</datalist></form>',
                    'errors' => [['hasFixAvailable' => true]],
                ],
                [
                    'code' => '<form method="POST"><input disabled name="_token" value="{{ csrf_token() }}"></form>',
                    'errors' => [['hasFixAvailable' => true]],
                ],
                [
                    'code' => '<form id="a" method="POST"><input form="b" name="_token" value="{{ csrf_token() }}"></form>',
                    'errors' => [['hasFixAvailable' => true]],
                ],
                [
                    'code' => '<form method="POST"><fieldset disabled><legend>Legend</legend>@csrf</fieldset></form>',
                    'errors' => [['hasFixAvailable' => true]],
                ],
                [
                    'code' => '<form id="a" method="POST"></form>@if($x)<input form="a" name="_token" value="{{ csrf_token() }}">@endif',
                    'errors' => [['hasFixAvailable' => true]],
                ],
                [
                    'code' => '<form id="a" method="POST"></form><form id="a" method="POST"></form><input form="a" name="_token" value="{{ csrf_token() }}">',
                    'errors' => [['hasFixAvailable' => true]],
                ],
                [
                    'code' => '<div id="profile"></div><form id="profile" method="POST"></form><input form="profile" type="hidden" name="_token" value="{{ csrf_token() }}">',
                    'errors' => [['hasFixAvailable' => true]],
                ],
                [
                    'code' => '<div id="pro&#102;ile"></div><form id="profile" method="POST"></form><input form="profile" type="hidden" name="_token" value="{{ csrf_token() }}">',
                    'errors' => [['hasFixAvailable' => true]],
                ],
                [
                    'code' => '<form id="profile" method="POST"></form><input @if($owned) form="profile" @endif type="hidden" name="_token" value="{{ csrf_token() }}">',
                    'errors' => [['hasFixAvailable' => true]],
                ],
                [
                    'code' => '<form id="profile" method="POST"></form><template><input form="profile" type="hidden" name="_token" value="{{ csrf_token() }}"></template>',
                    'errors' => [['hasFixAvailable' => true]],
                ],
            ],
        ]);
    });

    it('models controls instantiated or moved by Alpine templates', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'valid' => [
                '<form id="profile" method="POST"></form><template x-teleport="body"><input form="profile" type="hidden" name="_token" value="{{ csrf_token() }}"></template>',
                '<form method="get"><template x-teleport="body"><button type="submit" formmethod="post">Detached</button></template></form>',
                '<template x-if="open"><form method="POST">@csrf</form></template>',
            ],
            'invalid' => [[
                'code' => '<form method="POST"><template x-if="ready">@csrf</template></form>',
                'errors' => [['hasFixAvailable' => true]],
            ], [
                'code' => '<form method="get"><template x-if="show"><button type="submit" formmethod="post">Save</button></template></form>',
                'errors' => [['hasFixAvailable' => true]],
            ], [
                'code' => '<form id="profile" method="get"></form><template x-teleport="body"><button form="profile" type="submit" formmethod="post">Save</button></template>',
                'errors' => [['hasFixAvailable' => true]],
            ], [
                'code' => '<form id="profile" method="POST"></form><template x-if="ready"><input form="profile" type="hidden" name="_token" value="{{ csrf_token() }}"></template>',
                'errors' => [['hasFixAvailable' => true]],
            ]],
        ]);
    });

    it('requires token controls to be successful on every attribute render path', function (string $code): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => [['hasFixAvailable' => true]],
            ]],
        ]);
    })->with([
        'conditionally disabled token input' => '<form method="POST"><input type="hidden" name="_token" value="{{ csrf_token() }}" @if($off) disabled @endif></form>',
        'disabled directive on token input' => '<form method="POST"><input type="hidden" name="_token" value="{{ csrf_token() }}" @disabled($off)></form>',
        'conditional disabled fieldset containing @csrf' => '<form method="POST"><fieldset @if($off) disabled @endif>@csrf</fieldset></form>',
        'disabled directive fieldset containing helper' => '<form method="POST"><fieldset @disabled($off)>{{ csrf_field() }}</fieldset></form>',
    ]);

    it('does not accept input types that cannot guarantee submission of the token string', function (string $type): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [[
                'code' => '<form method="POST"><input type="'.$type.'" name="_token" value="{{ csrf_token() }}"></form>',
                'errors' => [['hasFixAvailable' => true]],
            ]],
        ]);
    })->with([
        'button',
        'checkbox',
        'file',
        'image',
        'number',
        'radio',
        'reset',
        'submit',
    ]);

    it('requires a token on every form render path', function (string $code): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'statically unreachable token' => '<form method="POST">@if(false)@csrf @endif</form>',
        'token in only the if arm' => '<form method="POST">@if($guard)@csrf @else No token @endif</form>',
        'token in only the else arm' => '<form method="POST">@if($guard)No token @else @csrf @endif</form>',
        'token in a possibly empty loop' => '<form method="POST">@foreach($items as $item)@csrf @endforeach</form>',
        'switch without a default' => '<form method="POST">@switch($x)@case(1)@csrf @break @endswitch</form>',
        'token after an unconditional switch break' => '<form method="POST">@switch($x)@case(1)@break @csrf @default @csrf @endswitch</form>',
        'token after a conditional switch break' => '<form method="POST">@switch($x)@case(1)@break($skip) @csrf @break @default @csrf @endswitch</form>',
        'token after a nested conditional switch break' => '<form method="POST">@switch($x)@case(1)@if($skip)@break @endif @csrf @break @default @csrf @endswitch</form>',
    ]);

    it('does not mistake csrf_field text or similarly named calls for the Laravel helper', function (string $code): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'string literal' => '<form method="POST">{{ "csrf_field()" }}</form>',
        'different function' => '<form method="POST">{{ custom_csrf_field() }}</form>',
        'object method' => '<form method="POST">{{ $helper->csrf_field() }}</form>',
        'conditional call' => '<form method="POST">{{ $enabled ? csrf_field() : "" }}</form>',
        'concatenated call' => '<form method="POST">{{ csrf_field()."suffix" }}</form>',
        'call with an argument' => '<form method="POST">{{ csrf_field("unexpected") }}</form>',
    ]);

    it('does not count a token input that cannot submit a token value', function (string $code): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
            ]],
        ]);
    })->with([
        'missing value' => '<form method="POST"><input type="hidden" name="_token"></form>',
        'empty value' => '<form method="POST"><input type="hidden" name="_token" value=""></form>',
        'static value' => '<form method="POST"><input type="hidden" name="_token" value="not-a-session-token"></form>',
        'arbitrary echo' => '<form method="POST"><input type="hidden" name="_token" value="{{ $token }}"></form>',
        'similarly named helper' => '<form method="POST"><input type="hidden" name="_token" value="{{ custom_csrf_token() }}"></form>',
        'object method' => '<form method="POST"><input type="hidden" name="_token" value="{{ $helper->csrf_token() }}"></form>',
        'prefixed token' => '<form method="POST"><input type="hidden" name="_token" value="prefix{{ csrf_token() }}"></form>',
        'suffixed token' => '<form method="POST"><input type="hidden" name="_token" value="{{ csrf_token() }}suffix"></form>',
        'concatenated token' => '<form method="POST"><input type="hidden" name="_token" value="{{ csrf_token().\'suffix\' }}"></form>',
        'transformed token' => '<form method="POST"><input type="hidden" name="_token" value="{{ strtoupper(csrf_token()) }}"></form>',
        'helper with argument' => '<form method="POST"><input type="hidden" name="_token" value="{{ csrf_token(\'unexpected\') }}"></form>',
        'dynamic name' => '<form method="POST"><input type="hidden" name="{{ $name }}" value="{{ csrf_token() }}"></form>',
    ]);

    it('accepts manual token inputs only when the complete value calls the global csrf_token helper', function (string $code): void {
        $this->getRuleTester()->run(new CsrfFieldRule, ['valid' => [$code]]);
    })->with([
        'escaped echo' => '<form method="POST"><input type="hidden" name="_token" value="{{ csrf_token() }}"></form>',
        'raw echo' => '<form method="POST"><input type="hidden" name="_token" value="{!! csrf_token() !!}"></form>',
        'explicit global helper' => '<form method="POST"><input type="hidden" name="_token" value="{{ \\csrf_token() }}"></form>',
        'surrounding whitespace' => '<form method="POST"><input type="hidden" name="_token" value="  {{ csrf_token() }}  "></form>',
    ]);

    it('reports forms whose method is dynamic', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [[
                'code' => '<form method="{{ $method }}"></form>',
                'errors' => 1,
            ]],
        ]);
    });

    it('fails for POST forms without @csrf', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [
                [
                    'code' => '<form method="POST"></form>',
                    'errors' => 1,
                ],
                [
                    'code' => '<form method="post" action="/submit"></form>',
                    'errors' => 1,
                ],
            ],
        ]);
    });

    it('provides auto-fix to add @csrf', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [
                [
                    'code' => '<form method="POST"></form>',
                    'errors' => [
                        [
                            'hasFixAvailable' => true,
                            'hasDangerousFix' => true,
                        ],
                    ],
                    'output' => "<form method=\"POST\">\n    @csrf</form>",
                ],
            ],
        ]);
    });

    it('withholds the state-changing token insertion unless dangerous fixes are enabled', function (): void {
        $code = '<form method="POST"></form>';
        $tester = $this->getRuleTester();

        expect($tester->fix(new CsrfFieldRule, $code))->toBe($code)
            ->and($tester->fix(new CsrfFieldRule, $code, dangerous: true))
            ->toBe("<form method=\"POST\">\n    @csrf</form>");
    });

    it('inserts @csrf after the opening tag even when attributes contain >', function (string $code, string $output): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [[
                'code' => $code,
                'errors' => 1,
                'output' => $output,
            ]],
        ]);
    })->with([
        'array arrow in echo' => [
            '<form method="post" action="{{ route(\'u.update\', [\'id\' => $id]) }}"></form>',
            "<form method=\"post\" action=\"{{ route('u.update', ['id' => \$id]) }}\">\n    @csrf</form>",
        ],
        'blade directive attribute' => [
            '<form method="post" @class([\'form\' => true])></form>',
            "<form method=\"post\" @class(['form' => true])>\n    @csrf</form>",
        ],
        'greater-than inside an attribute value' => [
            '<form method="post" data-hint="a > b"></form>',
            "<form method=\"post\" data-hint=\"a > b\">\n    @csrf</form>",
        ],
    ]);

    it('preserves CRLF line endings when inserting @csrf', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [[
                'code' => "<form method=\"POST\">\r\n    <input name=\"email\">\r\n</form>",
                'errors' => [['hasFixAvailable' => true]],
                'output' => "<form method=\"POST\">\r\n    @csrf\r\n    <input name=\"email\">\r\n</form>",
            ]],
        ]);
    });

    it('handles many independently targeted forms', function (): void {
        $source = '';
        for ($index = 0; $index < 400; $index++) {
            $source .= "<form id=\"f{$index}\" method=\"post\"><button type=\"submit\">Save</button>@csrf</form>";
        }

        $this->getRuleTester()->run(new CsrfFieldRule, ['valid' => [$source]]);
    });

    it('correlates conditional submission methods and targets', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'valid' => [
                '<form @if($external) method="post" action="https://payments.example/pay" @else method="get" action="/search" @endif></form>',
                '<form><button @if($external) formmethod="post" formaction="https://payments.example/pay" @else formmethod="get" formaction="/search" @endif>Go</button></form>',
            ],
            'invalid' => [
                [
                    'code' => '<form @if($save) method="post" action="/profile" @else method="get" @endif></form>',
                    'errors' => [['hasFixAvailable' => true, 'hasDangerousFix' => true]],
                ],
                [
                    'code' => '<form><button @if($save) formmethod="post" formaction="/profile" @else type="button" @endif>Go</button></form>',
                    'errors' => [['hasFixAvailable' => true, 'hasDangerousFix' => true]],
                ],
            ],
        ]);
    });

    it('does not let unrelated conditional attributes exhaust submission-path analysis', function (): void {
        $attributes = '';
        foreach (range(1, 8) as $index) {
            $attributes .= " @if(\$c{$index}) data-{$index}=\"x\" @endif";
        }

        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [[
                'code' => '<form method="post"'.$attributes.'></form>',
                'errors' => [['hasFixAvailable' => true, 'hasDangerousFix' => true]],
            ]],
        ]);
    });

    it('associates submit controls through conditional form attributes', function (): void {
        $this->getRuleTester()->run(new CsrfFieldRule, [
            'invalid' => [[
                'code' => '<form id="profile"></form><button @if($save) form="profile" formmethod="post" formaction="/profile" @endif>Save</button>',
                'errors' => [['hasFixAvailable' => true, 'hasDangerousFix' => true]],
            ]],
        ]);
    });
});
