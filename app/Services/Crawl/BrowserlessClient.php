<?php

namespace App\Services\Crawl;

use App\Providers\AppServiceProvider;
use App\Services\Crawl\Contracts\Crawler;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\RequestException;

/**
 * @deprecated 2026-06 Browserless was removed from the default
 * crawl chain in {@see AppServiceProvider} when the
 * Cloudflare /markdown + /content + Vision tiers were introduced —
 * CF covers the same ground free (on the customer's existing
 * Cloudflare bill) and Browserless added a paid third-party
 * dependency for no additional capability. BROWSERLESS_* env vars
 * stay readable so existing .env files don't error, but the chain
 * no longer auto-registers this client. Callers that still want it
 * can bind it manually in a custom service provider.
 */
class BrowserlessClient implements Crawler
{
    public function __construct(
        private readonly Guzzle $http,
        private readonly string $token,
    ) {}

    public static function default(?Guzzle $http = null): self
    {
        return new self(
            $http ?? new Guzzle(['timeout' => 30]),
            (string) config('services.browserless.token', env('BROWSERLESS_TOKEN', '')),
        );
    }

    /**
     * Fetch fully-rendered HTML for a URL.
     */
    public function content(string $url, array $opts = []): string
    {
        $endpoint = rtrim((string) config('services.browserless.url', 'https://chrome.browserless.io'), '/');

        try {
            $response = $this->http->post($endpoint.'/content?token='.$this->token, [
                'json' => [
                    'url' => $url,
                    'waitFor' => $opts['waitFor'] ?? 'domcontentloaded',
                ],
                'http_errors' => false,
            ]);
        } catch (RequestException $e) {
            // Sanitise — Guzzle includes the request URI (with ?token=…) in transport errors.
            throw new \RuntimeException(
                'Browserless fetch failed: '.$this->sanitiseMessage($e->getMessage()),
                previous: $e,
            );
        }

        $code = $response->getStatusCode();
        if ($code === 429) {
            throw new \RuntimeException('Browserless rate-limited (429).');
        }
        if ($code >= 400) {
            throw new \RuntimeException("Browserless returned HTTP {$code}.");
        }

        return (string) $response->getBody();
    }

    private function sanitiseMessage(string $message): string
    {
        if ($this->token === '') {
            return $message;
        }

        return str_replace(
            ['token='.$this->token, $this->token],
            ['token=[REDACTED]', '[REDACTED]'],
            $message,
        );
    }
}
