<?php

namespace App\Services\Crawl;

use App\Services\Crawl\Contracts\Crawler;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Crawler that fans out across a configured chain — typically:
 *   plain HTTP → CF Browser Rendering /markdown → CF Browser
 *   Rendering /content → CF Vision (screenshot + Workers AI OCR).
 *
 * Each call to `content()` tries the chain in order. The FIRST tier
 * that produces extractable text above the threshold wins; failures
 * (network, 4xx, 5xx, timeout, empty extraction) move to the next link.
 *
 * Why extracted text — not raw HTML:
 *   Pre-2026-06 the threshold was on RAW HTML length. CF Browser
 *   Rendering returns 50KB of empty `<div>` shells for JS-heavy SPAs
 *   that never hydrate → the chain said "winner!" → HtmlExtractor
 *   stripped it all → CrawlPageJob bailed at line 109 with "Page
 *   returned too little content" — and the fallback tiers (vision
 *   OCR especially) never got a chance to recover. Measuring the
 *   actual readable text after extraction means the chain promotes
 *   exactly when the extractor would have failed downstream.
 *
 * The extractor is injected so tests can stub it. When no extractor
 * is provided, the chain falls back to the raw-HTML length check
 * (legacy behaviour preserved for callers that haven't migrated).
 */
class ChainedCrawler implements Crawler
{
    /**
     * Threshold (chars). Aligned with the
     * `Page returned too little content` guard that used to live in
     * CrawlPageJob — the chain now owns this contract.
     */
    private const EXTRACTED_TEXT_THRESHOLD = 200;

    /**
     * @param  array<int, array{name: string, client: Crawler}>  $tiers
     *                                                                   Ordered fallback chain. tier[0] is primary.
     */
    public function __construct(
        private readonly array $tiers,
        private readonly ?ReadabilityExtractor $extractor = null,
    ) {}

    public function content(string $url, array $opts = []): string
    {
        if ($this->tiers === []) {
            throw new \RuntimeException('Crawler chain is empty — no client configured.');
        }

        // Short-lived response cache — same URL fetched twice in the
        // same crawl batch (e.g. sitemap + linked nav re-discovering)
        // returns the cached body instead of re-hitting CF Browser.
        // Disabled when `crawl.response_cache_ttl_seconds <= 0`.
        $cacheTtl = (int) config('services.crawl.response_cache_ttl_seconds', 300);
        $cacheKey = $cacheTtl > 0 ? 'crawler:resp:'.sha1($url) : null;
        if ($cacheKey !== null) {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $this->meetsThreshold($cached)) {
                return $cached;
            }
        }

        $errors = [];

        foreach ($this->tiers as $tier) {
            $name = (string) $tier['name'];
            $client = $tier['client'];

            try {
                $html = $client->content($url, $opts);
                if ($this->meetsThreshold($html)) {
                    if ($errors !== []) {
                        Log::info('crawler.chain.recovered', [
                            'url' => $url,
                            'winner' => $name,
                            'failed_tiers' => array_keys($errors),
                        ]);
                    }
                    if ($cacheKey !== null) {
                        Cache::put($cacheKey, $html, $cacheTtl);
                    }

                    return $html;
                }
                $errors[$name] = sprintf(
                    'extraction produced %d chars (under threshold)',
                    $this->extractedLength($html),
                );
            } catch (Throwable $e) {
                $errors[$name] = $e->getMessage();
            }
        }

        // Every tier failed. Before giving up: a TLS certificate that covers
        // only the apex domain fails EVERY tier for a www.<domain> URL with
        // cURL error 60 ("SSL: no alternative certificate subject name matches
        // target host name 'www.…'"). That is a common site misconfiguration —
        // the apex almost always serves identical content — so retry the apex
        // host ONCE. Self-terminating: the apex URL has no "www." prefix, so the
        // guard returns null on the recursive call. Gated on the cert-mismatch
        // signature so a genuinely broken www-only host (500 / DNS / 404) is not
        // handed a pointless second fetch.
        $apexUrl = $this->wwwApexFallbackUrl($url, $errors);
        if ($apexUrl !== null) {
            Log::info('crawler.chain.www_apex_fallback', ['from' => $url, 'to' => $apexUrl]);

            return $this->content($apexUrl, $opts);
        }

        // Exhausted every tier — bubble up with a composite message so
        // CrawlPageJob's failed() handler surfaces the actual cause to
        // the operator via sources.error.
        throw new \RuntimeException(
            'Every crawler tier failed for '.$url.': '.json_encode($errors),
        );
    }

    /**
     * The apex-host retry URL for a www.<domain> URL that failed every tier
     * with a TLS certificate host mismatch — or null when no such fallback
     * applies. Strips the leading "www." and preserves everything else
     * (scheme, port, path, query). Returns null unless the collected tier
     * errors carry the cert-mismatch signature AND the host is www.*, which
     * also makes the apex retry self-terminating.
     *
     * @param  array<string, string>  $errors  tier name => failure message
     */
    private function wwwApexFallbackUrl(string $url, array $errors): ?string
    {
        if (! $this->hasCertHostMismatch($errors)) {
            return null;
        }

        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || ! str_starts_with($host, 'www.')) {
            return null;
        }

        $scheme = $parts['scheme'] ?? 'https';
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        return $scheme.'://'.substr($host, 4).$port.$path.$query;
    }

    /**
     * Whether any tier failed with a TLS certificate host mismatch — cURL
     * error 60, whose message is "SSL: no alternative certificate subject
     * name matches target host name '…'". This is the ONE failure the apex
     * retry can fix; every other failure is left to fail exactly as before.
     *
     * @param  array<string, string>  $errors
     */
    private function hasCertHostMismatch(array $errors): bool
    {
        foreach ($errors as $message) {
            $needle = strtolower($message);
            if (str_contains($needle, 'curl error 60')
                || str_contains($needle, 'no alternative certificate subject name')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a tier's output meets the extraction threshold. When an
     * extractor is wired, measures the extracted text length; without
     * one, falls back to raw HTML length (legacy behaviour).
     */
    private function meetsThreshold(string $html): bool
    {
        return $this->extractedLength($html) >= self::EXTRACTED_TEXT_THRESHOLD;
    }

    /**
     * Compute the length of extractable text. With an extractor, runs
     * the same readability pipeline downstream consumers will. Without
     * one, returns the raw HTML length so the legacy threshold rule
     * still applies.
     */
    private function extractedLength(string $html): int
    {
        if ($this->extractor === null) {
            return mb_strlen($html);
        }

        try {
            $extracted = $this->extractor->extract($html);

            return mb_strlen($extracted['text'] ?? '');
        } catch (Throwable) {
            // Extraction blew up on malformed HTML — treat as zero
            // length so the chain promotes to the next tier.
            return 0;
        }
    }
}
