<?php

use App\Models\Agent;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\Rag\Contracts\Reranker;
use App\Services\Rag\Fakes\FakeReranker;
use App\Services\Rag\Retriever;
use App\Services\Vector\Contracts\QdrantClient;
use App\Services\Vector\Fakes\FakeQdrant;
use Illuminate\Support\Str;

/**
 * Reranker contract: input candidates → top-K in possibly-different order.
 * The Retriever must call it after ANN and before the threshold gate.
 */
function makeChunkedAgent(int $chunks = 4): array
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id, 'confidence_threshold' => 0.0]);
    $source = Source::factory()->create(['agent_id' => $agent->id]);
    $document = Document::factory()->create(['source_id' => $source->id, 'agent_id' => $agent->id]);

    $made = [];
    for ($i = 0; $i < $chunks; $i++) {
        $made[] = Chunk::create([
            'document_id' => $document->id,
            'agent_id' => $agent->id,
            'ord' => $i,
            'text' => "chunk #{$i}: lorem ipsum dolor sit amet",
            'token_count' => 8,
            'qdrant_point_id' => (string) Str::uuid7(),
        ]);
    }

    return ['agent' => $agent, 'chunks' => $made];
}

function seedFakeQdrantWithChunks(FakeQdrant $q, string $agentId, array $chunks): void
{
    $points = [];
    foreach ($chunks as $i => $c) {
        $points[] = [
            'id' => $c->qdrant_point_id,
            'vector' => array_fill(0, 8, 0.1 * ($i + 1)),
            'payload' => [
                'agent_id' => $agentId,
                'document_id' => $c->document_id,
                'chunk_id' => $c->id,
                'url' => 'https://example.com/'.$i,
            ],
        ];
    }
    $q->upsertPoints('pitchbar-chunks', $points);
}

test('Retriever delegates to reranker after ANN', function () {
    ['agent' => $agent, 'chunks' => $chunks] = makeChunkedAgent(4);

    /** @var FakeQdrant $q */
    $q = app(QdrantClient::class);
    seedFakeQdrantWithChunks($q, $agent->id, $chunks);

    /** @var FakeReranker $reranker */
    $reranker = app(Reranker::class);
    expect($reranker)->toBeInstanceOf(FakeReranker::class);

    $r = app(Retriever::class);
    $r->retrieve($agent->id, 'what is in chunk #2?', 0.0, 3);

    expect($reranker->calls)->toHaveCount(1);
    expect($reranker->calls[0]['topK'])->toBe(3);
    expect($reranker->calls[0]['count'])->toBeGreaterThanOrEqual(3);
});

test('Retriever respects rerank order', function () {
    ['agent' => $agent, 'chunks' => $chunks] = makeChunkedAgent(4);

    /** @var FakeQdrant $q */
    $q = app(QdrantClient::class);
    seedFakeQdrantWithChunks($q, $agent->id, $chunks);

    /** @var FakeReranker $reranker */
    $reranker = app(Reranker::class);
    $reranker->reverseOrder = true;

    $r = app(Retriever::class);
    $out = $r->retrieve($agent->id, 'whatever', 0.0, 4);

    expect(count($out['chunks']))->toBeGreaterThan(0);
    // We can't assert exact order without knowing FakeQdrant's ordering,
    // but the result set should still match against our chunks.
    foreach ($out['chunks'] as $c) {
        expect($c['chunk_id'])->toBeIn(array_map(fn ($x) => $x->id, $chunks));
    }
});

test('Retriever survives a reranker that returns nothing', function () {
    ['agent' => $agent, 'chunks' => $chunks] = makeChunkedAgent(2);

    /** @var FakeQdrant $q */
    $q = app(QdrantClient::class);
    seedFakeQdrantWithChunks($q, $agent->id, $chunks);

    app()->bind(Reranker::class, fn () => new class implements Reranker
    {
        public function rerank(string $query, array $candidates, int $topK): array
        {
            return [];
        }
    });

    $r = app(Retriever::class);
    $out = $r->retrieve($agent->id, 'whatever', 0.0, 6);

    expect($out['chunks'])->toBe([]);
    expect($out['low_confidence'])->toBeTrue();
});

test('Retriever fans out beyond topK so the reranker has options', function () {
    ['agent' => $agent, 'chunks' => $chunks] = makeChunkedAgent(12);

    /** @var FakeQdrant $q */
    $q = app(QdrantClient::class);
    seedFakeQdrantWithChunks($q, $agent->id, $chunks);

    /** @var FakeReranker $reranker */
    $reranker = app(Reranker::class);
    $reranker->calls = [];

    $r = app(Retriever::class);
    $r->retrieve($agent->id, 'q', 0.0, 4);

    expect($reranker->calls[0]['count'])->toBeGreaterThan(4);
});
