<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Str;

function feedMember(): array
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

test('feed returns handoff_requests for unclaimed conversations', function () {
    ['user' => $user, 'workspace' => $workspace] = feedMember();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Pitchbar Demo',
    ]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'human_requested_at' => now(),
        'claimed_by_user_id' => null,
        'page_url' => 'https://shop.example.com/products/red-pen',
    ]);

    $body = $this->actingAs($user)
        ->get('/app/leads/feed')
        ->assertOk()
        ->json('data');

    expect($body['handoff_requests'])->toHaveCount(1);
    expect($body['handoff_requests'][0]['id'])->toBe($conv->id);
    expect($body['handoff_requests'][0]['agent_name'])->toBe('Pitchbar Demo');
    expect($body['handoff_requests'][0]['page_url'])
        ->toBe('https://shop.example.com/products/red-pen');
    expect($body['handoff_requests'][0]['conversation_url'])
        ->toBe('/app/conversations/'.$conv->id);
});

test('feed excludes claimed conversations from handoff_requests', function () {
    ['user' => $user, 'workspace' => $workspace] = feedMember();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);

    Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'human_requested_at' => now(),
        'claimed_by_user_id' => $user->id,
        'claimed_at' => now(),
    ]);

    $body = $this->actingAs($user)
        ->get('/app/leads/feed')
        ->assertOk()
        ->json('data');

    expect($body['handoff_requests'])->toBe([]);
});

test('handoff_since cursor filters older requests out', function () {
    ['user' => $user, 'workspace' => $workspace] = feedMember();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);

    $old = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'human_requested_at' => now()->subMinutes(10),
    ]);
    $fresh = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'human_requested_at' => now()->subSeconds(5),
    ]);

    $cursor = now()->subMinutes(2)->toIso8601String();

    $body = $this->actingAs($user)
        ->get('/app/leads/feed?handoff_since='.urlencode($cursor))
        ->assertOk()
        ->json('data');

    $ids = collect($body['handoff_requests'])->pluck('id')->all();
    expect($ids)->toContain($fresh->id);
    expect($ids)->not->toContain($old->id);
});

test('feed surfaces the visitor first-message preview when available', function () {
    ['user' => $user, 'workspace' => $workspace] = feedMember();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'human_requested_at' => now(),
    ]);
    Message::create([
        'id' => (string) Str::uuid7(),
        'conversation_id' => $conv->id,
        'role' => 'user',
        'content' => 'I need help with my refund — order #1234',
        'citations' => [],
    ]);

    $body = $this->actingAs($user)
        ->get('/app/leads/feed')
        ->assertOk()
        ->json('data');

    expect($body['handoff_requests'][0]['preview'])
        ->toContain('refund')
        ->toContain('1234');
});
