<?php

use App\Jobs\Crawl\IndexDocumentJob;
use App\Models\Agent;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Llm\OpenAiHttpClient;
use Illuminate\Support\Str;

function indexDoc(): Document
{
    $ws = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);
    $source = Source::factory()->create(['agent_id' => $agent->id]);

    return Document::create([
        'id' => (string) Str::uuid7(),
        'source_id' => $source->id,
        'agent_id' => $agent->id,
        'url' => 'https://shop.com/p',
        'title' => 'P',
        'content_hash' => str_repeat('a', 64),
        'fetched_at' => now(),
    ]);
}

class ThrowingPrimaryClient implements OpenAiClient
{
    public function streamChat(array $messages, array $opts = []): iterable
    {
        return [];
    }

    public function chatWithTools(array $messages, array $tools, array $opts = []): array
    {
        return ['content' => '', 'finish_reason' => 'stop'];
    }

    public function embed(array $inputs): array
    {
        throw new RuntimeException('CF Workers AI is having a moment');
    }
}

test('falls back to the embedding.fallback binding when primary embed throws', function () {
    $this->app->instance(OpenAiClient::class, new ThrowingPrimaryClient);
    // Bind a Fake as the fallback — runs reliably + has deterministic vectors.
    $this->app->bind('embedding.fallback', fn () => new FakeOpenAi);

    $doc = indexDoc();
    IndexDocumentJob::dispatchSync(
        $doc->id,
        str_repeat('Apple MacBook Air with M5 chip and 18-hour battery. ', 30),
    );

    $chunks = Chunk::query()->withoutGlobalScopes()->where('document_id', $doc->id)->get();
    expect($chunks)->not->toBeEmpty();
    foreach ($chunks as $c) {
        // qdrant_point_id is only set after the embed call returns vectors
        // and the vector store accepts them — proves the fallback ran.
        expect($c->qdrant_point_id)->not->toBeNull();
    }
});

test('rethrows when primary fails AND no embedding.fallback is bound', function () {
    $this->app->instance(OpenAiClient::class, new ThrowingPrimaryClient);
    // Explicitly drop any default fallback binding.
    $this->app->offsetUnset('embedding.fallback');

    $doc = indexDoc();

    expect(fn () => IndexDocumentJob::dispatchSync(
        $doc->id,
        str_repeat('lorem ipsum dolor. ', 30),
    ))->toThrow(RuntimeException::class, 'CF Workers AI');
});

test('does not attempt fallback when primary IS already OpenAiHttpClient', function () {
    // If the primary is already OpenAI and it fails, falling back to
    // another OpenAI client would just hit the same provider/error.
    // Rethrow instead.
    $primary = Mockery::mock(OpenAiHttpClient::class);
    $primary->shouldReceive('embed')->andThrow(new RuntimeException('OpenAI quota'));
    $this->app->instance(OpenAiClient::class, $primary);
    $this->app->bind('embedding.fallback', fn () => new FakeOpenAi);

    $doc = indexDoc();

    expect(fn () => IndexDocumentJob::dispatchSync(
        $doc->id,
        str_repeat('text. ', 60),
    ))->toThrow(RuntimeException::class, 'OpenAI quota');
});
