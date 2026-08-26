<?php

namespace App\Services\Vector;

use App\Services\Vector\Contracts\QdrantClient;
use GuzzleHttp\Client as Guzzle;

class QdrantHttpClient implements QdrantClient
{
    private Guzzle $http;

    public function __construct(
        private readonly string $baseUrl,
        private readonly ?string $apiKey = null,
    ) {
        $this->http = new Guzzle([
            'base_uri' => rtrim($this->baseUrl, '/').'/',
            'timeout' => 10,
            'headers' => $this->apiKey !== null ? ['api-key' => $this->apiKey] : [],
        ]);
    }

    public function upsertPoints(string $collection, array $points): void
    {
        $this->http->put("collections/{$collection}/points?wait=true", [
            'json' => ['points' => array_map(fn ($p) => [
                'id' => $p['id'],
                'vector' => $p['vector'],
                'payload' => $p['payload'],
            ], $points)],
        ]);
    }

    public function search(string $collection, array $vector, array $filter, int $limit): array
    {
        $response = $this->http->post("collections/{$collection}/points/search", [
            'json' => [
                'vector' => $vector,
                'limit' => $limit,
                'filter' => $this->buildFilter($filter),
                'with_payload' => true,
            ],
        ]);

        $body = json_decode((string) $response->getBody(), true) ?? [];

        return array_map(fn (array $r): array => [
            'id' => (string) $r['id'],
            'score' => (float) $r['score'],
            'payload' => $r['payload'] ?? [],
        ], $body['result'] ?? []);
    }

    public function deleteByFilter(string $collection, array $filter): void
    {
        $this->http->post("collections/{$collection}/points/delete?wait=true", [
            'json' => ['filter' => $this->buildFilter($filter)],
        ]);
    }

    public function ensureCollection(string $name, int $dim, string $distance = 'Cosine'): void
    {
        try {
            $this->http->get("collections/{$name}");

            return;
        } catch (\Throwable) {
            // create below
        }

        $this->http->put("collections/{$name}", [
            'json' => [
                'vectors' => ['size' => $dim, 'distance' => $distance],
            ],
        ]);
    }

    public function dropCollection(string $name): void
    {
        try {
            $this->http->delete("collections/{$name}");
        } catch (\Throwable $e) {
            if (! str_contains((string) $e->getMessage(), '404')) {
                throw $e;
            }
        }
    }

    private function buildFilter(array $filter): array
    {
        if ($filter === []) {
            return [];
        }

        return [
            'must' => array_map(
                fn (string $k, mixed $v): array => ['key' => $k, 'match' => ['value' => $v]],
                array_keys($filter),
                array_values($filter),
            ),
        ];
    }
}
