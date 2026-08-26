<?php

namespace App\Services\Rag\Fakes;

use App\Services\Rag\Contracts\Reranker;

/**
 * Test double for the reranker. By default it's a passthrough (preserves
 * input order, truncates to topK). Set `$reverseOrder = true` in tests to
 * verify the reranker actually changes downstream behaviour.
 */
class FakeReranker implements Reranker
{
    public bool $reverseOrder = false;

    /** @var array<int, array{query: string, count: int, topK: int}> */
    public array $calls = [];

    public function rerank(string $query, array $candidates, int $topK): array
    {
        $this->calls[] = [
            'query' => $query,
            'count' => count($candidates),
            'topK' => $topK,
        ];

        if ($this->reverseOrder) {
            $candidates = array_reverse($candidates);
        }

        // Attach a deterministic descending score so threshold-based filtering
        // is testable without depending on FakeQdrant cosine which can be
        // negative for synthetic random vectors.
        $candidates = array_values(array_map(function (array $c, int $i): array {
            $c['rerank_score'] = max(0.0, 1.0 - $i * 0.1);

            return $c;
        }, $candidates, array_keys($candidates)));

        return array_slice($candidates, 0, $topK);
    }
}
