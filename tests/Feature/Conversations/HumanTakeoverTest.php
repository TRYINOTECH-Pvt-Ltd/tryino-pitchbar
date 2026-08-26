<?php

use App\Events\Conversations\AgentReplyPostedEvent;
use App\Events\Conversations\ConversationClaimedEvent;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Facades\Event;

function takeoverWs(): array
{
    $owner = User::factory()->create();
    $ws = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $owner->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $owner->forceFill(['default_workspace_id' => $ws->id])->save();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $convo = Conversation::factory()->create(['agent_id' => $agent->id, 'visitor_id' => $visitor->id]);

    return ['owner' => $owner, 'workspace' => $ws, 'agent' => $agent, 'conversation' => $convo];
}

test('claim assigns the conversation to the operator and broadcasts', function () {
    Event::fake([ConversationClaimedEvent::class]);
    ['owner' => $owner, 'conversation' => $c] = takeoverWs();

    $response = $this->actingAs($owner)
        ->postJson("/app/conversations/{$c->id}/claim");

    $response->assertOk();
    $response->assertJsonPath('data.conversation_id', $c->id);

    $fresh = $c->fresh();
    expect($fresh->claimed_by_user_id)->toBe($owner->id);
    expect($fresh->claimed_at)->not->toBeNull();

    Event::assertDispatched(ConversationClaimedEvent::class, fn ($e) => $e->conversationId === $c->id && $e->userId === (string) $owner->id);
});

test('claim returns 409 if another operator already holds it', function () {
    ['workspace' => $ws, 'agent' => $agent, 'conversation' => $c] = takeoverWs();
    $other = User::factory()->create(['name' => 'Alice']);
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $other->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $other->forceFill(['default_workspace_id' => $ws->id])->save();

    // Alice claims first
    $this->actingAs($other)->postJson("/app/conversations/{$c->id}/claim")->assertOk();

    // A second admin tries
    $bob = User::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $bob->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $bob->forceFill(['default_workspace_id' => $ws->id])->save();

    $this->actingAs($bob)
        ->postJson("/app/conversations/{$c->id}/claim")
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'already_claimed')
        ->assertJsonPath('error.by.name', 'Alice');
});

test('release clears the claim if held by the same user', function () {
    ['owner' => $owner, 'conversation' => $c] = takeoverWs();

    $this->actingAs($owner)->postJson("/app/conversations/{$c->id}/claim")->assertOk();
    $this->actingAs($owner)->postJson("/app/conversations/{$c->id}/release")->assertOk();

    $fresh = $c->fresh();
    expect($fresh->claimed_by_user_id)->toBeNull();
    expect($fresh->claimed_at)->toBeNull();
});

test('release clears human_requested_at and posts an AI-resumed system message + broadcasts', function () {
    // Buyer-reported (Lucian, 2026-05-15): visitor's "Connecting you
    // with someone…" banner persisted forever after operator release
    // because human_requested_at was left set. Visitor's widget treats
    // (human_requested_at != null AND claimed_by null) as "still queued
    // for an operator", so the banner looped.
    Event::fake([AgentReplyPostedEvent::class]);
    ['owner' => $owner, 'conversation' => $c] = takeoverWs();
    $c->forceFill(['human_requested_at' => now()])->save();

    $this->actingAs($owner)->postJson("/app/conversations/{$c->id}/claim")->assertOk();
    $this->actingAs($owner)->postJson("/app/conversations/{$c->id}/release")->assertOk();

    $fresh = $c->fresh();
    expect($fresh->human_requested_at)->toBeNull();
    expect($fresh->operator_typing_until)->toBeNull();

    // The chat history gets a one-line note so the visitor knows
    // what just happened (visible to their poll endpoint because
    // `model LIKE 'human:%'` matches).
    $note = Message::query()
        ->where('conversation_id', $c->id)
        ->where('role', 'assistant')
        ->where('model', 'like', 'human:%')
        ->first();
    expect($note)->not->toBeNull();
    expect((string) $note->content)->toContain('AI assistant is back to help');

    // Release should broadcast so the visitor's widget updates without
    // waiting for the next 3-second poll. Mirror of claim()'s
    // ConversationClaimedEvent dispatch.
    Event::assertDispatched(AgentReplyPostedEvent::class, fn ($e) => $e->conversationId === $c->id);
});

test('release on an unclaimed conversation clears flags but does NOT post the AI-resumed note', function () {
    // Defensive: race condition / double-click / admin clearing a stale
    // queue entry must not invent a fake "operator stepped away"
    // history entry — there was no operator to step away.
    Event::fake([AgentReplyPostedEvent::class]);
    ['owner' => $owner, 'conversation' => $c] = takeoverWs();
    $c->forceFill(['human_requested_at' => now()])->save();

    $this->actingAs($owner)
        ->postJson("/app/conversations/{$c->id}/release")
        ->assertOk();

    $fresh = $c->fresh();
    expect($fresh->claimed_by_user_id)->toBeNull();
    expect($fresh->human_requested_at)->toBeNull();

    $note = Message::query()
        ->where('conversation_id', $c->id)
        ->where('role', 'assistant')
        ->where('model', 'like', 'human:%')
        ->exists();
    expect($note)->toBeFalse();

    Event::assertNotDispatched(AgentReplyPostedEvent::class);
});

test('reply requires claim and persists a message + broadcasts', function () {
    Event::fake([AgentReplyPostedEvent::class]);
    ['owner' => $owner, 'conversation' => $c] = takeoverWs();

    // Without claim → 409
    $this->actingAs($owner)
        ->postJson("/app/conversations/{$c->id}/reply", ['content' => 'hi'])
        ->assertStatus(409);

    // After claim → 200
    $this->actingAs($owner)->postJson("/app/conversations/{$c->id}/claim");
    $response = $this->actingAs($owner)
        ->postJson("/app/conversations/{$c->id}/reply", ['content' => 'Hi there, can I help?']);

    $response->assertOk();

    expect(Message::query()->where('conversation_id', $c->id)->where('content', 'Hi there, can I help?')->exists())->toBeTrue();
    Event::assertDispatched(AgentReplyPostedEvent::class, fn ($e) => $e->conversationId === $c->id);
});

test('reply accepts the claimant even when DB hydrates claimed_by_user_id as a string', function () {
    // Buyer-reported (Lucian, 2026-05-15): claim() succeeded, but the
    // very next reply() returned 'must_claim_first'. Root cause: some
    // PDO drivers (cPanel MySQL with emulation on, MariaDB shared-host
    // installs) hand back BIGINT columns as strings; the strict !==
    // comparison then mismatched int 5 vs "5". This regression test
    // simulates the string-hydrated path by manually forceFill-ing the
    // value as a string AFTER the claim DB write.
    ['owner' => $owner, 'conversation' => $c] = takeoverWs();
    $this->actingAs($owner)->postJson("/app/conversations/{$c->id}/claim")->assertOk();

    // Simulate the string-hydration corner case directly on the DB row.
    DB::table('conversations')->where('id', $c->id)->update([
        'claimed_by_user_id' => (string) $owner->id,
    ]);

    $this->actingAs($owner)
        ->postJson("/app/conversations/{$c->id}/reply", ['content' => 'works under string hydration'])
        ->assertOk();
});

test('viewer cannot claim, release, or reply', function () {
    $viewer = User::factory()->create();
    $ws = Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $viewer->id,
        'role' => 'viewer',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $viewer->forceFill(['default_workspace_id' => $ws->id])->save();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $c = Conversation::factory()->create(['agent_id' => $agent->id, 'visitor_id' => $visitor->id]);

    $this->actingAs($viewer)->postJson("/app/conversations/{$c->id}/claim")->assertForbidden();
    $this->actingAs($viewer)->postJson("/app/conversations/{$c->id}/release")->assertForbidden();
    $this->actingAs($viewer)->postJson("/app/conversations/{$c->id}/reply", ['content' => 'x'])->assertForbidden();
});

test('reply validates content length', function () {
    ['owner' => $owner, 'conversation' => $c] = takeoverWs();
    $this->actingAs($owner)->postJson("/app/conversations/{$c->id}/claim");

    // Empty
    $this->actingAs($owner)
        ->postJson("/app/conversations/{$c->id}/reply", ['content' => ''])
        ->assertStatus(422);

    // Too long
    $this->actingAs($owner)
        ->postJson("/app/conversations/{$c->id}/reply", ['content' => str_repeat('x', 5000)])
        ->assertStatus(422);
});
