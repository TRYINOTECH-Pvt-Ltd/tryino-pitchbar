<?php

use App\Enums\PlatformRole;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

/**
 * Regression suite for v3 buyer-batch follow-ups:
 *
 *   - Inbox lead status PATCH actually changes the row (was always 'new')
 *   - Setup-progress "Validate in playground" marker flips on
 *     ANY playground conversation, not just whereHas('messages')
 *   - Admin can edit user name/email inline (was promote/byok only)
 *   - Admin can rename + toggle published on an agent
 *   - Admin conversation transcript page returns messages
 */
function batchV3SuperAdmin(): User
{
    return User::factory()->create([
        'email' => 'super-v3@example.com',
        'role' => PlatformRole::SuperAdmin,
    ]);
}

function batchV3CustomerOwner(): array
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

test('inbox PATCH /app/inbox/{lead} updates lead status', function () {
    ['user' => $user, 'workspace' => $workspace] = batchV3CustomerOwner();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $lead = Lead::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'new',
    ]);

    $this->actingAs($user)
        ->patch("/app/inbox/{$lead->id}", ['status' => 'qualified'])
        ->assertRedirect();

    expect($lead->fresh()->status)->toBe('qualified');
});

test('agent setup has_messages flips on a playground conversation alone', function () {
    ['user' => $user, 'workspace' => $workspace] = batchV3CustomerOwner();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    // Playground conversation with ZERO messages — mirrors the real
    // production path where PlaygroundStreamController intentionally
    // skips message persistence.
    Conversation::factory()->create([
        'agent_id' => $agent->id,
        'is_playground' => true,
    ]);

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}")
        ->assertInertia(fn ($p) => $p->where('setup.has_messages', true));
});

test('admin update user: PATCH /admin/users/{user} edits name + email', function () {
    $admin = batchV3SuperAdmin();
    $target = User::factory()->create([
        'name' => 'Old Name',
        'email' => 'old@example.com',
    ]);

    $this->actingAs($admin)
        ->patch("/admin/users/{$target->id}", [
            'name' => 'New Name',
            'email' => 'new@example.com',
        ])
        ->assertRedirect('/admin/users');

    $target->refresh();
    expect($target->name)->toBe('New Name');
    expect($target->email)->toBe('new@example.com');
});

test('admin update user: email uniqueness is enforced', function () {
    $admin = batchV3SuperAdmin();
    $a = User::factory()->create(['email' => 'a@example.com']);
    $b = User::factory()->create(['email' => 'b@example.com']);

    $this->actingAs($admin)
        ->patch("/admin/users/{$b->id}", [
            'name' => $b->name,
            'email' => 'a@example.com',
        ])
        ->assertSessionHasErrors('email');

    expect($b->fresh()->email)->toBe('b@example.com');
});

test('admin update agent: PATCH /admin/agents/{agent} renames + toggles publish', function () {
    $admin = batchV3SuperAdmin();
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Bot 9000',
        'is_published' => false,
    ]);

    $this->actingAs($admin)
        ->patch("/admin/agents/{$agent->id}", [
            'name' => 'Bot 9001',
            'is_published' => true,
        ])
        ->assertRedirect('/admin/agents');

    $agent->refresh();
    expect($agent->name)->toBe('Bot 9001');
    expect((bool) $agent->is_published)->toBeTrue();
});

test('admin conversation show renders the transcript', function () {
    $admin = batchV3SuperAdmin();
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $conversation = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'is_playground' => false,
    ]);
    Message::factory()->count(3)->create([
        'conversation_id' => $conversation->id,
    ]);

    $response = $this->actingAs($admin)
        ->get("/admin/conversations/{$conversation->id}");

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->component('admin/conversations/show')
        ->where('conversation.id', $conversation->id)
        ->has('conversation.messages', 3));
});
