<?php

declare(strict_types=1);

namespace Forte\Sheath\Rules\Security;

use Forte\Ast\Document\Document;
use Forte\Ast\Elements\Attribute;
use Forte\Ast\Elements\ElementNode;
use Forte\Sheath\Parsing\SrcsetParser;
use Forte\Sheath\Results\Fix;
use Forte\Sheath\Results\Severity;
use Forte\Sheath\Rules\AbstractRule;
use Forte\Sheath\Rules\Concerns\DetectsOpaqueAttributes;
use Forte\Sheath\Rules\RuleCategory;
use Forte\Sheath\Rules\RuleContext;
use Forte\Support\AttributeQuoting;

/** @internal */
class PreferHttpsRule extends AbstractRule
{
    use DetectsOpaqueAttributes;

    private const URL_ATTRIBUTES = [
        'href',
        'src',
        'action',
        'data',
        'poster',
        'srcset',
        'imagesrcset',
        'formaction',
        'cite',
        'manifest',
        'ping',
    ];

    private const DEFAULT_ALLOWED_HOSTS = [
        'localhost',
        '127.0.0.1',
        '[::1]',
    ];

    protected array $options = [
        'allowedHosts' => self::DEFAULT_ALLOWED_HOSTS,
    ];

    public function getId(): string
    {
        return 'security-prefer-https';
    }

    public function getDescription(): string
    {
        return 'URLs should use HTTPS instead of HTTP for security.';
    }

    public function getCategory(): RuleCategory
    {
        return RuleCategory::SECURITY;
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::WARNING;
    }

    public function check(Document $document, RuleContext $context): void
    {
        $context->elements()->each(function (ElementNode $element) use ($context): void {
            $attributesByName = $this->firstAttributesByNameOnRenderPaths($element, self::URL_ATTRIBUTES);

            foreach (self::URL_ATTRIBUTES as $attr) {
                $attributes = $attributesByName === null
                    ? $this->firstAttributesOnRenderPaths($element, $attr)
                    : $attributesByName[$attr];
                if ($attributes === null) {
                    continue;
                }

                $seen = [];
                foreach ($attributes as $attrNode) {
                    if ($attrNode === null || isset($seen[spl_object_id($attrNode)])) {
                        continue;
                    }
                    $seen[spl_object_id($attrNode)] = true;

                    if (! $attrNode->isUnconditionallyPresent()) {
                        continue;
                    }

                    $value = $this->staticUrlPrefix($attrNode);
                    $this->checkUrlAttribute($element, $attrNode, $attr, $value, $context);
                }
            }
        });
    }

    private function checkUrlAttribute(
        ElementNode $element,
        Attribute $attributeNode,
        string $attribute,
        string $value,
        RuleContext $context
    ): void {
        if ($attribute === 'srcset' || $attribute === 'imagesrcset') {
            $this->checkSrcset($element, $attribute, $value, $context);

            return;
        }

        if ($attribute === 'ping') {
            $this->checkPing($element, $value, $context);

            return;
        }

        $httpUrl = $this->httpUrl($value);

        if ($httpUrl !== null && ! $this->hostIsAllowed($httpUrl['url'])) {
            $context->report(
                $element,
                "Insecure HTTP URL in \"{$attribute}\" attribute.",
                $attributeNode->hasComplexValue() || $httpUrl['schemeOffset'] === null
                    ? null
                    : $this->createHttpsFix($attributeNode, $attribute, $value, $httpUrl['schemeOffset'])
            );
        }
    }

    private function staticUrlPrefix(Attribute $attribute): string
    {
        if (! $attribute->hasComplexValue()) {
            return $attribute->decodedValueText() ?? '';
        }

        $prefix = '';
        foreach ($attribute->value()?->parts() ?? [] as $part) {
            if (! is_string($part)) {
                break;
            }

            $prefix .= html_entity_decode($part, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $prefix;
    }

    private function checkSrcset(
        ElementNode $element,
        string $attribute,
        string $srcset,
        RuleContext $context,
    ): void {
        foreach (SrcsetParser::urls($srcset) as $url) {
            $httpUrl = $this->httpUrl($url);
            if ($httpUrl !== null && ! $this->hostIsAllowed($httpUrl['url'])) {
                $context->report(
                    $element,
                    "Insecure HTTP URL in {$attribute}."
                );
                break;
            }
        }
    }

    private function checkPing(ElementNode $element, string $value, RuleContext $context): void
    {
        $urls = preg_split('/[ \t\n\f\r]+/', trim($value, " \t\n\f\r")) ?: [];

        foreach ($urls as $url) {
            $httpUrl = $this->httpUrl($url);
            if ($httpUrl !== null && ! $this->hostIsAllowed($httpUrl['url'])) {
                $context->report(
                    $element,
                    'Insecure HTTP URL in ping.'
                );

                return;
            }
        }
    }

    private function hostIsAllowed(string $url): bool
    {
        $host = $this->extractHost($url);

        if ($host === '') {
            return false;
        }

        $allowedOption = $this->getOption('allowedHosts', self::DEFAULT_ALLOWED_HOSTS);
        $allowed = is_array($allowedOption) ? array_filter($allowedOption, is_string(...)) : [];

        $host = strtolower($host);
        $host = $this->canonicalIpv4Host($host) ?? $host;

        foreach ($allowed as $allowedHost) {
            $allowedHost = strtolower($allowedHost);
            $allowedHost = $this->canonicalIpv4Host($allowedHost) ?? $allowedHost;

            if ($allowedHost === $host) {
                return true;
            }
        }

        return false;
    }

    private function extractHost(string $url): string
    {
        $rest = substr($url, strlen('http://'));

        $authorityEnd = strcspn($rest, '/\\?#');
        $authority = substr($rest, 0, $authorityEnd);

        $atPos = strrpos($authority, '@');
        if ($atPos !== false) {
            $authority = substr($authority, $atPos + 1);
        }

        if (str_starts_with($authority, '[')) {
            $closing = strpos($authority, ']');

            return $closing === false ? $authority : substr($authority, 0, $closing + 1);
        }

        $colonPos = strpos($authority, ':');

        return $colonPos === false ? $authority : substr($authority, 0, $colonPos);
    }

    /** @return array{url: string, schemeOffset: int|null}|null */
    private function httpUrl(string $value): ?array
    {
        preg_match('/\A[\x00-\x20]*/', $value, $matches);
        $offset = strlen($matches[0] ?? '');
        $candidate = substr($value, $offset);

        if (str_starts_with(strtolower($candidate), 'http://')) {
            return ['url' => $candidate, 'schemeOffset' => $offset];
        }

        $normalized = preg_replace('/[\x09\x0A\x0D]/', '', $value) ?? $value;
        preg_match('/\A[\x00-\x20]*/', $normalized, $matches);
        $candidate = substr($normalized, strlen($matches[0] ?? ''));

        if (! str_starts_with(strtolower($candidate), 'http:')) {
            return null;
        }

        $remainder = preg_replace('/\A[\\\\\/]+/', '', substr($candidate, 5)) ?? substr($candidate, 5);

        return ['url' => 'http://'.$remainder, 'schemeOffset' => null];
    }

    private function canonicalIpv4Host(string $host): ?string
    {
        $parts = explode('.', $host);
        if (end($parts) === '') {
            array_pop($parts);
        }

        if ($parts === [] || count($parts) > 4) {
            return null;
        }

        $numbers = [];
        foreach ($parts as $part) {
            if ($part === '') {
                return null;
            }

            $base = 10;
            $digits = $part;
            if (preg_match('/\A0[xX]([0-9A-Fa-f]+)\z/', $part, $match) === 1) {
                $base = 16;
                $digits = $match[1];
            } elseif (strlen($part) > 1 && $part[0] === '0') {
                $base = 8;
                $digits = substr($part, 1) ?: '0';
            }

            if (($base === 10 && preg_match('/\A[0-9]+\z/', $digits) !== 1)
                || ($base === 8 && preg_match('/\A[0-7]+\z/', $digits) !== 1)) {
                return null;
            }

            $numbers[] = intval($digits, $base);
        }

        $last = array_pop($numbers);
        if ($last === null || $last >= 256 ** (4 - count($numbers))) {
            return null;
        }

        $value = $last;
        foreach ($numbers as $index => $number) {
            if ($number > 255) {
                return null;
            }

            $value += $number * (256 ** (3 - $index));
        }

        return implode('.', [
            ($value >> 24) & 255,
            ($value >> 16) & 255,
            ($value >> 8) & 255,
            $value & 255,
        ]);
    }

    private function createHttpsFix(Attribute $attrNode, string $attribute, string $value, int $schemeOffset): ?Fix
    {
        $startOffset = $attrNode->startOffset();
        $endOffset = $attrNode->endOffset();

        if ($startOffset < 0) {
            return null;
        }

        $newValue = substr($value, 0, $schemeOffset).'https://'.substr($value, $schemeOffset + 7);
        $quote = AttributeQuoting::styleOf($attrNode);
        $replacement = AttributeQuoting::render($attribute, $newValue, $quote);

        return new Fix($startOffset, $endOffset, $replacement, dangerous: true);
    }
}
