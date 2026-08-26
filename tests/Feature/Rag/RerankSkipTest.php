<?php

use App\Models\Agent;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Rag\Contracts\Reranker;
use App\Services\Rag\Fakes\FakeReranker;
use App\Services\Rag\Retriever;
use App\Services\Vector\Contracts\QdrantClient;
use App\Services\Vector\Fakes\FakeQdrant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    Cache::flush();
});

function skipTestChunk(string $agentId, string $sourceId, string $text): Chunk
{
    $doc = Document::create([
        'id' => (string) Str::uuid7(),
        'source_id' => $sourceId,
        'agent_id' => $agentId,
        'url' => 'https://example.com/'.Str::slug(substr($text, 0, 20)),
        'title' => substr($text, 0, 20),
        'content_hash' => hash('sha256', $text),
        'fetched_at' => now(),
    ]);
    $chunk = Chunk::create([
        'id' => (string) Str::uuid7(),
        'document_id' => $doc->id,
        'agent_id' => $agentId,
        'ord' => 0,
        'text' => $text,
        'token_count' => (int) ceil(mb_strlen($text) / 4),
    ]);

    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    /** @var FakeQdrant $vec */
    $vec = app(QdrantClient::class);

    $pointId = (string) Str::uuid7();
    $chunk->forceFill(['qdrant_point_id' => $pointId])->save();
    $vec->upsertPoints('pitchbar-chunks', [[
        'id' => $pointId,
        'vector' => $llm->embed([$text])[0],
        'payload' => [
            'agent_id' => $agentId,
            'document_id' => $doc->id,
            'chunk_id' => $chunk->id,
            'url' => 'https://example.com/'.Str::slug(substr($text, 0, 20)),
        ],
    ]]);

    return $chunk;
}

function skipTestAgent(): Agent
{
    $ws = Workspace::factory()->create();

    return Agent::factory()->create(['workspace_id' => $ws->id, 'confidence_threshold' => 0.0]);
}

it('skips the reranker when ANN already returned topK decisive candidates', function () {
    config(['services.rag.rerank_skip' => true, 'services.rag.rerank_skip_score' => 0.95]);

    $agent = skipTestAgent();
    $source = Source::factory()->create(['agent_id' => $agent->id]);

    // FakeOpenAi embeddings are deterministic: identical text → identical
    // vector → ANN cosine 1.0 against the query. Two chunks with the query
    // text give topK=2 candidates that both clear skip_score 0.95.
    $query = 'shipping costs to belgium';
    skipTestChunk($agent->id, $source->id, $query);
    skipTestChunk($agent->id, $source->id, $query);

    /** @var FakeReranker $reranker */
    $reranker = app(Reranker::class);

    $result = app(Retriever::class)->retrieve($agent->id, $query, threshold: 0.0, topK: 2);

    expect($reranker->calls)->toBe([])
        ->and($result['timings']['rerank_skipped'] ?? 0)->toBe(1)
        ->and($result['timings']['rerank_ms'])->toBe(0)
        ->and(count($result['chunks']))->toBe(2);
});

it('still reranks when ANN scores are weak', function () {
    config(['services.rag.rerank_skip' => true, 'services.rag.rerank_skip_score' => 0.95]);

    $agent = skipTestAgent();
    $source = Source::factory()->create(['agent_id' => $agent->id]);

    // Texts differ from the query → synthetic cosines land well below 0.95.
    skipTestChunk($agent->id, $source->id, 'return policy for damaged goods');
    skipTestChunk($agent->id, $source->id, 'warranty length on appliances');

    /** @var FakeReranker $reranker */
    $reranker = app(Reranker::class);

    $result = app(Retriever::class)->retrieve($agent->id, 'shipping costs to belgium', threshold: 0.0, topK: 2);

    expect(count($reranker->calls))->toBe(1)
        ->and($result['timings'])->not->toHaveKey('rerank_skipped');
});

it('still reranks when fewer than topK candidates exist even if scores are strong', function () {
    config(['services.rag.rerank_skip' => true, 'services.rag.rerank_skip_score' => 0.95]);

    $agent = skipTestAgent();
    $source = Source::factory()->create(['agent_id' => $agent->id]);

    $query = 'shipping costs to belgium';
    skipTestChunk($agent->id, $source->id, $query); // one perfect candidate, topK=2

    /** @var FakeReranker $reranker */
    $reranker = app(Reranker::class);

    app(Retriever::class)->retrieve($agent->id, $query, threshold: 0.0, topK: 2);

    expect(count($reranker->calls))->toBe(1);
});

it('never skips when the flag is off (default)', function () {
    config(['services.rag.rerank_skip' => false, 'services.rag.rerank_skip_score' => 0.95]);

    $agent = skipTestAgent();
    $source = Source::factory()->create(['agent_id' => $agent->id]);

    $query = 'shipping costs to belgium';
    skipTestChunk($agent->id, $source->id, $query);
    skipTestChunk($agent->id, $source->id, $query);

    /** @var FakeReranker $reranker */
    $reranker = app(Reranker::class);

    app(Retriever::class)->retrieve($agent->id, $query, threshold: 0.0, topK: 2);

    expect(count($reranker->calls))->toBe(1);
});

it('threshold filter passes on ANN score when rerank was skipped', function () {
    config(['services.rag.rerank_skip' => true, 'services.rag.rerank_skip_score' => 0.95]);

    $agent = skipTestAgent();
    $source = Source::factory()->create(['agent_id' => $agent->id]);

    $query = 'shipping costs to belgium';
    skipTestChunk($agent->id, $source->id, $query);
    skipTestChunk($agent->id, $source->id, $query);

    // Threshold 0.9 < cosine 1.0 — chunks must survive with no
    // rerank_score present at all.
    $result = app(Retriever::class)->retrieve($agent->id, $query, threshold: 0.9, topK: 2);

    expect(count($result['chunks']))->toBe(2)
        ->and($result['chunks'][0])->not->toHaveKey('rerank_score')
        ->and($result['low_confidence'])->toBeFalse();
});
