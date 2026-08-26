<?php

use App\Jobs\Crawl\IndexDocumentJob;
use App\Models\Agent;
use App\Models\Chunk;
use App\Models\Document;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\Vector\Contracts\QdrantClient;
use App\Services\Vector\Fakes\FakeQdrant;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

function seedAgentWithChunksAndVectors(int $dbChunks, int $alsoInVectorize): array
{
    $ws = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);
    $source = Source::factory()->create(['agent_id' => $agent->id]);
    $document = Document::factory()->create(['source_id' => $source->id, 'agent_id' => $agent->id]);

    /** @var FakeQdrant $vector */
    $vector = app(QdrantClient::class);
    $points = [];

    $chunks = [];
    for ($i = 0; $i < $dbChunks; $i++) {
        $pointId = (string) Str::uuid7();
        $chunk = Chunk::create([
            'document_id' => $document->id,
            'agent_id' => $agent->id,
            'ord' => $i,
            'text' => "chunk {$i}",
            'token_count' => 8,
            'qdrant_point_id' => $pointId,
        ]);
        $chunks[] = $chunk;

        if ($i < $alsoInVectorize) {
            $points[] = [
                'id' => $pointId,
                'vector' => array_fill(0, 8, 0.1),
                'payload' => [
                    'agent_id' => $agent->id,
                    'document_id' => $document->id,
                    'chunk_id' => $chunk->id,
                ],
            ];
        }
    }
    if ($points !== []) {
        $vector->upsertPoints('pitchbar-chunks', $points);
    }

    return ['agent' => $agent, 'document' => $document, 'chunks' => $chunks];
}

test('does NOT re-queue anything when DB and Vectorize match', function () {
    Bus::fake();
    ['agent' => $agent] = seedAgentWithChunksAndVectors(dbChunks: 4, alsoInVectorize: 4);

    /** @var FakeQdrant $q */
    $q = app(QdrantClient::class);
    $stored = $q->dump('pitchbar-chunks');
    expect(count($stored))->toBe(4); // sanity check the seeder

    $this->artisan('pitchbar:audit-vectors', ['--agent' => $agent->id, '--reindex' => true])->assertSuccessful();

    Bus::assertNotDispatched(IndexDocumentJob::class);
});

test('runs successfully when there are no agents', function () {
    $this->artisan('pitchbar:audit-vectors')->assertSuccessful();
});

test('--reindex re-queues IndexDocumentJob for missing chunks', function () {
    Bus::fake();
    seedAgentWithChunksAndVectors(dbChunks: 3, alsoInVectorize: 1);

    $this->artisan('pitchbar:audit-vectors', ['--reindex' => true])
        ->assertSuccessful();

    Bus::assertDispatched(IndexDocumentJob::class);
});

test('--agent restricts the audit to a single agent', function () {
    Bus::fake();
    ['agent' => $a] = seedAgentWithChunksAndVectors(dbChunks: 2, alsoInVectorize: 0);
    seedAgentWithChunksAndVectors(dbChunks: 2, alsoInVectorize: 0);

    $this->artisan('pitchbar:audit-vectors', ['--agent' => $a->id, '--reindex' => true])
        ->assertSuccessful();

    // Only the one targeted agent's missing docs should have been requeued.
    Bus::assertDispatchedTimes(IndexDocumentJob::class, 1);
});
