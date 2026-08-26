<?php

use App\Enums\PlatformRole;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\TurnTrace;
use App\Models\User;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function debuggerSuperAdmin(): User
{
    $user = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return $user;
}

function debuggerConversationWithTrace(): Conversation
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
    ]);

    TurnTrace::query()->create([
        'agent_id' => $agent->id,
        'conversation_id' => $conversation->id,
        'message_id' => null,
        'kind' => 'error',
        'payload' => [
            'user_message' => 'broken turn',
            'error' => ['code' => 'llm_failed', 'message' => 'quota'],
        ],
        'created_at' => now(),
    ]);

    return $conversation;
}

test('super_admin sees turn traces on the platform conversation page', function () {
    $admin = debuggerSuperAdmin();
    $conversation = debuggerConversationWithTrace();

    $this->actingAs($admin)
        ->get("/admin/conversations/{$conversation->id}")
        ->assertOk()
        ->assertInertia(
            fn ($page) => $page
                ->component('admin/conversations/show')
                ->has('traces', 1)
                ->where('traces.0.kind', 'error')
                ->where('traces.0.payload.error.code', 'llm_failed'),
        );
});

test('workspace customer cannot reach the platform conversation debugger', function () {
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

    $conversation = debuggerConversationWithTrace();

    $response = $this->actingAs($user)->get("/admin/conversations/{$conversation->id}");

    expect($response->status())->toBeIn([302, 403, 404]);
});
