<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Per-model price catalog → cost-in-USD-micros for any (provider, model,
 * tokens_in, tokens_out) tuple. Numbers below are list public-API
 * pricing snapshots; the catalog is operator-overridable via
 * `config('services.llm.pricing')`.
 *
 * One micro = $0.000001. So Llama 3.3 70B Workers AI input @ $0.59 /
 * million tokens stores as 590 micros / 1000 tokens = 0.59 micros per
 * token, which we represent as `in_per_million = 590000`. Multiplying
 * by tokens never overflows a u64 column.
 */
final class TokenPricing
{
    /**
     * Track (provider, model) tuples that resolved only to a wildcard
     * fallback during this PHP-FPM worker's lifetime. Surfaced via
     * `unmappedSeen()` so the admin Usage page can render a one-time
     * warning. Logging once per worker keeps Laravel.log from filling
     * with the same line on every persist.
     *
     * @var array<string, true>
     */
    private static array $loggedFallback = [];

    /**
     * Returns cost in USD micros (dollars × 1,000,000).
     */
    public static function costMicros(
        string $provider,
        string $model,
        int $tokensIn,
        int $tokensOut,
    ): int {
        $rates = self::ratesFor($provider, $model, $matchedExact);
        if ($rates === null) {
            self::logFallback($provider, $model, 'no_provider');

            return 0;
        }

        if (! $matchedExact) {
            self::logFallback($provider, $model, 'wildcard');
        }

        // rates[*] are micros per 1M tokens. cost = (tokens × rate) / 1_000_000.
        $in = intdiv($tokensIn * $rates['in_per_million'], 1_000_000);
        $out = intdiv($tokensOut * $rates['out_per_million'], 1_000_000);

        return $in + $out;
    }

    private static function logFallback(string $provider, string $model, string $reason): void
    {
        $key = $provider.'|'.$model.'|'.$reason;
        if (isset(self::$loggedFallback[$key])) {
            return;
        }
        self::$loggedFallback[$key] = true;

        try {
            Log::info('token_pricing.catalog_gap', [
                'provider' => $provider,
                'model' => $model,
                'reason' => $reason,
                'fix' => 'Add the model to TokenPricing::defaults() or set services.llm.pricing.'.$provider.'.'.$model.' in config.',
            ]);
        } catch (\Throwable) {
            // Log facade may not be bootstrapped in some contexts.
        }
    }

    /**
     * @return ?array{in_per_million: int, out_per_million: int}
     */
    private static function ratesFor(string $provider, string $model, ?bool &$matchedExact = null): ?array
    {
        $matchedExact = false;
        $configured = config("services.llm.pricing.{$provider}.{$model}");
        if (is_array($configured)
            && isset($configured['in_per_million'], $configured['out_per_million'])) {
            $matchedExact = true;

            return [
                'in_per_million' => (int) $configured['in_per_million'],
                'out_per_million' => (int) $configured['out_per_million'],
            ];
        }

        $defaults = self::defaults();
        if (isset($defaults[$provider][$model])) {
            $matchedExact = true;

            return $defaults[$provider][$model];
        }

        return $defaults[$provider]['*'] ?? null;
    }

    /**
     * @return array<string, array<string, array{in_per_million: int, out_per_million: int}>>
     */
    private static function defaults(): array
    {
        return [
            'cloudflare' => [
                '@cf/meta/llama-3.3-70b-instruct-fp8-fast' => [
                    'in_per_million' => 590_000,    // $0.59 / 1M
                    'out_per_million' => 790_000,   // $0.79 / 1M
                ],
                '@cf/meta/llama-3.1-70b-instruct' => [
                    'in_per_million' => 590_000,
                    'out_per_million' => 790_000,
                ],
                '@cf/meta/llama-3.1-8b-instruct' => [
                    'in_per_million' => 280_000,    // $0.28 / 1M
                    'out_per_million' => 280_000,
                ],
                '@cf/meta/llama-3-8b-instruct' => [
                    'in_per_million' => 280_000,
                    'out_per_million' => 280_000,
                ],
                '@cf/mistral/mistral-7b-instruct-v0.2' => [
                    'in_per_million' => 110_000,
                    'out_per_million' => 190_000,
                ],
                '@cf/baai/bge-base-en-v1.5' => [
                    'in_per_million' => 12_000,     // $0.012 / 1M
                    'out_per_million' => 0,
                ],
                '@cf/baai/bge-large-en-v1.5' => [
                    'in_per_million' => 12_000,
                    'out_per_million' => 0,
                ],
                '@cf/baai/bge-reranker-base' => [
                    'in_per_million' => 12_000,
                    'out_per_million' => 0,
                ],
                '*' => [
                    'in_per_million' => 500_000,
                    'out_per_million' => 750_000,
                ],
            ],
            'openai' => [
                'gpt-4o-mini' => [
                    'in_per_million' => 150_000,    // $0.15 / 1M
                    'out_per_million' => 600_000,   // $0.60 / 1M
                ],
                'gpt-4o' => [
                    'in_per_million' => 2_500_000,   // $2.50 / 1M
                    'out_per_million' => 10_000_000, // $10.00 / 1M
                ],
                'gpt-4.1-mini' => [
                    'in_per_million' => 400_000,    // $0.40 / 1M
                    'out_per_million' => 1_600_000, // $1.60 / 1M
                ],
                'gpt-4.1' => [
                    'in_per_million' => 2_000_000,
                    'out_per_million' => 8_000_000,
                ],
                'text-embedding-3-small' => [
                    'in_per_million' => 20_000,     // $0.02 / 1M
                    'out_per_million' => 0,
                ],
                'text-embedding-3-large' => [
                    'in_per_million' => 130_000,    // $0.13 / 1M
                    'out_per_million' => 0,
                ],
                '*' => [
                    'in_per_million' => 5_000_000,
                    'out_per_million' => 15_000_000,
                ],
            ],
            'openrouter' => [
                'meta-llama/llama-3.3-70b-instruct:free' => [
                    'in_per_million' => 0,
                    'out_per_million' => 0,
                ],
                'meta-llama/llama-3.1-70b-instruct' => [
                    'in_per_million' => 590_000,
                    'out_per_million' => 790_000,
                ],
                'meta-llama/llama-3.1-8b-instruct' => [
                    'in_per_million' => 60_000,
                    'out_per_million' => 60_000,
                ],
                'mistralai/mistral-small' => [
                    'in_per_million' => 200_000,
                    'out_per_million' => 600_000,
                ],
                '*' => [
                    'in_per_million' => 500_000,
                    'out_per_million' => 750_000,
                ],
            ],
            'fake' => [
                '*' => ['in_per_million' => 0, 'out_per_million' => 0],
            ],
        ];
    }
}
