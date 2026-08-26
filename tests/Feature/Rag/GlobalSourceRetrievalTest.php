<?php

use App\Models\Agent;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Rag\Retriever;
use App\Services\Vector\Contracts\QdrantClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * "Answer on every page" (is_global) coverage on the plain (unsegmented)
 * retrieval path. A source flagged global gets the current-page-equivalent
 * lift from any page — but only when the reranker judged it relevant to
 * the query, and capped so a big global source can't flood the answer.
 */
function globalAgent(): Agent
{
    Cache::flush();
    $workspace = Workspace::factory()->create();

    return Agent::factory()->create(['workspace_id' => $workspace->id, 'confidence_threshold' => 0.5]);
}

/** Index one chunk; $isGlobal marks the owning source "answer on every page". */
function globalChunk(Agent $agent, string $text, bool $isGlobal = false, string $url = 'https://x.test/page'): Chunk
{
    $source = Source::factory()->create(['agent_id' => $agent->id, 'is_global' => $isGlobal]);
    $document = Document::factory()->create([
        'source_id' => $source->id,
        'agent_id' => $agent->id,
        'url' => $url,
    ]);
    $chunk = Chunk::factory()->create([
        'document_id' => $document->id,
        'agent_id' => $agent->id,
        'ord' => 0,
        'text' => $text,
        'qdrant_point_id' => (string) Str::uuid7(),
    ]);

    app(QdrantClient::class)->upsertPoints((string) config('services.vector_collection', 'pitchbar-chunks'), [[
        'id' => $chunk->qdrant_point_id,
        'vector' => app(OpenAiClient::class)->embed([$text])[0],
        'payload' => [
            'workspace_id' => $agent->workspace_id,
            'agent_id' => $agent->id,
            'document_id' => $document->id,
            'chunk_id' => $chunk->id,
            'url' => $url,
        ],
    ]]);

    return $chunk;
}

test('a global source rescues a fact that would otherwise fall below the threshold', function () {
    $agent = globalAgent();
    // The address is a non-verbatim match (FakeOpenAi cosine ≈ 0 → rerank
    // position 1 → 0.9) so a 0.95 threshold normally drops it — the
    // homepage "I can't confirm" case.
    globalChunk($agent, 'storage locker sizes overview');
    $addressChunk = globalChunk($agent, 'Industrieweg 6 7944 HS Meppel', isGlobal: true);

    $result = app(Retriever::class)->retrieve($agent->id, 'storage locker sizes overview', 0.95, 6);

    $address = collect($result['chunks'])->firstWhere('chunk_id', $addressChunk->id);
    expect($address)->not->toBeNull()
        ->and($address['boosted_for_global'] ?? false)->toBeTrue()
        // Legacy payload shape preserved — no segment bookkeeping leaks in.
        ->and($result)->not->toHaveKey('segment_id');
});

test('without the global flag the same fact stays below the threshold', function () {
    $agent = globalAgent();
    globalChunk($agent, 'storage locker sizes overview');
    $addressChunk = globalChunk($agent, 'Industrieweg 6 7944 HS Meppel', isGlobal: false);

    $result = app(Retriever::class)->retrieve($agent->id, 'storage locker sizes overview', 0.95, 6);

    expect(collect($result['chunks'])->pluck('chunk_id'))->not->toContain($addressChunk->id);
});

test('at most GLOBAL_MAX_FORCED global chunks are force-passed so a big source cannot flood', function () {
    $agent = globalAgent();
    globalChunk($agent, 'main product catalogue landing');
    globalChunk($agent, 'global fact alpha industrieweg', isGlobal: true);
    globalChunk($agent, 'global fact bravo openingstijden', isGlobal: true);
    globalChunk($agent, 'global fact charlie telefoon', isGlobal: true);

    $result = app(Retriever::class)->retrieve($agent->id, 'main product catalogue landing', 0.95, 6);

    $boosted = collect($result['chunks'])->filter(fn ($c) => ($c['boosted_for_global'] ?? false) === true);
    expect($boosted)->toHaveCount(2);
});

test('a global chunk the reranker ranks LOW is still surfaced (cross-lingual regression)', function () {
    // Live regression 2026-07-05: an earlier top-K reranker gate blocked
    // the exact case this feature exists for. The reranker mis-ranks a
    // Dutch postal string for the English word "address", pushing it out
    // of the top-K — so gating on reranker position dropped it. Relevance
    // is filtered by ANN candidacy + the cap, NOT the reranker's ordering.
    $agent = globalAgent();
    // Strong matches take the top rerank positions; the global address
    // chunk lands low (rerank 0.7 < 0.95 threshold).
    globalChunk($agent, 'storage locker sizes overview one');
    globalChunk($agent, 'storage locker sizes overview two');
    globalChunk($agent, 'storage locker sizes overview three');
    $addressChunk = globalChunk($agent, 'Industrieweg 6 7944 HS Meppel', isGlobal: true);

    $result = app(Retriever::class)->retrieve($agent->id, 'storage locker sizes overview one', 0.95, 6);

    $address = collect($result['chunks'])->firstWhere('chunk_id', $addressChunk->id);
    expect($address)->not->toBeNull()
        ->and($address['boosted_for_global'] ?? false)->toBeTrue();
});
