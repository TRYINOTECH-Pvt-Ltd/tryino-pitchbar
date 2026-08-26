<?php

use App\Jobs\Agents\PurgeAgentVectorsJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Document;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Source;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;

function deleteCascadeAsAdmin(): array
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

test('deleting an agent hard-deletes the agent row from the database', function () {
    ['user' => $user, 'workspace' => $workspace] = deleteCascadeAsAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->delete(route('agents.destroy', ['agent' => $agent->id]))
        ->assertRedirect();

    expect(Agent::query()->withTrashed()->where('id', $agent->id)->exists())->toBeFalse();
});

test('deleting an agent cascades playground conversations + their messages', function () {
    ['user' => $user, 'workspace' => $workspace] = deleteCascadeAsAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $playgroundConv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'is_playground' => true,
    ]);
    Message::create([
        'id' => (string) Str::uuid(),
        'conversation_id' => $playgroundConv->id,
        'role' => 'user',
        'content' => 'hi from the playground',
    ]);

    $this->actingAs($user)
        ->delete(route('agents.destroy', ['agent' => $agent->id]))
        ->assertRedirect();

    expect(Conversation::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->count())->toBe(0);
    expect(Message::query()->where('conversation_id', $playgroundConv->id)->count())->toBe(0);
});

test('deleting an agent cascades real conversations + leads + sources + documents', function () {
    ['user' => $user, 'workspace' => $workspace] = deleteCascadeAsAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'is_playground' => false,
    ]);
    Lead::create([
        'agent_id' => $agent->id,
        'conversation_id' => $conv->id,
        'email' => 'lead@example.com',
        'status' => 'new',
    ]);
    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'url',
        'status' => 'indexed',
    ]);
    Document::create([
        'agent_id' => $agent->id,
        'source_id' => $source->id,
        'url' => 'https://example.com',
        'title' => 'Test',
        'content_hash' => 'abc',
        'fetched_at' => now(),
    ]);

    $this->actingAs($user)
        ->delete(route('agents.destroy', ['agent' => $agent->id]))
        ->assertRedirect();

    expect(Lead::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->count())->toBe(0);
    expect(Source::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->count())->toBe(0);
    expect(Document::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->count())->toBe(0);
    expect(Visitor::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->count())->toBe(0);
});

test('bulk destroy also hard-deletes', function () {
    ['user' => $user, 'workspace' => $workspace] = deleteCascadeAsAdmin();
    $a = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $b = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->post(route('agents.bulkDestroy'), ['ids' => [$a->id, $b->id]])
        ->assertRedirect();

    expect(Agent::query()->withTrashed()->whereIn('id', [$a->id, $b->id])->count())->toBe(0);
});

test('deleting an agent dispatches PurgeAgentVectorsJob to clear orphan vectors', function () {
    Bus::fake([PurgeAgentVectorsJob::class]);

    ['user' => $user, 'workspace' => $workspace] = deleteCascadeAsAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->delete(route('agents.destroy', ['agent' => $agent->id]))
        ->assertRedirect();

    Bus::assertDispatched(
        PurgeAgentVectorsJob::class,
        fn (PurgeAgentVectorsJob $job) => $job->agentId === $agent->id,
    );
});

test('bulk destroy dispatches PurgeAgentVectorsJob for every deleted agent', function () {
    Bus::fake([PurgeAgentVectorsJob::class]);

    ['user' => $user, 'workspace' => $workspace] = deleteCascadeAsAdmin();
    $a = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $b = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->post(route('agents.bulkDestroy'), ['ids' => [$a->id, $b->id]])
        ->assertRedirect();

    Bus::assertDispatched(PurgeAgentVectorsJob::class, 2);
});
