<?php

namespace App\Services\Crawl;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\RequestException;

/**
 * Cloudflare Browser Rendering — `/screenshot` endpoint.
 *
 * Returns a PNG/JPEG of the rendered page. Used as the first stage of
 * the vision-OCR fallback tier ({@see CloudflareVisionCrawler}) for
 * pages where even rendered HTML lacks extractable text (canvas-rendered
 * slide decks, all-image landing pages, PDF viewers, etc.).
 *
 * REST API:
 *   POST https://api.cloudflare.com/client/v4/accounts/{ACCOUNT_ID}/browser-rendering/screenshot
 *
 * Returns raw binary image bytes (not JSON). Cloudflare sets
 * `Content-Type: image/png` or `image/jpeg` on the response.
 */
class CloudflareBrowserScreenshotClient
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

    /**
     * Fetch a screenshot of the given URL.
     *
     * @return array{bytes: string, mime: string} Raw image bytes + MIME type so the vision client can wrap them correctly.
     */
    public function screenshot(string $url, array $opts = []): array
    {
        $endpoint = "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/browser-rendering/screenshot";

        $payload = [
            'url' => $url,
            'userAgent' => $opts['userAgent'] ?? self::DEFAULT_USER_AGENT,
            'viewport' => $opts['viewport'] ?? ['width' => 1280, 'height' => 1600],
            'gotoOptions' => $opts['gotoOptions'] ?? [
                'waitUntil' => 'networkidle0',
                'timeout' => 25000,
            ],
            // PNG keeps text crisp for the vision model. JPEG would
            // compress badly around glyph edges and hurt OCR accuracy.
            'screenshotOptions' => $opts['screenshotOptions'] ?? [
                'type' => 'png',
                'fullPage' => true,
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
            throw new \RuntimeException("Cloudflare Browser Rendering /screenshot failed: {$e->getMessage()}", previous: $e);
        }

        $code = $response->getStatusCode();
        if ($code === 429) {
            throw new \RuntimeException('Cloudflare Browser Rendering /screenshot rate-limited (429).');
        }
        if ($code >= 400) {
            $body = (string) $response->getBody();
            throw new \RuntimeException("Cloudflare Browser Rendering /screenshot HTTP {$code}: {$body}");
        }

        $mime = $response->getHeaderLine('Content-Type') ?: 'image/png';
        $bytes = (string) $response->getBody();

        if ($bytes === '') {
            throw new \RuntimeException('Cloudflare Browser Rendering /screenshot returned empty body.');
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }
}
