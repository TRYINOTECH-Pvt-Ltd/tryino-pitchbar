<?php

namespace App\Support;

/**
 * Canonical-URL helper used for source identity across the app.
 *
 * The same physical page can be reached through many surface URLs:
 *   https://site.com/page
 *   https://site.com/page/
 *   https://site.com/page?utm_source=newsletter
 *   https://site.com/page#description
 *   HTTPS://Site.COM/page
 *
 * For dedup, citation matching, and "we already indexed this" checks
 * we want them all to compare equal. CanonicalUrl::for() strips:
 *   - URL fragments (#anchor)
 *   - query strings (no useful identity)
 *   - trailing slashes on non-root paths
 *   - userinfo (never index credentials)
 * and lowercases the scheme + host.
 */
class CanonicalUrl
{
    /**
     * Returns the canonical form of $url, or null if it's not parseable
     * or not http(s).
     */
    public static function for(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }
        $host = strtolower($parts['host']);
        if ($host === '') {
            return null;
        }

        $path = $parts['path'] ?? '/';
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        // Drop fragment, query, userinfo by NOT including them.
        return "{$scheme}://{$host}{$port}{$path}";
    }
}
