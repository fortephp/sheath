# Sheath

Sheath is a Laravel-first linter for Blade and HTML templates. It catches
accessibility, security, Blade, SEO, performance, and markup problems before
they reach review or production.

Rather than treating Blade as plain text, Sheath understands its directives
and components. This helps it point you to the right place and apply a fix
when the change is unambiguous. When dynamic markup makes the answer
uncertain, Sheath stays quiet instead of reporting a guess.

Presets make it easy to get started, while inline suppressions and baselines
help teams adopt Sheath at their own pace. Autofixes handle straightforward
changes. CI-friendly output, caching, parallel linting, and custom rules are
available when you need them.

## Requirements

- PHP 8.2+
- Laravel 12 or 13

## Installation

```bash
composer require fortephp/sheath --dev
```

Sheath is ready to use as soon as Composer finishes. By default, it checks
`resources/views` with the `recommended` preset.

Publish the configuration when you want to change the paths, presets, or
rules:

```bash
php artisan vendor:publish --tag=sheath-config
```

## Usage

Lint your configured views, a directory, or a single template:

```bash
php artisan sheath:lint
php artisan sheath:lint resources/views/components
php artisan sheath:lint resources/views/components/button.blade.php
```

Apply fixes that are safe to make automatically:

```bash
php artisan sheath:lint --fix
```

Some fixes can change rendered output. Preview those changes before choosing
whether to apply them:

```bash
php artisan sheath:lint --dry-run --dangerous
php artisan sheath:lint --fix --dangerous
```

### Adopt Sheath on an existing application

Apply the safe fixes first, then create a baseline for anything you want to
address later:

```bash
php artisan sheath:lint --fix
php artisan sheath:lint --generate-baseline
```

As you address those findings, refresh the baseline:

```bash
php artisan sheath:lint --update-baseline
```

### Modernize older Blade syntax

The `migration` preset helps update older Blade syntax. Preview its changes
before applying them:

```bash
php artisan sheath:lint --preset=migration --dry-run --dangerous
php artisan sheath:lint --preset=migration --fix --dangerous
```

### Run in CI

For GitHub Actions, use the `github` format to add findings to the workflow.
This example also treats warnings as failures and ignores inline suppressions:

```bash
php artisan sheath:lint --format=github --max-warnings=0 --no-inline-config
```

Other available formats include `json`, `checkstyle`, `unix`, and `compact`.

### Report a problem

Include the output of `sheath:support` in bug reports. It lists the Sheath,
PHP, Laravel, and Forte versions, the active configuration, and whether
parallel linting is available, without printing any template source:

```bash
php artisan sheath:support
php artisan sheath:support --json
```

## Configuration

Choose a preset, then override only the rules your project needs to handle
differently:

```php
return [
    'preset' => ['recommended', 'stylistic'],

    'rules' => [
        'best-practices-no-obsolete-tags' => 'off',
        'security-no-raw-echo' => [
            'error',
            ['allowed' => ['$post->renderedBody']],
        ],
    ],
];
```

Available presets are `recommended`, `strict`, `stylistic`, `migration`, and
`empty`. Run `php artisan sheath:lint --print-config` to see exactly which
rules are active and how they are configured.

When an exception is intentional, suppress just that rule and leave a short
reason:

```blade
{{-- sheath-disable-next-line security-no-raw-echo -- trusted sanitized HTML --}}
{!! $post->renderedBody !!}
```

## License

Sheath is open-source software licensed under the [MIT license](license.md).
