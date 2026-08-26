<?php

namespace App\Services\Vector\Fakes;

use App\Services\Vector\Contracts\QdrantClient;

class FakeQdrant implements QdrantClient
{
    /** @var array<string, array<int, array{id: string, vector: array<int, float>, payload: array<string, mixed>}>> */
    private array $store = [];

    public function upsertPoints(string $collection, array $points): void
    {
        $this->store[$collection] ??= [];

        foreach ($points as $point) {
            // Replace existing point with the same id (idempotent).
            $this->store[$collection] = array_values(array_filter(
                $this->store[$collection],
                fn (array $p) => $p['id'] !== $point['id'],
            ));
            $this->store[$collection][] = [
                'id' => (string) $point['id'],
                'vector' => $point['vector'],
                'payload' => $point['payload'],
            ];
        }
    }

    public function search(string $collection, array $vector, array $filter, int $limit): array
    {
        $points = $this->store[$collection] ?? [];

        // Filter
        if ($filter !== []) {
            $points = array_filter($points, function (array $p) use ($filter): bool {
                foreach ($filter as $k => $v) {
                    if (($p['payload'][$k] ?? null) !== $v) {
                        return false;
                    }
                }

                return true;
            });
        }

        // Cosine similarity
        $scored = array_map(function (array $p) use ($vector): array {
            return [
                'id' => $p['id'],
                'score' => self::cosine($vector, $p['vector']),
                'payload' => $p['payload'],
            ];
        }, array_values($points));

        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $limit);
    }

    public function deleteByFilter(string $collection, array $filter): void
    {
        if (! isset($this->store[$collection])) {
            return;
        }

        $this->store[$collection] = array_values(array_filter(
            $this->store[$collection],
            function (array $p) use ($filter): bool {
                foreach ($filter as $k => $v) {
                    if (($p['payload'][$k] ?? null) === $v) {
                        return false; // matches → delete
                    }
                }

                return true;
            },
        ));
    }

    public function ensureCollection(string $name, int $dim, string $distance = 'Cosine'): void
    {
        $this->store[$name] ??= [];
    }

    public function dropCollection(string $name): void
    {
        unset($this->store[$name]);
    }

    public function dump(string $collection): array
    {
        return $this->store[$collection] ?? [];
    }

    private static function cosine(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $magA = 0.0;
        $magB = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $dot += $a[$i] * $b[$i];
            $magA += $a[$i] ** 2;
            $magB += $b[$i] ** 2;
        }

        return $magA == 0.0 || $magB == 0.0 ? 0.0 : $dot / (sqrt($magA) * sqrt($magB));
    }
}
