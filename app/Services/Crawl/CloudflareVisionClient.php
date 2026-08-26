<?php

namespace App\Services\Crawl;

use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Cache;

/**
 * Cloudflare Workers AI — vision model OCR.
 *
 * REST API:
 *   POST https://api.cloudflare.com/client/v4/accounts/{ACCOUNT_ID}/ai/v1/chat/completions
 *
 * Uses the OpenAI-compatible chat-completions interface with a
 * multimodal user message: text prompt + base64-encoded image. The
 * model returns the extracted text in `choices[0].message.content`.
 *
 * Default model: `@cf/meta/llama-3.2-11b-vision-instruct`. Operators
 * can override via CLOUDFLARE_VISION_MODEL env. Daily cap separate from
 * the Browser Rendering cap because vision calls consume Workers AI
 * Neurons, not Browser Rendering invocations.
 */
class CloudflareVisionClient
{
    public function __construct(
        private readonly Guzzle $http,
        private readonly string $accountId,
        private readonly string $apiToken,
        private readonly string $model = '@cf/meta/llama-3.2-11b-vision-instruct',
        private readonly ?string $aiGatewayUrl = null,
    ) {}

    public static function default(
        string $accountId,
        string $apiToken,
        ?string $model = null,
        ?string $aiGatewayUrl = null,
        ?Guzzle $http = null,
    ): self {
        return new self(
            $http ?? new Guzzle(['timeout' => 60, 'connect_timeout' => 5]),
            $accountId,
            $apiToken,
            $model ?? '@cf/meta/llama-3.2-11b-vision-instruct',
            $aiGatewayUrl,
        );
    }

    /**
     * The prompt is locked in code instead of being passed through
     * because the entire purpose of this tier is well-defined and
     * letting callers customise it would invite prompt-injection
     * footguns (e.g. a webhook payload steering the model away from
     * "extract text").
     */
    private const SYSTEM_PROMPT = 'You are an OCR extraction engine. Your only job is to read text from images and return it verbatim.';

    private const USER_PROMPT = 'Extract every readable line of text from this webpage screenshot in natural reading order (top to bottom, left to right). Preserve paragraph breaks. Skip navigation menus, headers, footers, cookie banners, and chat widgets. Skip anything that is purely decorative (icons, logos without alt text, dividers). Return ONLY the extracted text, no commentary, no summary, no markdown formatting.';

    /**
     * @param  string  $imageBytes  Raw image bytes (PNG/JPEG)
     * @param  string  $mime  MIME type, e.g. `image/png`
     * @return string Extracted text — empty string when the model returns no usable text.
     */
    public function extractText(string $imageBytes, string $mime = 'image/png'): string
    {
        $this->assertWithinDailyBudget();

        $baseUri = $this->aiGatewayUrl !== null && $this->aiGatewayUrl !== ''
            ? rtrim($this->aiGatewayUrl, '/')
            : "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/ai/v1";

        $encoded = base64_encode($imageBytes);
        $dataUrl = "data:{$mime};base64,{$encoded}";

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => self::USER_PROMPT],
                        ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                    ],
                ],
            ],
            // Deterministic + reasonably long — pages with lots of
            // text need headroom. The model usually truncates well
            // below this on real screenshots.
            'temperature' => 0,
            'max_tokens' => 4096,
        ];

        try {
            $response = $this->http->post($baseUri.'/chat/completions', [
                'json' => $payload,
                'headers' => [
                    'Authorization' => "Bearer {$this->apiToken}",
                    'Content-Type' => 'application/json',
                ],
                'http_errors' => false,
            ]);
        } catch (RequestException $e) {
            throw new \RuntimeException("Cloudflare Workers AI vision call failed: {$e->getMessage()}", previous: $e);
        }

        $code = $response->getStatusCode();
        if ($code === 429) {
            throw new \RuntimeException('Cloudflare Workers AI vision rate-limited (429).');
        }
        if ($code >= 400) {
            $body = (string) $response->getBody();
            throw new \RuntimeException("Cloudflare Workers AI vision HTTP {$code}: {$body}");
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('Cloudflare Workers AI vision returned non-JSON body.');
        }

        $text = $decoded['choices'][0]['message']['content'] ?? null;
        if (! is_string($text)) {
            // Some Workers AI envelopes return the body under
            // `result.response` rather than the OpenAI-style choices
            // when proxied through a gateway. Handle both shapes.
            $text = $decoded['result']['response'] ?? '';
            if (! is_string($text)) {
                $text = '';
            }
        }

        return trim($text);
    }

    /**
     * Separate daily-cap bucket from CLOUDFLARE_BROWSER_DAILY_LIMIT —
     * vision calls consume Workers AI Neurons; Browser Rendering
     * calls have their own quota. Operators can dial each
     * independently.
     */
    private function assertWithinDailyBudget(): void
    {
        $limit = (int) (function_exists('app') && app()->bound('config')
            ? config('services.cloudflare.vision_daily_limit', 0)
            : (int) env('CLOUDFLARE_VISION_DAILY_LIMIT', 0));
        if ($limit <= 0) {
            return;
        }

        $key = 'cf_vision_calls:'.now()->format('Y-m-d');
        $count = (int) Cache::get($key, 0);
        if ($count >= $limit) {
            throw new \RuntimeException("Cloudflare Workers AI vision daily limit reached ({$limit}); falling back to next tier.");
        }
        Cache::add($key, 0, now()->endOfDay()->addMinute());
        Cache::increment($key);
    }
}
