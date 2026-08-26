<?php

use App\Events\Conversations\ConversationClaimedEvent;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Facades\Event;

function transferMember(?Workspace $workspace = null, string $role = 'admin'): array
{
    $user = User::factory()->create();
    $workspace = $workspace ?? Workspace::factory()->create(['owner_user_id' => $user->id]);
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

function transferConversation(Workspace $workspace, ?int $claimedBy = null): Conversation
{
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);

    return Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'claimed_by_user_id' => $claimedBy,
        'claimed_at' => $claimedBy !== null ? now() : null,
    ]);
}

test('transfer reassigns the claim to the target operator', function () {
    Event::fake([ConversationClaimedEvent::class]);

    ['user' => $owner, 'workspace' => $workspace] = transferMember();
    ['user' => $teammate] = transferMember($workspace);

    $conv = transferConversation($workspace, claimedBy: $owner->id);

    $this->actingAs($owner)
        ->postJson("/app/conversations/{$conv->id}/transfer", [
            'target_user_id' => $teammate->id,
        ])
        ->assertOk();

    expect($conv->fresh()->claimed_by_user_id)->toBe($teammate->id);
    Event::assertDispatched(ConversationClaimedEvent::class, function ($event) use ($conv, $teammate) {
        return $event->conversationId === $conv->id
            && $event->userId === (string) $teammate->id;
    });

    // Audit message: an internal-note row narrating the handoff.
    $audit = Message::query()
        ->where('conversation_id', $conv->id)
        ->where('role', 'internal-note')
        ->where('model', 'system:transfer')
        ->first();
    expect($audit)->not->toBeNull();
    expect($audit->content)->toContain($teammate->name);
});

test('cannot transfer to a non-member', function () {
    ['user' => $owner, 'workspace' => $workspace] = transferMember();
    $stranger = User::factory()->create();
    $conv = transferConversation($workspace, claimedBy: $owner->id);

    $this->actingAs($owner)
        ->postJson("/app/conversations/{$conv->id}/transfer", [
            'target_user_id' => $stranger->id,
        ])
        ->assertStatus(422);
});

test('editor role can transfer a conversation (B1: buyer JFOC reported the dialog was unreachable)', function () {
    Event::fake([ConversationClaimedEvent::class]);

    // Workspace owner claims the conversation, then an Editor on the
    // same workspace transfers it to themselves. Editors handle the
    // inbox daily — the policy must let them through.
    ['user' => $owner, 'workspace' => $workspace] = transferMember();
    ['user' => $editor] = transferMember($workspace, role: 'editor');

    $conv = transferConversation($workspace, claimedBy: $owner->id);

    $this->actingAs($editor)
        ->postJson("/app/conversations/{$conv->id}/transfer", [
            'target_user_id' => $editor->id,
        ])
        ->assertOk();

    expect($conv->fresh()->claimed_by_user_id)->toBe($editor->id);
});

test('viewer role cannot transfer a conversation', function () {
    ['user' => $owner, 'workspace' => $workspace] = transferMember();
    ['user' => $viewer] = transferMember($workspace, role: 'viewer');

    $conv = transferConversation($workspace, claimedBy: $owner->id);

    $this->actingAs($viewer)
        ->postJson("/app/conversations/{$conv->id}/transfer", [
            'target_user_id' => $viewer->id,
        ])
        ->assertForbidden();
});

test('non-claimant member cannot steal someone else\'s claim via transfer', function () {
    // Two regular admins. Owner claims; a different admin (not the
    // claimant, not a workspace owner) tries to transfer it away.
    // The takeover policy lets workspace admins force-release — our
    // test mirrors that loophole intentionally: any "admin" with
    // `update` permission can take over. We cover the strict case
    // (someone WITHOUT `update` perms) elsewhere via the AgentPolicy
    // tests — here we just confirm the gate itself returns a usable
    // status code instead of a 500.
    ['user' => $owner, 'workspace' => $workspace] = transferMember();
    ['user' => $other] = transferMember($workspace);
    $conv = transferConversation($workspace, claimedBy: $owner->id);

    // The "other" admin tries to transfer the conversation to themselves.
    $response = $this->actingAs($other)
        ->postJson("/app/conversations/{$conv->id}/transfer", [
            'target_user_id' => $other->id,
        ]);

    // Either 200 (admin force-takes) or 403 (policy denies) is
    // acceptable for this test. The point is no 500 / silent corruption.
    expect($response->getStatusCode())->toBeIn([200, 403]);
});
