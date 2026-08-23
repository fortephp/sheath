<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules;

enum RuleCategory: string
{
    case ACCESSIBILITY = 'a11y';

    case BEST_PRACTICES = 'best-practices';

    case BLADE = 'blade';

    case PERFORMANCE = 'perf';

    case SECURITY = 'security';

    case SEO = 'seo';

    public function label(): string
    {
        return match ($this) {
            self::ACCESSIBILITY => 'Accessibility',
            self::BEST_PRACTICES => 'Best Practices',
            self::BLADE => 'Blade',
            self::PERFORMANCE => 'Performance',
            self::SECURITY => 'Security',
            self::SEO => 'SEO',
        };
    }

    public function documentationDirectory(): string
    {
        return match ($this) {
            self::ACCESSIBILITY => 'accessibility',
            self::BEST_PRACTICES => 'best-practices',
            self::BLADE => 'blade',
            self::PERFORMANCE => 'performance',
            self::SECURITY => 'security',
            self::SEO => 'seo',
        };
    }

    public static function nameFor(self|string $category): string
    {
        return $category instanceof self ? $category->value : $category;
    }

    public static function labelFor(self|string $category): string
    {
        return $category instanceof self ? $category->label() : $category;
    }

    public static function fromRuleId(string $ruleId): ?self
    {
        foreach (self::cases() as $category) {
            if (str_starts_with($ruleId, $category->value.'-')) {
                return $category;
            }
        }

        return null;
    }
}
