<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * Default HTML escaper for trusted application templates.
 *
 * It provides explicit escaping for text, attributes, URLs, JSON payloads,
 * attribute bags, class lists and CSS custom properties.
 */
final class DefaultEscaper implements Escaper
{
    private AttributeEscaper $attributes;

    public function __construct()
    {
        $this->attributes = new AttributeEscaper($this);
    }

    public function html(mixed $value): string
    {
        return htmlspecialchars($this->string($value), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8', true);
    }

    public function attr(mixed $value): string
    {
        return $this->html($value);
    }

    public function url(string|\Stringable|null $value): string
    {
        if ($value === null) {
            return '';
        }

        $url = trim((string) $value);
        if ($url === '') {
            return '';
        }

        if (!$this->isSafeUrl($url)) {
            return '#';
        }

        return $this->attr($url);
    }

    public function json(mixed $value): Html
    {
        return new Html(json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE,
        ));
    }

    public function attrs(array $attributes): Html
    {
        return $this->attributes->attrs($attributes);
    }

    public function classes(mixed ...$values): string
    {
        return $this->attributes->classes(...$values);
    }

    public function styleVars(array $vars): Html
    {
        return $this->attributes->styleVars($vars);
    }

    private function isSafeUrl(string $url): bool
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme !== null && $scheme !== false && $scheme !== '') {
            return in_array(strtolower($scheme), ['http', 'https', 'mailto', 'tel'], true);
        }

        if (str_replace('\\', '/', substr($url, 0, 2)) === '//') {
            return false;
        }

        return (
            str_starts_with($url, '/')
            || str_starts_with($url, '#')
            || str_starts_with($url, '?')
            || !preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)
        );
    }

    private function string(mixed $value): string
    {
        if ($value === null || $value === false) {
            return '';
        }

        if (is_scalar($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw new \InvalidArgumentException(sprintf('Cannot escape value of type %s.', get_debug_type($value)));
    }
}
