<?php

use App\Jobs\Analytics\SuggestCuratedAnswerForGapJob;
use App\Models\Agent;
use App\Models\Chunk;
use App\Models\ContentGap;
use App\Models\CuratedAnswer;
use App\Models\Document;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Fakes\FakeOpenAi;
use App\Services\Rag\Retriever;
use App\Services\Vector\Contracts\QdrantClient;
use App\Services\Vector\Fakes\FakeQdrant;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

function seedAgentWithSomeChunks(): array
{
    $ws = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);
    $source = Source::factory()->create(['agent_id' => $agent->id]);
    $document = Document::factory()->create(['source_id' => $source->id, 'agent_id' => $agent->id]);

    /** @var FakeQdrant $q */
    $q = app(QdrantClient::class);
    $points = [];
    for ($i = 0; $i < 4; $i++) {
        $pid = (string) Str::uuid7();
        $chunk = Chunk::create([
            'document_id' => $document->id,
            'agent_id' => $agent->id,
            'ord' => $i,
            'text' => "fact #{$i}: pricing 148000",
            'token_count' => 6,
            'qdrant_point_id' => $pid,
        ]);
        $points[] = [
            'id' => $pid,
            'vector' => array_fill(0, 8, 0.1),
            'payload' => [
                'agent_id' => $agent->id,
                'document_id' => $document->id,
                'chunk_id' => $chunk->id,
                'url' => 'https://shop.example.com/x',
            ],
        ];
    }
    $q->upsertPoints('pitchbar-chunks', $points);

    return ['workspace' => $ws, 'agent' => $agent];
}

test('command dispatches the job for gaps over the occurrence threshold', function () {
    Bus::fake();
    ['agent' => $agent] = seedAgentWithSomeChunks();

    ContentGap::create([
        'agent_id' => $agent->id,
        'question' => 'how much does it cost?',
        'question_hash' => hash('sha256', 'how much does it cost?'),
        'occurrences' => 5,
        'last_seen_at' => now(),
        'status' => 'open',
    ]);
    ContentGap::create([
        'agent_id' => $agent->id,
        'question' => 'do you ship internationally?',
        'question_hash' => hash('sha256', 'do you ship internationally?'),
        'occurrences' => 1,
        'last_seen_at' => now(),
        'status' => 'open',
    ]);

    $this->artisan('pitchbar:suggest-from-gaps', ['--min-occurrences' => 3])->assertSuccessful();

    Bus::assertDispatchedTimes(SuggestCuratedAnswerForGapJob::class, 1);
});

test('job creates a disabled CuratedAnswer with the suggested marker', function () {
    ['agent' => $agent] = seedAgentWithSomeChunks();

    $gap = ContentGap::create([
        'agent_id' => $agent->id,
        'question' => 'what is the price?',
        'question_hash' => hash('sha256', 'what is the price?'),
        'occurrences' => 4,
        'last_seen_at' => now(),
        'status' => 'open',
    ]);

    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('It costs 148,000 taka.');

    (new SuggestCuratedAnswerForGapJob($gap->id))->handle(
        app(Retriever::class),
        $llm,
    );

    $ans = CuratedAnswer::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->first();
    expect($ans)->not->toBeNull();
    expect($ans->enabled)->toBeFalse();
    expect($ans->answer)->toContain('148,000 taka');
    expect($ans->conditions)->toMatchArray(['suggested' => true, 'suggested_from_gap_id' => $gap->id]);

    expect($gap->fresh()->status)->toBe('suggested');
});

test('job marks gap as unable_to_suggest when no chunks support it', function () {
    $ws = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);
    // No sources / chunks at all → retriever returns nothing.

    $gap = ContentGap::create([
        'agent_id' => $agent->id,
        'question' => 'whatever',
        'question_hash' => hash('sha256', 'whatever'),
        'occurrences' => 5,
        'last_seen_at' => now(),
        'status' => 'open',
    ]);

    (new SuggestCuratedAnswerForGapJob($gap->id))->handle(
        app(Retriever::class),
        app(OpenAiClient::class),
    );

    expect($gap->fresh()->status)->toBe('unable_to_suggest');
    expect(CuratedAnswer::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('job marks gap unable_to_suggest when LLM returns NO_ANSWER', function () {
    ['agent' => $agent] = seedAgentWithSomeChunks();

    $gap = ContentGap::create([
        'agent_id' => $agent->id,
        'question' => 'will it run on Mars?',
        'question_hash' => hash('sha256', 'will it run on Mars?'),
        'occurrences' => 7,
        'last_seen_at' => now(),
        'status' => 'open',
    ]);

    /** @var FakeOpenAi $llm */
    $llm = app(OpenAiClient::class);
    $llm->setDefaultResponse('NO_ANSWER');

    (new SuggestCuratedAnswerForGapJob($gap->id))->handle(
        app(Retriever::class),
        $llm,
    );

    expect($gap->fresh()->status)->toBe('unable_to_suggest');
    expect(CuratedAnswer::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->count())->toBe(0);
});

test('approve endpoint flips a suggested CuratedAnswer to enabled and clears markers', function () {
    ['workspace' => $ws, 'agent' => $agent] = seedAgentWithSomeChunks();

    $owner = User::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $owner->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $owner->forceFill(['default_workspace_id' => $ws->id])->save();

    $ca = CuratedAnswer::create([
        'agent_id' => $agent->id,
        'question_pattern' => 'price?',
        'answer' => 'It costs 148,000 taka.',
        'priority' => 50,
        'conditions' => ['suggested' => true, 'suggested_from_gap_id' => 'gap-x'],
        'enabled' => false,
    ]);

    $this->actingAs($owner)
        ->post("/app/curated-answers/{$ca->id}/approve")
        ->assertRedirect();

    $fresh = $ca->fresh();
    expect($fresh->enabled)->toBeTrue();
    expect($fresh->conditions)->not->toHaveKey('suggested');
    expect($fresh->conditions)->not->toHaveKey('suggested_from_gap_id');
});

test('viewer cannot approve', function () {
    ['workspace' => $ws, 'agent' => $agent] = seedAgentWithSomeChunks();

    $viewer = User::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $viewer->id,
        'role' => 'viewer',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $viewer->forceFill(['default_workspace_id' => $ws->id])->save();

    $ca = CuratedAnswer::create([
        'agent_id' => $agent->id,
        'question_pattern' => 'price?',
        'answer' => 'whatever',
        'priority' => 50,
        'conditions' => ['suggested' => true],
        'enabled' => false,
    ]);

    $this->actingAs($viewer)
        ->post("/app/curated-answers/{$ca->id}/approve")
        ->assertForbidden();

    expect($ca->fresh()->enabled)->toBeFalse();
});
