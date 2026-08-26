<?php

namespace App\Services\Crawl;

use App\Support\Exceptions\UnsafeUrlException;
use App\Support\UrlSafetyGuard;
use GuzzleHttp\Client as Guzzle;
use Illuminate\Support\Facades\Log;

/**
 * Resolves a user-supplied URL into a flat list of crawlable page URLs
 * pulled from sitemap.xml / sitemap_index.xml / robots.txt-advertised
 * sitemaps. Handles three input shapes:
 *
 *   1. Domain root (https://example.com)  → tries /sitemap.xml +
 *      /sitemap_index.xml.
 *   2. Direct sitemap URL (https://example.com/sitemap.xml or
 *      https://example.com/products/sitemap.xml) → fetched verbatim.
 *   3. Sitemap-index that points at child sitemaps (`<sitemapindex>`)
 *      → recursively fetches each child sitemap (one level deep) and
 *      flattens.
 *
 * SSRF guard: every candidate AND every child sitemap URL is passed
 * through UrlSafetyGuard before being fetched. A malicious sitemapindex
 * cannot point at http://169.254.169.254/ or other internal hosts —
 * the entry is silently dropped.
 *
 * Aggregate body-bytes are capped (32MB total across all child
 * fetches) so a hostile sitemapindex with thousands of children
 * cannot OOM the worker.
 *
 * Output is deduped, capped at $max, and stable in input order.
 */
class SitemapDiscoverer
{
    private const PER_FETCH_BYTE_CAP = 8 * 1024 * 1024;

    private const AGGREGATE_BYTE_CAP = 32 * 1024 * 1024;

    public function __construct(
        private readonly Guzzle $http = new Guzzle(['timeout' => 10]),
        private readonly ?UrlSafetyGuard $guard = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function discover(string $rootUrl, int $max = 500): array
    {
        $rootUrl = trim($rootUrl);
        if ($rootUrl === '') {
            return [];
        }

        $candidates = $this->candidatesFor($rootUrl);

        $aggregateBudget = self::AGGREGATE_BYTE_CAP;
        foreach ($candidates as $candidate) {
            $urls = $this->fetchAndExpand($candidate, $max, depth: 0, aggregateBudget: $aggregateBudget);
            if ($urls !== []) {
                return array_slice(array_values(array_unique($urls)), 0, $max);
            }
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function candidatesFor(string $rootUrl): array
    {
        $base = rtrim($rootUrl, '/');
        $path = strtolower((string) parse_url($base, PHP_URL_PATH));

        if (str_ends_with($path, '.xml') || str_contains($path, 'sitemap')) {
            return [$base];
        }

        return [
            $base.'/sitemap.xml',
            $base.'/sitemap_index.xml',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function fetchAndExpand(string $url, int $max, int $depth, int &$aggregateBudget): array
    {
        if ($depth > 1 || $aggregateBudget <= 0) {
            return [];
        }

        if ($this->guard !== null) {
            try {
                $this->guard->assertSafe($url);
            } catch (UnsafeUrlException $e) {
                Log::warning('sitemap.discover.skipped_unsafe_url', [
                    'url_host' => parse_url($url, PHP_URL_HOST),
                    'reason' => $e->getMessage(),
                ]);

                return [];
            }
        }

        try {
            $stream = $this->http->get($url, ['http_errors' => false, 'stream' => true])->getBody();
            $body = '';
            $cap = min(self::PER_FETCH_BYTE_CAP, $aggregateBudget);
            while (! $stream->eof() && strlen($body) < $cap) {
                $body .= $stream->read(min(65536, $cap - strlen($body)));
            }
            $aggregateBudget -= strlen($body);
        } catch (\Throwable) {
            return [];
        }

        if ($body === '') {
            return [];
        }

        $isIndex = str_contains($body, '<sitemapindex');
        $isUrlset = str_contains($body, '<urlset');
        if (! $isIndex && ! $isUrlset) {
            return [];
        }

        if (preg_match_all('/<loc>([^<]+)<\/loc>/i', $body, $m) !== 1
            && empty($m[1])) {
            return [];
        }
        $locs = array_map('trim', $m[1] ?? []);

        if ($isUrlset) {
            return $locs;
        }

        $aggregated = [];
        foreach ($locs as $childSitemap) {
            if ($aggregateBudget <= 0) {
                break;
            }
            $children = $this->fetchAndExpand($childSitemap, $max, $depth + 1, $aggregateBudget);
            foreach ($children as $page) {
                $aggregated[] = $page;
                if (count($aggregated) >= $max) {
                    return $aggregated;
                }
            }
        }

        return $aggregated;
    }
}
