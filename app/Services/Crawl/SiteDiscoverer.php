<?php

namespace App\Services\Crawl;

use GuzzleHttp\Client as Guzzle;

/**
 * Given a root domain, returns the URLs we'd want to crawl on first connect.
 *
 * Order of preference:
 *   1. `Sitemap:` directives in /robots.txt (most reliable)
 *   2. /sitemap.xml + /sitemap_index.xml (recursing one level for indexes)
 *   3. Common high-value paths probed via HEAD: /about, /pricing, /faq,
 *      /docs, /help, /support, /contact, /features, /products
 *
 * Returns at most $max URLs deduped, with paths from #1/#2 first.
 *
 * Designed to take <2s on the happy path so it can run synchronously
 * during signup. All HTTP failures are swallowed — this is best-effort
 * metadata, not crawl execution.
 */
class SiteDiscoverer
{
    private const COMMON_PATHS = [
        '/about',
        '/pricing',
        '/features',
        '/products',
        '/faq',
        '/docs',
        '/help',
        '/support',
        '/contact',
    ];

    public function __construct(
        private readonly Guzzle $http = new Guzzle(['timeout' => 5, 'allow_redirects' => true]),
    ) {}

    /**
     * @return array{sitemap_urls: array<int, string>, probed_urls: array<int, string>, root: string}
     */
    public function discover(string $rootUrl, int $max = 50): array
    {
        $root = $this->normalizeRoot($rootUrl);
        if ($root === null) {
            return ['sitemap_urls' => [], 'probed_urls' => [], 'root' => ''];
        }

        $fromSitemaps = $this->fromSitemaps($root, $max);
        $fromProbe = count($fromSitemaps) >= $max
            ? []
            : $this->probeCommonPaths($root, $max - count($fromSitemaps));

        return [
            'sitemap_urls' => $fromSitemaps,
            'probed_urls' => $fromProbe,
            'root' => $root,
        ];
    }

    private function normalizeRoot(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || empty($parts['host'])) {
            return null;
        }
        $scheme = $parts['scheme'] ?? 'https';

        return $scheme.'://'.$parts['host'];
    }

    /** @return array<int, string> */
    private function fromSitemaps(string $root, int $max): array
    {
        $candidateSitemaps = [];

        // 1. robots.txt → Sitemap: directives.
        try {
            $body = (string) $this->http->get($root.'/robots.txt', ['http_errors' => false])->getBody();
            if (preg_match_all('/^\s*Sitemap:\s*(\S+)/im', $body, $m) > 0) {
                foreach ($m[1] as $sitemap) {
                    $candidateSitemaps[] = trim($sitemap);
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        // 2. Conventional fallbacks if robots gave us nothing.
        if ($candidateSitemaps === []) {
            $candidateSitemaps = [$root.'/sitemap.xml', $root.'/sitemap_index.xml'];
        }

        $urls = [];
        foreach ($candidateSitemaps as $sitemap) {
            foreach ($this->fetchSitemap($sitemap, depth: 0) as $u) {
                $urls[] = $u;
                if (count($urls) >= $max) {
                    break 2;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * Recursive sitemap fetch — depth-limited to handle <sitemapindex>.
     *
     * @return array<int, string>
     */
    private function fetchSitemap(string $url, int $depth): array
    {
        if ($depth > 1) {
            return [];
        }

        try {
            $body = (string) $this->http->get($url, ['http_errors' => false])->getBody();
        } catch (\Throwable) {
            return [];
        }

        if ($body === '') {
            return [];
        }

        $isIndex = str_contains($body, '<sitemapindex');
        if (preg_match_all('/<loc>([^<]+)<\/loc>/i', $body, $m) === 0) {
            return [];
        }

        $locs = array_map('trim', $m[1]);

        if (! $isIndex) {
            return $locs;
        }

        // It's a sitemap-of-sitemaps — recurse one level.
        $out = [];
        foreach (array_slice($locs, 0, 5) as $child) {
            foreach ($this->fetchSitemap($child, $depth + 1) as $u) {
                $out[] = $u;
            }
        }

        return $out;
    }

    /**
     * Probe well-known marketing/doc paths in parallel-ish (HEAD requests).
     *
     * @return array<int, string>
     */
    private function probeCommonPaths(string $root, int $max): array
    {
        $found = [];
        foreach (self::COMMON_PATHS as $path) {
            if (count($found) >= $max) {
                break;
            }
            $candidate = $root.$path;
            try {
                $code = $this->http->head($candidate, ['http_errors' => false])->getStatusCode();
                if ($code >= 200 && $code < 400) {
                    $found[] = $candidate;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return $found;
    }
}
