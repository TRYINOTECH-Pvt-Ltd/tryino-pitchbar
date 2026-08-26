<?php

use App\Jobs\Crawl\IndexTextSourceJob;
use App\Models\Agent;
use App\Models\Document;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Facades\Bus;

/**
 * Pasted-text knowledge sources used to be create-only — to change the
 * content you had to delete and recreate. These pin the Edit flow:
 *   - create persists the body so an edit form can prefill it,
 *   - updateText re-indexes with the new body,
 *   - IndexTextSourceJob now replaces (purges the prior document) instead
 *     of leaving stale content embedded alongside the edit.
 */
function editTextActor(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    return ['user' => $user, 'agent' => $agent];
}

test('creating a text source persists the body in config for later editing', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = editTextActor();

    $this->actingAs($user)->post(
        route('agents.sources.text', ['agent' => $agent->id]),
        ['title' => 'My note', 'body' => str_repeat('original content that clears the minimum. ', 3)],
    )->assertRedirect();

    $source = Source::query()->where('agent_id', $agent->id)->where('type', 'text')->firstOrFail();
    expect($source->config['body'])->toContain('original content');
    expect($source->config['title'])->toBe('My note');
});

test('updateText re-indexes the source with the new body', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = editTextActor();
    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'text',
        'status' => 'indexed',
        'config' => ['title' => 'Old', 'source_url' => null, 'body' => str_repeat('old body text here. ', 4)],
    ]);

    $this->actingAs($user)->patch(
        "/app/sources/{$source->id}/text",
        ['title' => 'New title', 'body' => str_repeat('the brand new edited content goes here. ', 3)],
    )->assertRedirect();

    $source->refresh();
    expect($source->config['title'])->toBe('New title')
        ->and($source->config['body'])->toContain('brand new edited content')
        ->and($source->status)->toBe('pending');

    Bus::assertDispatched(
        IndexTextSourceJob::class,
        fn (IndexTextSourceJob $job) => $job->sourceId === $source->id
            && str_contains($job->body, 'brand new edited content'),
    );
});

test('updateText 404s for a non-text source', function () {
    ['user' => $user, 'agent' => $agent] = editTextActor();
    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'url',
        'status' => 'indexed',
        'config' => ['url' => 'https://example.com'],
    ]);

    $this->actingAs($user)
        ->patch("/app/sources/{$source->id}/text", ['body' => str_repeat('x ', 30)])
        ->assertNotFound();
});

test('updateText requires a body of at least 50 characters', function () {
    ['user' => $user, 'agent' => $agent] = editTextActor();
    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'text',
        'status' => 'indexed',
        'config' => ['title' => 'X', 'body' => str_repeat('original body content. ', 4)],
    ]);

    $this->actingAs($user)
        ->patch("/app/sources/{$source->id}/text", ['body' => 'too short'])
        ->assertSessionHasErrors('body');
});

test('IndexTextSourceJob replaces the prior document on re-run (no stale duplicate)', function () {
    ['user' => $user, 'agent' => $agent] = editTextActor();
    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'text',
        'status' => 'pending',
        'config' => ['title' => 'Doc', 'body' => 'first version of the pasted content, long enough to index.'],
    ]);

    // First index.
    (new IndexTextSourceJob($source->id, 'Doc', 'first version of the pasted content, long enough to index.'))->handle();
    // Edit → re-index with new content.
    (new IndexTextSourceJob($source->id, 'Doc', 'second, completely rewritten version of the content, also long enough.'))->handle();

    $docs = Document::query()->withoutGlobalScopes()->where('source_id', $source->id)->get();
    expect($docs)->toHaveCount(1)
        ->and($docs->first()->content_hash)->toBe(hash('sha256', 'second, completely rewritten version of the content, also long enough.'));
});
