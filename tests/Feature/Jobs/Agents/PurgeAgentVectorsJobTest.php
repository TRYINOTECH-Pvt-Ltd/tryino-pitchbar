<?php

use App\Jobs\Agents\PurgeAgentVectorsJob;
use App\Services\Vector\Contracts\QdrantClient;
use App\Services\Vector\Fakes\FakeQdrant;

beforeEach(function () {
    $this->fake = new FakeQdrant;
    $this->app->instance(QdrantClient::class, $this->fake);
});

test('purges every chunk belonging to the agent across the vector store', function () {
    $collection = (string) config('services.vector_collection', 'pitchbar-chunks');
    $this->fake->ensureCollection($collection, 4);
    $this->fake->upsertPoints($collection, [
        ['id' => 'a1', 'vector' => [0.1, 0.2, 0.3, 0.4], 'payload' => ['agent_id' => 'agent-x', 'document_id' => 'd1']],
        ['id' => 'a2', 'vector' => [0.2, 0.2, 0.3, 0.4], 'payload' => ['agent_id' => 'agent-x', 'document_id' => 'd2']],
        ['id' => 'b1', 'vector' => [0.5, 0.5, 0.3, 0.4], 'payload' => ['agent_id' => 'agent-y', 'document_id' => 'd3']],
    ]);

    PurgeAgentVectorsJob::dispatchSync('agent-x');

    $remaining = collect($this->fake->dump($collection))->pluck('payload.agent_id')->all();
    expect($remaining)->toBe(['agent-y']);
});

test('does not throw when the agent has no vectors', function () {
    $collection = (string) config('services.vector_collection', 'pitchbar-chunks');
    $this->fake->ensureCollection($collection, 4);

    PurgeAgentVectorsJob::dispatchSync('agent-missing');

    expect($this->fake->dump($collection))->toBe([]);
});

test('swallows vector store exceptions instead of retrying forever', function () {
    $boom = new class implements QdrantClient
    {
        public function upsertPoints(string $collection, array $points): void {}

        public function search(string $collection, array $vector, array $filter, int $limit): array
        {
            return [];
        }

        public function deleteByFilter(string $collection, array $filter): void
        {
            throw new RuntimeException('vector store unavailable');
        }

        public function ensureCollection(string $name, int $dim, string $distance = 'Cosine'): void {}

        public function dropCollection(string $name): void {}
    };

    $this->app->instance(QdrantClient::class, $boom);

    PurgeAgentVectorsJob::dispatchSync('agent-x');

    // Reaching this line proves the throw was caught; no further assertions needed.
    expect(true)->toBeTrue();
});
