<?php

namespace App\Services\Crawl;

use App\Services\Crawl\Contracts\Crawler;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;

/**
 * Cloudflare Browser Rendering — drop-in replacement for BrowserlessClient.
 *
 * REST API:
 *   https://api.cloudflare.com/client/v4/accounts/{ACCOUNT_ID}/browser-rendering/content
 *
 * Returns rendered HTML for the given URL. Same shape as Browserless's
 * /content endpoint, so the rest of the crawler is unchanged.
 */
class CloudflareBrowserClient implements Crawler
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

    /** Realistic browser fingerprint — many sites send simpler HTML (or block) bot UAs. */
    private const DEFAULT_USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

    public function content(string $url, array $opts = []): string
    {
        $this->assertWithinDailyBudget();

        $endpoint = "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/browser-rendering/content";

        // Cloudflare Browser Rendering accepts `url` plus optional viewport,
        // gotoOptions, userAgent, etc. Sensible defaults below give modern
        // JS-heavy SPAs time to hydrate AND make us look like a real
        // browser instead of a bot. Callers can still override.
        $payload = [
            'url' => $url,
            'userAgent' => $opts['userAgent'] ?? self::DEFAULT_USER_AGENT,
            'viewport' => $opts['viewport'] ?? ['width' => 1280, 'height' => 800],
            'gotoOptions' => $opts['gotoOptions'] ?? [
                // Wait for the page to be quiet for ~500ms before grabbing
                // HTML — gives React/Vue/etc. time to render.
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
            throw new \RuntimeException("Cloudflare Browser Rendering fetch failed: {$e->getMessage()}", previous: $e);
        }

        $code = $response->getStatusCode();
        if ($code === 429) {
            throw new \RuntimeException('Cloudflare Browser Rendering rate-limited (429).');
        }
        if ($code >= 400) {
            $body = (string) $response->getBody();
            throw new \RuntimeException("Cloudflare Browser Rendering HTTP {$code}: {$body}");
        }

        // Cloudflare wraps the result in {"success": true, "result": "<html>...</html>"}
        $body = (string) $response->getBody();
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['result']) && is_string($decoded['result'])) {
            return $decoded['result'];
        }

        // Some rendering responses return raw HTML
        return $body;
    }

    /**
     * Daily-cap guard. Counts every successful enqueue and throws once
     * the env-configured limit is reached so ChainedCrawler can roll
     * forward to a cheaper tier. Default unlimited (limit <= 0).
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
