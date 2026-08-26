<?php

use App\Events\Conversations\AgentReplyPostedEvent;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Widget\WidgetJwt;
use Illuminate\Support\Facades\Event;

function noteMember(): array
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

function noteConversation(Workspace $workspace): Conversation
{
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);

    return Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);
}

test('note persists with role=internal-note and is NOT broadcast to the visitor', function () {
    Event::fake([AgentReplyPostedEvent::class]);

    ['user' => $user, 'workspace' => $workspace] = noteMember();
    $conv = noteConversation($workspace);

    $this->actingAs($user)
        ->postJson("/app/conversations/{$conv->id}/note", [
            'content' => 'Visitor seems frustrated — handle gently.',
        ])
        ->assertOk();

    $note = Message::query()
        ->where('conversation_id', $conv->id)
        ->where('role', 'internal-note')
        ->first();

    expect($note)->not->toBeNull();
    expect($note->content)->toContain('frustrated');
    expect((string) $note->model)->toStartWith('note:');

    // Internal notes never fire the agent-reply broadcast — that's the
    // event the visitor's widget reacts to.
    Event::assertNotDispatched(AgentReplyPostedEvent::class);
});

test('visitor poll endpoint never returns internal-note rows', function () {
    ['user' => $user, 'workspace' => $workspace] = noteMember();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    // Add a note via the operator endpoint.
    $this->actingAs($user)
        ->postJson("/app/conversations/{$conv->id}/note", [
            'content' => 'Internal handoff context — never visible to visitor.',
        ])
        ->assertOk();

    // Visitor poll: must not see the note.
    $jwt = app(WidgetJwt::class)->issue($agent->id, $visitor->id, $conv->id);
    $body = $this->withHeaders([
        'Authorization' => "Bearer {$jwt['token']}",
        'Origin' => 'https://example.com',
    ])
        ->get('/api/v1/widget/conversation/messages')
        ->assertOk()
        ->json('data');

    expect($body['messages'])->toBe([]);
});

test('cannot post a note without claiming or having permission', function () {
    ['user' => $user] = noteMember();

    // Conversation in a foreign workspace.
    $foreignWorkspace = Workspace::factory()->create();
    $conv = noteConversation($foreignWorkspace);

    $this->actingAs($user)
        ->postJson("/app/conversations/{$conv->id}/note", [
            'content' => 'I do not belong here.',
        ])
        ->assertStatus(403);
});
