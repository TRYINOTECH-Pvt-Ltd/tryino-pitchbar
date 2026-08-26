<?php

namespace App\Services\Llm;

use App\Services\Llm\Contracts\OpenAiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Measures and caches the real time-to-first-token a buyer sees when
 * the bot streams a reply from the configured LLM provider. The system
 * settings page shows these numbers next to the static ModelCatalog
 * estimate so admins can confirm: "yes, gpt-4o-mini really is ~280 ms
 * from my server".
 *
 * Flow:
 *   1. Resolve the bound OpenAiClient (Cloudflare / OpenAI / OpenRouter
 *      / Fake — driven by AppServiceProvider's provider auto-bind).
 *   2. Send a one-token streamChat ("Reply with 'ok'.").
 *   3. Record hrtime at start, again on the first token chunk we see
 *      that contains ANY non-empty character — that's TTFT.
 *   4. Drain the stream up to 8 tokens, then record total wall time.
 *   5. Cache per (provider, model) for 24 h so repeated page loads do
 *      not hammer the provider's API. Buyer can force a re-measure by
 *      clicking "Test connection" again — that calls measure() with
 *      forceRefresh=true.
 *
 * Errors are swallowed and returned as {error: string, ...} so the UI
 * can render "✗ unreachable" instead of an exception. We never let a
 * probe failure cascade into a settings-page crash.
 */
class ModelLatencyProbe
{
    public const CACHE_TTL_SECONDS = 60 * 60 * 24;

    private const PROBE_PROMPT = 'Reply with the single word "ok".';

    private const MAX_TOKENS = 8;

    public function __construct(private readonly OpenAiClient $client) {}

    /**
     * @return array{
     *   ok: bool,
     *   ttft_ms: int|null,
     *   total_ms: int|null,
     *   measured_at: string|null,
     *   error: string|null,
     *   provider: string,
     *   model: string,
     * }
     */
    public function measure(string $provider, string $model, bool $forceRefresh = false): array
    {
        $key = $this->cacheKey($provider, $model);

        if (! $forceRefresh) {
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $startNs = hrtime(true);
        $ttftNs = null;
        $tokens = [];
        $error = null;

        try {
            foreach ($this->client->streamChat(
                [['role' => 'user', 'content' => self::PROBE_PROMPT]],
                ['max_tokens' => self::MAX_TOKENS],
            ) as $token) {
                if ($ttftNs === null && trim((string) $token) !== '') {
                    $ttftNs = hrtime(true) - $startNs;
                }
                $tokens[] = $token;
                if (count($tokens) >= self::MAX_TOKENS) {
                    break;
                }
            }
        } catch (Throwable $e) {
            $error = $this->humaniseError($e);
            Log::warning('llm.latency_probe.failed', [
                'provider' => $provider,
                'model' => $model,
                'error' => $e->getMessage(),
            ]);
        }

        $totalNs = hrtime(true) - $startNs;

        $result = [
            'ok' => $error === null && $ttftNs !== null,
            'ttft_ms' => $ttftNs !== null ? (int) round($ttftNs / 1_000_000) : null,
            'total_ms' => (int) round($totalNs / 1_000_000),
            'measured_at' => now()->toIso8601String(),
            'error' => $error,
            'provider' => $provider,
            'model' => $model,
        ];

        Cache::put($key, $result, self::CACHE_TTL_SECONDS);

        return $result;
    }

    /**
     * Read the cached probe without firing a new call. Returns null when
     * no probe has run yet for this (provider, model).
     *
     * @return array{
     *   ok: bool, ttft_ms: int|null, total_ms: int|null,
     *   measured_at: string|null, error: string|null, provider: string, model: string,
     * }|null
     */
    public function cached(string $provider, string $model): ?array
    {
        $cached = Cache::get($this->cacheKey($provider, $model));

        return is_array($cached) ? $cached : null;
    }

    public function forget(string $provider, string $model): void
    {
        Cache::forget($this->cacheKey($provider, $model));
    }

    private function cacheKey(string $provider, string $model): string
    {
        return 'llm.latency.'.$provider.'.'.md5($model);
    }

    /**
     * Turn provider exceptions into a one-line summary the operator can
     * act on. We deliberately do not surface full stack traces because
     * the settings page is non-technical operators.
     */
    private function humaniseError(Throwable $e): string
    {
        $message = trim($e->getMessage());

        if ($message === '') {
            return class_basename($e);
        }

        // Single line, capped — the UI shows this inline next to the
        // model name. Anything longer wraps awkwardly.
        return mb_substr(preg_replace('/\s+/', ' ', $message) ?? $message, 0, 200);
    }
}
