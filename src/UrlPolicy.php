<?php

declare(strict_types=1);

namespace Kaly\Tpl;

/**
 * URL policy shared by {@see DefaultEscaper::url()} and the URL-list attributes.
 *
 * Allowed: relative URLs, fragments, queries and the `http`, `https`, `mailto` and `tel`
 * schemes. Rejected: other schemes, protocol-relative URLs and control characters.
 *
 * @internal
 */
final class UrlPolicy
{
    public static function isSafe(string $url): bool
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

    /**
     * Safe and resolving to HTTP(S): relative, `http` or `https`. Used for `ping`, which
     * browsers only send to HTTP(S) URLs.
     */
    public static function isHttp(string $url): bool
    {
        if (!self::isSafe($url)) {
            return false;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        if ($scheme === null || $scheme === false || $scheme === '') {
            return true;
        }

        return in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
