<?php

namespace App\Services\Vector;

use App\Services\Vector\Contracts\QdrantClient;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Exception\RequestException;

/**
 * Cloudflare Vectorize — drops in for QdrantClient.
 *
 * REST API base:
 *   https://api.cloudflare.com/client/v4/accounts/{ACCOUNT_ID}/vectorize/v2/indexes/{INDEX}
 *
 * Authoritative differences from Qdrant:
 *   - "collection" → "index"
 *   - filter syntax: {"field": {"$eq": "value"}} (Mongo-ish)
 *   - delete-by-filter is NOT native; we query, then delete_by_ids
 *   - upsert uses NDJSON of {id, values, metadata} per line
 */
class VectorizeClient implements QdrantClient
{
    public function __construct(
        private readonly Guzzle $http,
        private readonly string $accountId,
        private readonly string $apiToken,
    ) {}

    public static function default(string $accountId, string $apiToken, ?Guzzle $http = null): self
    {
        // 30s overall, 10s connect. The previous 15s ceiling was too tight
        // for cold Cloudflare reads on first request of a worker process —
        // it caused ensureCollection() to misread an existing index as
        // "doesn't exist", then POST /indexes and get duplicate_name back,
        // failing the whole IndexDocumentJob.
        return new self(
            $http ?? new Guzzle(['timeout' => 30, 'connect_timeout' => 10]),
            $accountId,
            $apiToken,
        );
    }

    public function upsertPoints(string $collection, array $points): void
    {
        if ($points === []) {
            return;
        }

        // Validate every vector's length BEFORE we waste a round trip
        // to Cloudflare. Buyer-reported 2026-05-15 (whispbar): a model
        // change (bge-base 768 → bge-m3 1024) without matching index
        // re-creation produced an opaque
        // `invalid vector for id="…", expected 768 dimensions, and got
        // 1024 dimensions` 400 on every job. We now fail with an
        // actionable message that names the configured model and the
        // recovery command.
        $expected = $this->resolveVectorDim();
        foreach ($points as $p) {
            $actual = is_array($p['vector'] ?? null) ? count($p['vector']) : 0;
            if ($actual !== $expected) {
                throw new \RuntimeException(sprintf(
                    'Vectorize upsert blocked: vector length %d does not match index dimension %d. '
                    .'The configured embedding model is producing a different size than the Vectorize index was provisioned for. '
                    .'Run `php artisan vector:rebuild-index` to drop + recreate the index at the model\'s native dim, then re-index sources.',
                    $actual,
                    $expected,
                ));
            }
        }

        // Vectorize ingests NDJSON: one JSON object per line.
        $lines = [];
        foreach ($points as $p) {
            $lines[] = json_encode([
                'id' => (string) $p['id'],
                'values' => $p['vector'],
                'metadata' => $p['payload'] ?? [],
            ], JSON_THROW_ON_ERROR);
        }

        $this->request(
            method: 'POST',
            path: "indexes/{$collection}/upsert",
            options: [
                'headers' => ['Content-Type' => 'application/x-ndjson'],
                'body' => implode("\n", $lines),
            ],
        );
    }

    public function search(string $collection, array $vector, array $filter, int $limit): array
    {
        // Pre-flight dim guard. Same mismatch class as the upsert path:
        // operator changed `CLOUDFLARE_EMBED_MODEL` but the existing
        // Vectorize index was provisioned at the older dim. Cloudflare
        // returns code 40006 "invalid query vector, expected N
        // dimensions" — opaque and prone to retry loops. Fail locally
        // with the recovery hint so playground / hot-path callers see
        // an actionable message.
        $expected = $this->resolveVectorDim();
        if (count($vector) !== $expected) {
            throw new \RuntimeException(sprintf(
                'Vectorize query blocked: vector length %d does not match index dimension %d. '
                .'The configured embedding model is producing a different size than the Vectorize index was provisioned for. '
                .'Run `php artisan vector:rebuild-index` to drop + recreate the index at the model\'s native dim, then re-index sources.',
                count($vector),
                $expected,
            ));
        }

        $body = [
            'vector' => $vector,
            'topK' => $limit,
            'returnMetadata' => 'all',
        ];

        if ($filter !== []) {
            $body['filter'] = $this->buildFilter($filter);
        }

        $response = $this->request('POST', "indexes/{$collection}/query", ['json' => $body]);
        $matches = $response['result']['matches'] ?? [];

        return array_map(fn (array $m): array => [
            'id' => (string) ($m['id'] ?? ''),
            'score' => (float) ($m['score'] ?? 0.0),
            'payload' => (array) ($m['metadata'] ?? []),
        ], $matches);
    }

    public function deleteByFilter(string $collection, array $filter): void
    {
        // Vectorize lacks a native delete-by-filter. We page through matches
        // and delete by id. Cloudflare caps topK at 50 with returnMetadata=all,
        // so we use the lighter "ids only" path (returnValues=false,
        // returnMetadata=indexed) which allows topK=100.
        $dim = $this->resolveVectorDim();
        $deleted = 0;

        for ($page = 0; $page < 20; $page++) { // hard cap ~2,000 deletions per call
            $body = [
                'vector' => array_fill(0, $dim, 0.0),
                'topK' => 100,
                'returnValues' => false,
                'returnMetadata' => 'indexed',
                'filter' => $this->buildFilter($filter),
            ];

            $response = $this->request('POST', "indexes/{$collection}/query", ['json' => $body]);
            $matches = $response['result']['matches'] ?? [];
            if ($matches === []) {
                return;
            }

            $ids = array_values(array_filter(array_map(fn ($m) => (string) ($m['id'] ?? ''), $matches)));
            if ($ids === []) {
                return;
            }

            $this->request('POST', "indexes/{$collection}/delete_by_ids", [
                'json' => ['ids' => $ids],
            ]);
            $deleted += count($ids);

            if (count($ids) < 100) {
                return; // last page
            }
        }
    }

    public function ensureCollection(string $name, int $dim, string $distance = 'Cosine'): void
    {
        $exists = false;
        try {
            $info = $this->request('GET', "indexes/{$name}");
            $exists = true;

            // Validate the existing index dim matches what we want to
            // upsert. Buyer-reported (whispbar, 2026-05-15): operator
            // changed CLOUDFLARE_EMBED_MODEL from bge-base (768) to
            // bge-m3 (1024) but the index was already provisioned at
            // 768 — every IndexDocumentJob then died with an opaque
            // 40012 "invalid vector". Surface the mismatch here, before
            // we burn an embedding round-trip on every chunk.
            $existingDim = (int) ($info['result']['config']['dimensions']
                ?? $info['result']['dimensions']
                ?? 0);
            if ($existingDim > 0 && $existingDim !== $dim) {
                throw new \RuntimeException(sprintf(
                    'Vectorize index "%s" exists at %d dimensions but the configured embedding model requires %d. '
                    .'Either point CLOUDFLARE_EMBED_MODEL back at a %d-dim model OR run '
                    .'`php artisan vector:rebuild-index` to drop + recreate the index at %d dimensions (re-indexes every Source).',
                    $name,
                    $existingDim,
                    $dim,
                    $existingDim,
                    $dim,
                ));
            }
        } catch (\RuntimeException $e) {
            // Re-throw our own actionable mismatch error so callers see it.
            if (str_contains($e->getMessage(), 'Vectorize index')) {
                throw $e;
            }
            // GET hit a 404 OR a transient (timeout / 5xx / network blip).
            // We can't distinguish from here — fall through to POST and
            // swallow the duplicate_name response below if it turns out
            // the index did exist all along.
        } catch (\Throwable) {
            // Same as above for non-RuntimeException paths.
        }

        if (! $exists) {
            try {
                $this->request('POST', 'indexes', [
                    'json' => [
                        'name' => $name,
                        'config' => [
                            'dimensions' => $dim,
                            'metric' => strtolower($distance),
                        ],
                    ],
                ]);
            } catch (\Throwable $e) {
                // Cloudflare returns `vectorize.index.duplicate_name`
                // (code 3002) when the index already exists. That's the
                // race-with-create OR transient-GET-but-index-exists case
                // — treat as success. Anything else (auth, quota, server
                // error) still escapes so the job fails loudly.
                if (! str_contains($e->getMessage(), 'duplicate_name')) {
                    throw $e;
                }
            }
        }

        // Create metadata indexes for the fields we filter by. Cloudflare
        // Vectorize will not return matches when filtering by a property
        // that has no metadata index.
        foreach (['agent_id', 'document_id', 'chunk_id'] as $property) {
            try {
                $this->request('POST', "indexes/{$name}/metadata_index/create", [
                    'json' => ['propertyName' => $property, 'indexType' => 'string'],
                ]);
            } catch (\Throwable) {
                // Already exists; that's fine.
            }
        }
    }

    /**
     * Drop a Vectorize index. Used by the operator-facing
     * `vector:rebuild-index` artisan command when the index dimension
     * needs to change (e.g. after switching CLOUDFLARE_EMBED_MODEL
     * from bge-base 768 → bge-m3 1024). Idempotent: a 404 is treated
     * as success.
     */
    public function dropCollection(string $name): void
    {
        try {
            $this->request('DELETE', "indexes/{$name}");
        } catch (\Throwable $e) {
            if (! str_contains($e->getMessage(), '404')
                && ! str_contains($e->getMessage(), 'not_found')
                && ! str_contains($e->getMessage(), 'does not exist')) {
                throw $e;
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options = []): array
    {
        $url = "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/vectorize/v2/{$path}";

        $options['headers'] = array_merge([
            'Authorization' => "Bearer {$this->apiToken}",
            'Accept' => 'application/json',
        ], $options['headers'] ?? []);

        try {
            $response = $this->http->request($method, $url, $options);
        } catch (RequestException $e) {
            $body = $e->hasResponse() ? (string) $e->getResponse()->getBody() : $e->getMessage();
            throw new \RuntimeException("Vectorize {$method} {$path} failed: {$body}", previous: $e);
        }

        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException("Vectorize returned non-JSON for {$method} {$path}");
        }

        return $decoded;
    }

    /**
     * Resolve the vector dimension from config when the Laravel app is
     * available; fall back to the Cloudflare bge-base default (768) for
     * pure-PHP unit-test contexts. Reads through `EmbedModelDimensions`
     * so a non-default embedding model auto-picks the right dim even
     * when VECTOR_DIM env wasn't bumped manually.
     */
    private function resolveVectorDim(): int
    {
        try {
            if (function_exists('app') && app()->bound('config')) {
                return EmbedModelDimensions::resolveExpectedDim();
            }
        } catch (\Throwable) {
            // app() not booted (unit test) — fall through.
        }

        return 768;
    }

    /**
     * Convert {field => value} into Vectorize's {field: {$eq: value}}.
     */
    private function buildFilter(array $filter): array
    {
        $out = [];
        foreach ($filter as $k => $v) {
            $out[$k] = ['$eq' => $v];
        }

        return $out;
    }
}
