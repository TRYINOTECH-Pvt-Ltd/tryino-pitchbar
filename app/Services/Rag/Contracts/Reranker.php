<?php

namespace App\Services\Rag\Contracts;

interface Reranker
{
    /**
     * Score and reorder candidate texts for a query, returning the top-k in
     * descending relevance. Implementations MUST be best-effort: if the
     * upstream service fails, return the candidates in their original order
     * (truncated to topK) rather than throwing.
     *
     * @param  string  $query  Visitor question.
     * @param  array<int, array<string, mixed>>  $candidates  Each must contain a 'text' key; other keys preserved.
     * @param  int  $topK  Max items to return.
     * @return array<int, array<string, mixed>>
     */
    public function rerank(string $query, array $candidates, int $topK): array;
}
