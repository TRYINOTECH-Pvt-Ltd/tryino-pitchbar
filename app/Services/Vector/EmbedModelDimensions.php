<?php

namespace App\Services\Vector;

/**
 * Mapping of known embedding model slugs to their output vector
 * dimensions. Used to:
 *
 *   1. Auto-derive `services.vector_dim` when the operator did not set
 *      `VECTOR_DIM` explicitly but did set a non-default
 *      `CLOUDFLARE_EMBED_MODEL` / `OPENAI_EMBED_MODEL`. The runtime
 *      Cloudflare error
 *      `expected 768 dimensions, and got 1024 dimensions` came from
 *      exactly this gap — operator pointed at bge-m3 (1024 dim) but
 *      the index was provisioned at 768 because nobody changed
 *      VECTOR_DIM.
 *
 *   2. Sanity-check the Vectorize index dimension against the
 *      configured embed model before the first upsert, so the operator
 *      sees an actionable error instead of an opaque 40012 from
 *      Cloudflare on every single CrawlPageJob attempt.
 *
 * Add new models here as Cloudflare / OpenAI ship them. Unknown
 * models fall back to the explicit `VECTOR_DIM` env value (or 768).
 */
final class EmbedModelDimensions
{
    /**
     * @var array<string, int>
     */
    private const MAP = [
        // Cloudflare Workers AI
        '@cf/baai/bge-small-en-v1.5' => 384,
        '@cf/baai/bge-base-en-v1.5' => 768,
        '@cf/baai/bge-large-en-v1.5' => 1024,
        '@cf/baai/bge-m3' => 1024,

        // OpenAI
        'text-embedding-3-small' => 1536,
        'text-embedding-3-large' => 3072,
        'text-embedding-ada-002' => 1536,
    ];

    public static function forModel(?string $model): ?int
    {
        if (! is_string($model) || $model === '') {
            return null;
        }

        return self::MAP[$model] ?? null;
    }

    /**
     * Resolve the dimension we expect from the currently-configured
     * primary embedding model, with these priorities:
     *
     *   1. Explicit `VECTOR_DIM` env (operator override)
     *   2. Known model→dim map for the configured CF / OpenAI slug
     *   3. Hard fallback to 768 (CF bge-base default)
     */
    public static function resolveExpectedDim(): int
    {
        $explicit = env('VECTOR_DIM');
        if (is_numeric($explicit) && (int) $explicit > 0) {
            return (int) $explicit;
        }

        $provider = (string) (config('services.llm_provider')
            ?: env('LLM_PROVIDER', 'cloudflare'));

        $modelKey = match ($provider) {
            'openai' => (string) config('services.openai.embed_model', env('OPENAI_EMBED_MODEL', '')),
            default => (string) config('services.cloudflare.embed_model', env('CLOUDFLARE_EMBED_MODEL', '')),
        };

        return self::forModel($modelKey)
            ?? (int) config('services.vector_dim', 768);
    }
}
