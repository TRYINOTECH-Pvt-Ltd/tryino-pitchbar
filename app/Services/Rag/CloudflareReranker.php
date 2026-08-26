<?php

namespace App\Services\Rag;

use App\Services\Rag\Contracts\Reranker;
use GuzzleHttp\Client as Guzzle;
use Illuminate\Support\Facades\Log;

/**
 * Cross-encoder reranker backed by Cloudflare Workers AI's bge-reranker-base.
 *
 * Workflow: Retriever fetches 2*topK candidates from Vectorize using cosine
 * similarity (cheap, recall-oriented); we then ask the reranker to score
 * each candidate against the query (expensive, precision-oriented) and keep
 * the top-K. This consistently lifts the genuinely-best chunk to position 1
 * — bge-base alone often picks tangentially-related neighbors first.
 *
 * REST: POST /accounts/{ACCOUNT_ID}/ai/run/@cf/baai/bge-reranker-base
 *
 * Request shape:  {"query": "...", "contexts": [{"text": "..."}], "top_k": N}
 * Response shape: {"result": {"response": [{"id": 0, "score": 0.95}, ...]}}
 */
class CloudflareReranker implements Reranker
{
    public function __construct(
        private readonly Guzzle $http,
        private readonly string $accountId,
        private readonly string $apiToken,
        private readonly string $model = '@cf/baai/bge-reranker-base',
    ) {}

    public static function default(string $accountId, string $apiToken, ?Guzzle $http = null): self
    {
        return new self(
            $http ?? new Guzzle(['timeout' => (float) config('services.rag.rerank_timeout_seconds', 3.0)]),
            $accountId,
            $apiToken,
        );
    }

    public function rerank(string $query, array $candidates, int $topK): array
    {
        if ($candidates === []) {
            return [];
        }
        if (count($candidates) === 1) {
            return $candidates;
        }

        $endpoint = "https://api.cloudflare.com/client/v4/accounts/{$this->accountId}/ai/run/{$this->model}";

        $contexts = [];
        foreach ($candidates as $i => $cand) {
            $contexts[] = ['text' => (string) ($cand['text'] ?? '')];
        }

        try {
            $response = $this->http->post($endpoint, [
                'json' => [
                    'query' => $query,
                    'contexts' => $contexts,
                    'top_k' => min($topK, count($candidates)),
                ],
                'headers' => [
                    'Authorization' => "Bearer {$this->apiToken}",
                    'Content-Type' => 'application/json',
                ],
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Reranker HTTP failed; falling back to original order', ['err' => $e->getMessage()]);

            return array_slice($candidates, 0, $topK);
        }

        if ($response->getStatusCode() >= 400) {
            Log::warning('Reranker HTTP non-2xx; falling back', [
                'status' => $response->getStatusCode(),
                'body' => mb_substr((string) $response->getBody(), 0, 200),
            ]);

            return array_slice($candidates, 0, $topK);
        }

        $body = json_decode((string) $response->getBody(), true);
        $scored = $body['result']['response'] ?? null;
        if (! is_array($scored) || $scored === []) {
            return array_slice($candidates, 0, $topK);
        }

        $reranked = [];
        foreach ($scored as $r) {
            $idx = $r['id'] ?? null;
            if (! is_int($idx) || ! isset($candidates[$idx])) {
                continue;
            }
            $reranked[] = array_merge($candidates[$idx], [
                'rerank_score' => (float) ($r['score'] ?? 0),
            ]);
        }

        if ($reranked === []) {
            return array_slice($candidates, 0, $topK);
        }

        return array_slice($reranked, 0, $topK);
    }
}
