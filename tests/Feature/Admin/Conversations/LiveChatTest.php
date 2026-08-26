<?php

use App\Events\Conversations\AgentReplyPostedEvent;
use App\Events\Conversations\ConversationClaimedEvent;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia;

function liveChatMember(string $role = 'admin'): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return ['user' => $user, 'workspace' => $workspace];
}

function liveChatConversation(Workspace $workspace, array $convOverrides = []): Conversation
{
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);

    return Conversation::factory()->create(array_merge([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ], $convOverrides));
}

test('show page exposes claim status, lead info, and live-handoff state', function () {
    ['user' => $user, 'workspace' => $workspace] = liveChatMember();
    $conv = liveChatConversation($workspace, [
        'human_requested_at' => now()->subMinute(),
        'claimed_by_user_id' => null,
    ]);

    $this->actingAs($user)
        ->get("/app/conversations/{$conv->id}")
        ->assertInertia(function (AssertableInertia $page) {
            $page->component('app/conversations/show')
                ->has('conversation.human_requested_at')
                ->where('conversation.claimed_by', null)
                ->where('can_takeover', true)
                ->has('auth_user_id');
        });
});

test('claim → reply → release lifecycle broadcasts the right events', function () {
    Event::fake([ConversationClaimedEvent::class, AgentReplyPostedEvent::class]);

    ['user' => $user, 'workspace' => $workspace] = liveChatMember();
    $conv = liveChatConversation($workspace, [
        'human_requested_at' => now()->subMinute(),
    ]);

    // Claim
    $this->actingAs($user)
        ->postJson("/app/conversations/{$conv->id}/claim")
        ->assertOk();

    expect($conv->fresh()->claimed_by_user_id)->toBe($user->id);
    Event::assertDispatched(ConversationClaimedEvent::class);

    // Reply
    $this->actingAs($user)
        ->postJson("/app/conversations/{$conv->id}/reply", [
            'content' => 'Hi, I can help.',
        ])
        ->assertOk();

    Event::assertDispatched(AgentReplyPostedEvent::class, function ($event) use ($conv) {
        return $event->conversationId === $conv->id
            && $event->content === 'Hi, I can help.';
    });

    // Release
    $this->actingAs($user)
        ->postJson("/app/conversations/{$conv->id}/release")
        ->assertOk();

    expect($conv->fresh()->claimed_by_user_id)->toBeNull();
});

test('cannot reply without claiming first', function () {
    ['user' => $user, 'workspace' => $workspace] = liveChatMember();
    $conv = liveChatConversation($workspace);

    $this->actingAs($user)
        ->postJson("/app/conversations/{$conv->id}/reply", ['content' => 'hi'])
        ->assertStatus(409);
});

test('a different operator cannot reply to a claimed conversation', function () {
    ['user' => $owner, 'workspace' => $workspace] = liveChatMember();
    $other = User::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $other->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $conv = liveChatConversation($workspace);

    $this->actingAs($owner)->postJson("/app/conversations/{$conv->id}/claim")->assertOk();

    $this->actingAs($other)
        ->postJson("/app/conversations/{$conv->id}/reply", ['content' => 'I want to butt in'])
        ->assertStatus(409);
});

test('workspaceIndex filter=needs_human only returns flagged-but-unclaimed conversations', function () {
    ['user' => $user, 'workspace' => $workspace] = liveChatMember();

    $needs = liveChatConversation($workspace, [
        'human_requested_at' => now(),
    ]);
    $claimed = liveChatConversation($workspace, [
        'human_requested_at' => now(),
        'claimed_by_user_id' => $user->id,
        'claimed_at' => now(),
    ]);
    $idle = liveChatConversation($workspace);

    $this->actingAs($user)
        ->get('/app/conversations?filter=needs_human&show=all')
        ->assertInertia(function (AssertableInertia $page) use ($needs, $claimed, $idle) {
            $ids = collect($page->toArray()['props']['conversations'])
                ->pluck('id')
                ->all();
            expect($ids)->toContain($needs->id);
            expect($ids)->not->toContain($claimed->id);
            expect($ids)->not->toContain($idle->id);
        });
});

test('workspaceIndex returns needs_human + live_now totals', function () {
    ['user' => $user, 'workspace' => $workspace] = liveChatMember();

    liveChatConversation($workspace, ['human_requested_at' => now()]);
    liveChatConversation($workspace, [
        'human_requested_at' => now(),
        'claimed_by_user_id' => $user->id,
        'claimed_at' => now(),
    ]);
    liveChatConversation($workspace);

    $this->actingAs($user)
        ->get('/app/conversations')
        ->assertInertia(function (AssertableInertia $page) {
            $page->where('totals.needs_human', 1)
                ->where('totals.live_now', 1);
        });
});
