<?php

use App\Jobs\Crawl\CrawlSourceJob;
use App\Jobs\Crawl\IndexTextSourceJob;
use App\Models\Agent;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Testing\Fakes\BusFake;

function asAdmin(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return ['user' => $user, 'workspace' => $workspace];
}

test('admin can add a URL source and the crawl is dispatched', function () {
    Bus::fake();

    ['user' => $user, 'workspace' => $workspace] = asAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)->post(route('agents.sources.store', ['agent' => $agent->id]), [
        'type' => 'url',
        'url' => 'https://pitchbar.io',
    ])->assertRedirect();

    $source = Source::query()->where('agent_id', $agent->id)->firstOrFail();
    expect($source->type)->toBe('url');
    expect($source->config['url'])->toBe('https://pitchbar.io');
    Bus::assertDispatched(CrawlSourceJob::class);
});

test('viewer cannot add a source', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'viewer',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)->post(route('agents.sources.store', ['agent' => $agent->id]), [
        'type' => 'url',
        'url' => 'https://pitchbar.io',
    ])->assertForbidden();
});

test('reindex re-dispatches the crawl', function () {
    Bus::fake();

    ['user' => $user, 'workspace' => $workspace] = asAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $source = Source::factory()->create(['agent_id' => $agent->id, 'status' => 'failed']);

    $this->actingAs($user)->post(route('sources.reindex', ['source' => $source->id]))->assertRedirect();

    expect($source->fresh()->status)->toBe('pending');
    Bus::assertDispatched(CrawlSourceJob::class);
});

test('reindex returns a friendly error flash when the queue backend is unreachable', function () {
    // Buyer report 2026-05-26: Predis\Connection\ConnectionException
    // bubbled out of PendingDispatch::__destruct, 500'd the admin
    // page. Simulate by binding a throwing Queue dispatcher.
    ['user' => $user, 'workspace' => $workspace] = asAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $source = Source::factory()->create(['agent_id' => $agent->id, 'status' => 'failed']);

    $fake = new class extends BusFake
    {
        public function __construct() {}

        public function dispatch($command): mixed
        {
            throw new RuntimeException('Stream is already at the end [tcp://127.0.0.1:6379]');
        }

        public function dispatchSync($command, $handler = null): mixed
        {
            throw new RuntimeException('Stream is already at the end [tcp://127.0.0.1:6379]');
        }
    };
    $this->app->instance(Dispatcher::class, $fake);

    $this->actingAs($user)
        ->post(route('sources.reindex', ['source' => $source->id]))
        ->assertRedirect()
        ->assertSessionHas('error');

    $fresh = $source->fresh();
    expect($fresh->status)->toBe('failed')
        ->and($fresh->error)->toContain('Queue unavailable');
});

test('admin can paste text content as a source and indexing is dispatched', function () {
    Bus::fake();

    ['user' => $user, 'workspace' => $workspace] = asAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $body = str_repeat('The MacBook Air M5 13-inch ships in space gray and silver. ', 5);

    $this->actingAs($user)->post(route('agents.sources.text', ['agent' => $agent->id]), [
        'title' => 'MacBook Air M5 specs',
        'body' => $body,
        'source_url' => 'https://example.com/macbook',
    ])->assertRedirect();

    $source = Source::query()->where('agent_id', $agent->id)->firstOrFail();
    expect($source->type)->toBe('text');
    expect($source->config['title'])->toBe('MacBook Air M5 specs');
    expect($source->config['source_url'])->toBe('https://example.com/macbook');
    Bus::assertDispatched(IndexTextSourceJob::class);
});

test('paste-text rejects content that is too short', function () {
    ['user' => $user, 'workspace' => $workspace] = asAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)->post(route('agents.sources.text', ['agent' => $agent->id]), [
        'body' => 'too short',
    ])->assertSessionHasErrors('body');
});

test('viewer cannot paste text content', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'viewer',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)->post(route('agents.sources.text', ['agent' => $agent->id]), [
        'body' => str_repeat('plenty of content here. ', 10),
    ])->assertForbidden();
});

test('cross-workspace source store is forbidden', function () {
    ['user' => $user] = asAdmin();
    $other = Workspace::factory()->create();
    $otherAgent = Agent::factory()->create(['workspace_id' => $other->id]);

    $this->actingAs($user)->post(route('agents.sources.store', ['agent' => $otherAgent->id]), [
        'type' => 'url',
        'url' => 'https://pitchbar.io',
    ])->assertForbidden();
});
