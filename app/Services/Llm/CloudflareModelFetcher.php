<?php

namespace App\Services\Llm;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pulls the live list of Cloudflare Workers AI text-generation models
 * from `accounts/{id}/ai/models/search?task=Text Generation`. Merged
 * with our hand-curated ModelCatalog so the dropdown stays current
 * even when Cloudflare ships a new model we haven't catalogued yet.
 *
 * Live IDs that match our static entries inherit our metadata
 * (tier / cost / notes). Live IDs unknown to us get default metadata:
 * tier=medium, cost=$$, recommended=false, notes="Discovered from
 * Cloudflare's API.". Admin can still pick them; the latency probe
 * confirms whether they actually work on this install.
 *
 * Caches the live list in Redis for 24h. Buyer can force a refresh
 * by clicking "Refresh from Cloudflare" — that calls fetch(force=true).
 */
class CloudflareModelFetcher
{
    public const CACHE_KEY = 'llm.cloudflare.live_models';

    public const CACHE_TTL_SECONDS = 60 * 60 * 24;

    private const ENDPOINT = 'https://api.cloudflare.com/client/v4/accounts/%s/ai/models/search';

    private const REQUEST_TIMEOUT_SECONDS = 10;

    /**
     * @return array{
     *   ok: bool,
     *   ids: array<int, string>,
     *   error: string|null,
     *   fetched_at: string|null,
     * }
     */
    public function fetch(bool $force = false): array
    {
        if (! $force) {
            $cached = Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        $accountId = (string) config('services.cloudflare.account_id', '');
        $apiToken = (string) config('services.cloudflare.api_token', '');

        if ($accountId === '' || $apiToken === '') {
            $result = [
                'ok' => false,
                'ids' => [],
                'error' => 'Cloudflare account ID or API token missing.',
                'fetched_at' => now()->toIso8601String(),
            ];
            Cache::put(self::CACHE_KEY, $result, self::CACHE_TTL_SECONDS);

            return $result;
        }

        try {
            $response = Http::withToken($apiToken)
                ->acceptJson()
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->get(sprintf(self::ENDPOINT, urlencode($accountId)), [
                    'task' => 'Text Generation',
                    'per_page' => 100,
                ]);

            if (! $response->successful()) {
                $error = 'Cloudflare returned HTTP '.$response->status();
                Log::warning('cloudflare.models.fetch.failed', [
                    'status' => $response->status(),
                    'body' => mb_substr((string) $response->body(), 0, 500),
                ]);
                $result = [
                    'ok' => false,
                    'ids' => [],
                    'error' => $error,
                    'fetched_at' => now()->toIso8601String(),
                ];
                Cache::put(self::CACHE_KEY, $result, self::CACHE_TTL_SECONDS);

                return $result;
            }

            $payload = $response->json();
            $models = is_array($payload['result'] ?? null) ? $payload['result'] : [];

            $ids = [];
            foreach ($models as $entry) {
                $name = is_array($entry) ? ($entry['name'] ?? null) : null;
                if (is_string($name) && $name !== '') {
                    $ids[] = $name;
                }
            }
            $ids = array_values(array_unique($ids));

            $result = [
                'ok' => true,
                'ids' => $ids,
                'error' => null,
                'fetched_at' => now()->toIso8601String(),
            ];
            Cache::put(self::CACHE_KEY, $result, self::CACHE_TTL_SECONDS);

            return $result;
        } catch (Throwable $e) {
            Log::warning('cloudflare.models.fetch.exception', [
                'error' => $e->getMessage(),
            ]);
            $result = [
                'ok' => false,
                'ids' => [],
                'error' => $e->getMessage(),
                'fetched_at' => now()->toIso8601String(),
            ];
            Cache::put(self::CACHE_KEY, $result, self::CACHE_TTL_SECONDS);

            return $result;
        }
    }

    /**
     * @return array{
     *   ok: bool, ids: array<int, string>, error: string|null, fetched_at: string|null
     * }|null
     */
    public function cached(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        return is_array($cached) ? $cached : null;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
