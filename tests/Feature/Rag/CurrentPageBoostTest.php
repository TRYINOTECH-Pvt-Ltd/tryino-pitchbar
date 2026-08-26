<?php

use App\Models\Agent;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Rag\Retriever;
use App\Services\Vector\Contracts\QdrantClient;
use App\Services\Vector\Fakes\FakeQdrant;
use Illuminate\Support\Str;

function indexedChunk(string $agentId, string $sourceId, string $url, string $text): Chunk
{
    $doc = Document::create([
        'id' => (string) Str::uuid7(),
        'source_id' => $sourceId,
        'agent_id' => $agentId,
        'url' => $url,
        'title' => parse_url($url, PHP_URL_PATH) ?: '/',
        'content_hash' => hash('sha256', $url.$text),
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

    $vector = $llm->embed([$text])[0];
    $pointId = (string) Str::uuid7();
    $chunk->forceFill(['qdrant_point_id' => $pointId])->save();
    $vec->upsertPoints('pitchbar-chunks', [[
        'id' => $pointId,
        'vector' => $vector,
        'payload' => [
            'agent_id' => $agentId,
            'document_id' => $doc->id,
            'chunk_id' => $chunk->id,
            'url' => $url,
        ],
    ]]);

    return $chunk;
}

test('chunks from the visitor\'s current page outrank equivalent chunks from other pages', function () {
    $ws = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id, 'confidence_threshold' => 0.0]);
    $source = Source::factory()->create(['agent_id' => $agent->id]);

    // Two near-identical chunks on different product pages. The
    // similarity scores will be very close — close enough that the
    // tiebreaker is the current-page boost.
    $textShared = 'price discount offer best deal product';
    indexedChunk($agent->id, $source->id, 'https://shop.com/products/red-pen', $textShared.' red pen');
    indexedChunk($agent->id, $source->id, 'https://shop.com/products/varsha', $textShared.' varsha');

    /** @var Retriever $retriever */
    $retriever = app(Retriever::class);

    // Visitor on red-pen page asks the shared question.
    $resultA = $retriever->retrieve(
        agentId: $agent->id,
        query: 'price discount product',
        threshold: 0.0,
        topK: 6,
        currentPageUrl: 'https://shop.com/products/red-pen',
    );
    expect($resultA['chunks'][0]['url'])->toBe('https://shop.com/products/red-pen');

    // Same query, visitor now on varsha page → varsha chunk wins.
    $resultB = $retriever->retrieve(
        agentId: $agent->id,
        query: 'price discount product',
        threshold: 0.0,
        topK: 6,
        currentPageUrl: 'https://shop.com/products/varsha',
    );
    expect($resultB['chunks'][0]['url'])->toBe('https://shop.com/products/varsha');
});

test('current-page URL is canonicalized — fragment + query do not break the boost', function () {
    $ws = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id, 'confidence_threshold' => 0.0]);
    $source = Source::factory()->create(['agent_id' => $agent->id]);

    indexedChunk($agent->id, $source->id, 'https://shop.com/products/red-pen', 'red pen content');
    indexedChunk($agent->id, $source->id, 'https://shop.com/about', 'company history page');

    /** @var Retriever $retriever */
    $retriever = app(Retriever::class);

    $result = $retriever->retrieve(
        agentId: $agent->id,
        query: 'tell me about the product',
        threshold: 0.0,
        topK: 6,
        // visitor scrolled to a section + arrived via a tracking param
        currentPageUrl: 'https://shop.com/products/red-pen/?utm_source=ig#description',
    );

    expect($result['chunks'][0]['url'])->toBe('https://shop.com/products/red-pen');
    // The boosted flag should be set on the matching chunk.
    expect($result['chunks'][0]['boosted_for_current_page'] ?? false)->toBeTrue();
});

test('boosted chunk below the agent threshold still passes through', function () {
    // Contract: boosted_for_current_page chunks bypass the threshold
    // filter even when their raw score is below it.
    $ws = Workspace::factory()->create();
    // Threshold deliberately high so a non-current-page chunk of the
    // same text would NOT make it through.
    $agent = Agent::factory()->create(['workspace_id' => $ws->id, 'confidence_threshold' => 0.99]);
    $source = Source::factory()->create(['agent_id' => $agent->id]);

    // Chunk lives on the visitor's current page. Embedding ranks low
    // for the deliberately-mismatched query, but the +0.15 boost
    // + the `boosted_for_current_page` flag bypass the threshold gate.
    indexedChunk(
        $agent->id,
        $source->id,
        'https://shop.com/products/red-pen',
        'completely unrelated text that wont embedding-rank well',
    );

    /** @var Retriever $retriever */
    $retriever = app(Retriever::class);

    $result = $retriever->retrieve(
        agentId: $agent->id,
        query: 'red pen',
        threshold: 0.99,
        topK: 6,
        currentPageUrl: 'https://shop.com/products/red-pen',
    );

    // Chunk should still be present (boost-bypass) AND tagged.
    expect($result['chunks'])->not->toBeEmpty();
    expect($result['chunks'][0]['boosted_for_current_page'] ?? false)->toBeTrue();
});

test('no current page → existing behavior preserved (no boost, no flag)', function () {
    $ws = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id, 'confidence_threshold' => 0.0]);
    $source = Source::factory()->create(['agent_id' => $agent->id]);

    indexedChunk($agent->id, $source->id, 'https://shop.com/products/red-pen', 'red pen content');

    /** @var Retriever $retriever */
    $retriever = app(Retriever::class);

    $result = $retriever->retrieve(
        agentId: $agent->id,
        query: 'red pen content',
        threshold: 0.0,
        topK: 6,
    );

    expect($result['chunks'])->not->toBeEmpty();
    expect($result['chunks'][0]['boosted_for_current_page'] ?? false)->toBeFalse();
});
