<?php

declare(strict_types=1);

namespace Forte\Sheath\Configuration;

use Forte\Sheath\Rules\RuleRegistry;

enum RulePreset: string
{
    case RECOMMENDED = 'recommended';
    case STRICT = 'strict';
    case STYLISTIC = 'stylistic';
    case MIGRATION = 'migration';
    case EMPTY = 'empty';

    /**
     * @var array<string, string>
     */
    private const RECOMMENDED_RULES = [
        'blade-alpine-directive-integrity' => 'error',
        'blade-alpine-for-key-integrity' => 'warning',

        'a11y-alt-text' => 'error',
        'a11y-anchor-content' => 'error',
        'a11y-button-accessible-name' => 'error',
        'a11y-form-label' => 'error',
        'a11y-html-lang' => 'error',
        'a11y-list-semantics' => 'error',
        'a11y-no-abstract-roles' => 'error',
        'a11y-no-accesskey' => 'warning',
        'a11y-no-aria-hidden-on-focusable' => 'error',
        'a11y-no-autofocus' => 'warning',
        'a11y-no-empty-headings' => 'error',
        'a11y-no-heading-inside-button' => 'warning',
        'a11y-no-invalid-role' => 'error',
        'a11y-no-non-scalable-viewport' => 'error',
        'a11y-no-unknown-aria-attributes' => 'error',
        'a11y-no-unsupported-aria-properties' => 'error',
        'a11y-no-positive-tabindex' => 'warning',
        'a11y-no-skip-heading-levels' => 'warning',
        'a11y-required-aria-properties' => 'error',
        'a11y-require-frame-title' => 'error',
        'a11y-table-headers' => 'warning',
        'a11y-valid-aria-values' => 'error',

        'security-csrf-field' => 'error',
        'security-no-target-blank' => 'error',
        'security-prefer-https' => 'error',

        'blade-component-tag-integrity' => 'error',
        'blade-component-required-props' => 'error',
        'blade-forelse-empty-arguments' => 'error',
        'blade-forelse-has-empty' => 'error',
        'blade-method-field' => 'error',
        'blade-no-compiler-directives-in-comments' => 'error',
        'blade-no-debug' => 'error',
        'blade-no-directive-attribute-collision' => 'error',
        'blade-no-directive-space' => 'warning',
        'blade-no-else-condition' => 'error',
        'blade-no-php-tag' => 'warning',
        'blade-no-triple-echo' => 'warning',
        'blade-no-unquoted-echo-attribute' => 'warning',
        'blade-reactive-template-structure' => 'error',
        'blade-switch-structure' => 'error',
        'blade-unclosed-directives' => 'error',
        'blade-valid-directive-arguments' => 'error',
        'blade-valid-echo-expression' => 'error',
        'blade-valid-php-syntax' => 'error',
        'blade-view-reference-exists' => 'error',

        'blade-livewire-directive-integrity' => 'error',
        'blade-livewire-loop-key-integrity' => 'error',

        'blade-reactive-directive-conflicts' => 'error',

        'best-practices-button-type' => 'warning',
        'best-practices-no-duplicate-attrs' => 'error',
        'best-practices-no-duplicate-class' => 'warning',
        'best-practices-no-duplicate-id' => 'error',
        'best-practices-no-duplicate-in-head' => 'warning',
        'best-practices-no-nested-interactive' => 'error',
        'best-practices-no-obsolete-tags' => 'warning',
        'best-practices-no-script-style-type' => 'warning',
        'best-practices-require-doctype' => 'warning',
        'best-practices-require-form-method' => 'warning',
        'best-practices-require-li-container' => 'error',
        'best-practices-require-meta-charset' => 'warning',
        'best-practices-require-meta-viewport' => 'warning',

        'perf-no-render-blocking' => 'warning',
        'seo-no-multiple-h1' => 'warning',
        'seo-require-title' => 'warning',
    ];

    /**
     * @var array<string, string>
     */
    private const STRICT_ADDITIONS = [
        'security-no-inline-js' => 'warning',
        'security-no-raw-echo' => 'error',

        'blade-no-logic-in-views' => 'warning',
        'blade-prefer-json-in-script' => 'warning',

        'perf-lazy-load-images' => 'warning',
        'perf-require-explicit-size' => 'warning',
        'perf-responsive-images' => 'warning',

        'seo-canonical-tag' => 'warning',
        'seo-meta-description' => 'warning',
        'seo-require-open-graph' => 'warning',
    ];

    /**
     * @var array<string, string>
     */
    private const STYLISTIC_RULES = [
        'best-practices-no-inline-styles' => 'warning',
        'best-practices-self-closing-void-elements' => 'warning',
        'blade-component-self-closing' => 'warning',
        'blade-prefer-forelse' => 'warning',
        'blade-prefer-unless' => 'warning',
    ];

    /**
     * @var array<string, string>
     */
    private const MIGRATION_RULES = [
        'blade-no-php-echo' => 'warning',
        'blade-prefer-component-tags' => 'info',
        'blade-prefer-endsection' => 'info',
        'blade-prefer-lang-helper' => 'info',
    ];

    /**
     * @var array<string>
     */
    private const OPT_IN_ONLY = [
        'blade-require-props',
    ];

    public static function tryFromName(string $name): ?self
    {
        return self::tryFrom(strtolower(trim($name)));
    }

    /**
     * @return array<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $preset): string => $preset->value, self::cases());
    }

    /**
     * @return array<string, string>
     */
    public function rules(RuleRegistry $registry): array
    {
        return array_intersect_key(
            $this->declaredRules(),
            array_flip($registry->all())
        );
    }

    /**
     * @return array<string, string>
     */
    public function declaredRules(): array
    {
        return match ($this) {
            self::RECOMMENDED => self::RECOMMENDED_RULES,
            self::STRICT => array_merge(self::RECOMMENDED_RULES, self::STRICT_ADDITIONS),
            self::STYLISTIC => self::STYLISTIC_RULES,
            self::MIGRATION => self::MIGRATION_RULES,
            self::EMPTY => [],
        };
    }

    /**
     * @return array<string>
     */
    public static function classifiedRuleIds(): array
    {
        return array_merge(
            array_keys(array_merge(
                self::STRICT->declaredRules(),
                self::STYLISTIC->declaredRules(),
                self::MIGRATION->declaredRules(),
            )),
            self::OPT_IN_ONLY,
        );
    }

    /**
     * @return array<string>
     */
    public static function optInOnlyRuleIds(): array
    {
        return self::OPT_IN_ONLY;
    }
}
