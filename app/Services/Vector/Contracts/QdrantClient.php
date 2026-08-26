<?php

namespace App\Services\Vector\Contracts;

interface QdrantClient
{
    /** @param array<int, array{id: string, vector: array<int, float>, payload: array<string, mixed>}> $points */
    public function upsertPoints(string $collection, array $points): void;

    /**
     * @param  array<int, float>  $vector
     * @param  array<string, mixed>  $filter  Match-all filter; keys are payload fields.
     * @return array<int, array{id: string, score: float, payload: array<string, mixed>}>
     */
    public function search(string $collection, array $vector, array $filter, int $limit): array;

    /** @param array<string, mixed> $filter */
    public function deleteByFilter(string $collection, array $filter): void;

    public function ensureCollection(string $name, int $dim, string $distance = 'Cosine'): void;

    /**
     * Hard-delete an index. Used by the rebuild-index recovery flow
     * when the operator changed embedding models and needs to re-
     * provision the index at the new dimension. Idempotent: missing
     * indexes are not an error.
     */
    public function dropCollection(string $name): void;
}
