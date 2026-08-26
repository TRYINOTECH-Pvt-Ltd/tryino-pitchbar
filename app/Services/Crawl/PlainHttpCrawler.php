<?php

namespace App\Services\Crawl;

use App\Services\Crawl\Contracts\Crawler;
use App\Support\Exceptions\UnsafeUrlException;
use App\Support\UrlSafetyGuard;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\RequestException;

/**
 * Free fallback crawler: plain Guzzle GET. Works for any server-rendered
 * page. Fails on heavy SPAs that build HTML in the browser.
 *
 * Used when neither BROWSERLESS_TOKEN nor CLOUDFLARE_API_TOKEN is set —
 * which is precisely the deployment shape where SSRF matters most.
 * Cloudflare Browser Rendering hosts the fetch on Cloudflare's edge, so
 * a malicious URL there only exposes Cloudflare's network. Plain Guzzle
 * fetches from THIS server's egress IP, so a URL like
 * `http://169.254.169.254/...` or `http://localhost:6379/` would walk
 * straight into our metadata service or local Redis. UrlSafetyGuard
 * blocks that — it's defence-in-depth on top of the same gate in
 * CrawlSourceJob (in case any caller bypasses the job entry point).
 */
class PlainHttpCrawler implements Crawler
{
    public function __construct(
        private readonly UrlSafetyGuard $guard,
        private readonly Guzzle $http = new Guzzle([
            'timeout' => 15,
            // Don't follow redirects automatically — a 302 to
            // http://169.254.169.254 would otherwise sail past the
            // per-URL guard. We re-run the guard on every hop below.
            'allow_redirects' => false,
            'headers' => [
                // Mozilla-flavoured UA per CLAUDE.md gotcha #5 — bot
                // UAs (`Pitchbar/1.0`, etc.) get blocked at the edge by
                // Shopify, Cloudflare-fronted sites, and most major
                // SaaS landing pages. We fetch one page per visit
                // (well within polite-bot rate), and the higher-tier
                // crawlers (Cloudflare Browser Rendering, Browserless)
                // already send realistic UAs. This last-resort tier
                // matches them.
                'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
                'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language' => 'en-US,en;q=0.9',
            ],
        ]),
    ) {}

    public function content(string $url, array $opts = []): string
    {
        return $this->fetchFollowingRedirects($url, hops: 0);
    }

    private function fetchFollowingRedirects(string $url, int $hops): string
    {
        if ($hops > 5) {
            throw new \RuntimeException("Plain HTTP refused after 5 redirects for {$url}");
        }

        try {
            $this->guard->assertSafe($url);
        } catch (UnsafeUrlException $e) {
            throw new \RuntimeException("Plain HTTP refused unsafe URL: {$e->getMessage()}", previous: $e);
        }

        try {
            // Force `allow_redirects=false` on the per-request options so
            // a caller-supplied Guzzle Client (tests, custom wiring)
            // can't accidentally re-enable auto-redirects and let the
            // mocked-for-this-hop response sail into a second hop the
            // SSRF guard never sees.
            $response = $this->http->get($url, [
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (RequestException $e) {
            throw new \RuntimeException("Plain HTTP fetch failed: {$e->getMessage()}", previous: $e);
        }

        $code = $response->getStatusCode();
        if ($code >= 300 && $code < 400 && $response->hasHeader('Location')) {
            // Re-validate every hop. Resolves `Location: /relative` too.
            $next = (string) $response->getHeaderLine('Location');
            $resolved = $this->resolveRedirect($url, $next);

            return $this->fetchFollowingRedirects($resolved, $hops + 1);
        }
        if ($code >= 400) {
            throw new \RuntimeException("Plain HTTP returned {$code} for {$url}");
        }

        return (string) $response->getBody();
    }

    private function resolveRedirect(string $from, string $location): string
    {
        // Absolute URL — use it directly.
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        // Relative — resolve against the base URL's scheme + host.
        $parts = parse_url($from);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $location;
        }
        $scheme = $parts['scheme'];
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = str_starts_with($location, '/') ? $location : '/'.$location;

        return "{$scheme}://{$host}{$port}{$path}";
    }
}
