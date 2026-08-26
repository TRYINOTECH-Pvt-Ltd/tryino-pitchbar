<?php

namespace App\Services\Crawl;

use App\Services\Crawl\Contracts\Crawler;
use App\Support\SafeMarkdown;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;

/**
 * Cloudflare Browser Rendering — `/markdown` endpoint.
 *
 * REST API:
 *   POST https://api.cloudflare.com/client/v4/accounts/{ACCOUNT_ID}/browser-rendering/markdown
 *
 * Runs headless Chrome AND emits clean markdown server-side, strictly
 * better than fetching HTML and stripping with our regex pipeline:
 *   - structural fidelity (headings, lists, tables) preserved
 *   - chrome/nav already removed by Cloudflare's extractor
 *   - smaller payload over the wire (markdown << rendered HTML)
 *
 * Same auth + daily-cap shape as CloudflareBrowserClient. We wrap the
 * markdown body in a minimal HTML envelope so the rest of the crawler
 * (ReadabilityExtractor / HtmlExtractor / chunker) stays unchanged.
 */
class CloudflareBrowserMarkdownCrawler implements Crawler
{
    public function __construct(
        private readonly Guzzle $http,
        private readonly string $accountId,
        private readonly string $apiToken,
    ) {}

    public static function default(string $accountId, string $apiToken, ?Guzzle $http = null): self
    {
        return new self(
            $http ?? new Guzzle(['timeout' => 30]),
            $accountId,
            $apiToken,
        );
    }

    private const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    public function content(string $url, array $opts = []): string
    {
        $this->assertWithinDailyBudget();

        $endpoint = "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/browser-rendering/markdown";

        $payload = [
            'url' => $url,
            'userAgent' => $opts['userAgent'] ?? self::DEFAULT_USER_AGENT,
            'viewport' => $opts['viewport'] ?? ['width' => 1280, 'height' => 800],
            'gotoOptions' => $opts['gotoOptions'] ?? [
                'waitUntil' => 'networkidle0',
                'timeout' => 25000,
            ],
        ];

        try {
            $response = $this->http->post($endpoint, [
                'json' => $payload,
                'headers' => [
                    'Authorization' => "Bearer {$this->apiToken}",
                    'Content-Type' => 'application/json',
                ],
                'http_errors' => false,
            ]);
        } catch (RequestException $e) {
            throw new \RuntimeException("Cloudflare Browser Rendering /markdown failed: {$e->getMessage()}", previous: $e);
        }

        $code = $response->getStatusCode();
        if ($code === 429) {
            throw new \RuntimeException('Cloudflare Browser Rendering rate-limited (429).');
        }
        if ($code >= 400) {
            $body = (string) $response->getBody();
            throw new \RuntimeException("Cloudflare Browser Rendering /markdown HTTP {$code}: {$body}");
        }

        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        $markdown = '';
        if (is_array($decoded) && isset($decoded['result']) && is_string($decoded['result'])) {
            $markdown = $decoded['result'];
        } else {
            // Some envelopes return raw markdown without the {result: ...} wrapper.
            $markdown = $body;
        }

        if (trim($markdown) === '') {
            // Surface empty pages as exceptions so ChainedCrawler
            // promotes us to the next tier instead of accepting empty.
            throw new \RuntimeException('Cloudflare Browser Rendering /markdown returned empty body.');
        }

        return $this->wrapMarkdownAsHtml($markdown);
    }

    /**
     * Wrap markdown in a minimal HTML envelope so downstream extractors
     * (ReadabilityExtractor + HtmlExtractor) treat it like a normal
     * crawl response. Uses SafeMarkdown so any HTML embedded in the
     * markdown body is stripped (defence in depth — Cloudflare's
     * markdown extractor should already be safe).
     */
    private function wrapMarkdownAsHtml(string $markdown): string
    {
        $rendered = SafeMarkdown::render($markdown);

        return "<!DOCTYPE html><html><head><meta charset=\"utf-8\"></head><body><article>{$rendered}</article></body></html>";
    }

    /**
     * Daily-cap guard. Shares the same `cf_browser_calls:` bucket as
     * the /content endpoint so a single env var (CLOUDFLARE_BROWSER_DAILY_LIMIT)
     * governs total Browser Rendering spend across both tiers.
     */
    private function assertWithinDailyBudget(): void
    {
        $limit = (int) (function_exists('app') && app()->bound('config')
            ? config('services.cloudflare.browser_daily_limit', 0)
            : (int) env('CLOUDFLARE_BROWSER_DAILY_LIMIT', 0));
        if ($limit <= 0) {
            return;
        }

        $key = 'cf_browser_calls:'.now()->format('Y-m-d');
        $count = (int) Cache::get($key, 0);
        if ($count >= $limit) {
            throw new \RuntimeException("Cloudflare Browser Rendering daily limit reached ({$limit}); falling back to next tier.");
        }
        Cache::add($key, 0, now()->endOfDay()->addMinute());
        Cache::increment($key);
    }
}
