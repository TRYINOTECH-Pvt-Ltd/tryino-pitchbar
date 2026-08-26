<?php

use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Support\AuditLogger;

function auditUser(string $role = 'admin'): array
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

test('creating an agent writes an audit row', function () {
    ['user' => $user, 'workspace' => $workspace] = auditUser('admin');

    $this->actingAs($user)
        ->post('/app/agents', [
            'name' => 'Audit subject',
            'language_default' => 'en',
        ])
        ->assertRedirect();

    $row = AuditLog::query()->where('workspace_id', $workspace->id)
        ->where('action', 'agent.created')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->user_id)->toBe($user->id);
    expect($row->entity_type)->toBe('agent');
});

test('updating an agent writes an audit row with before+after', function () {
    ['user' => $user, 'workspace' => $workspace] = auditUser('admin');
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Old name',
    ]);

    $this->actingAs($user)
        ->patch("/app/agents/{$agent->id}", ['name' => 'New name'])
        ->assertRedirect();

    $row = AuditLog::query()->where('workspace_id', $workspace->id)
        ->where('action', 'agent.updated')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->before['name'])->toBe('Old name');
    expect($row->after['name'])->toBe('New name');
});

test('deleting an agent writes an audit row', function () {
    ['user' => $user, 'workspace' => $workspace] = auditUser('admin');
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->delete("/app/agents/{$agent->id}")
        ->assertRedirect();

    expect(
        AuditLog::query()
            ->where('workspace_id', $workspace->id)
            ->where('action', 'agent.deleted')
            ->exists()
    )->toBeTrue();
});

test('inviting a member writes member.invited row', function () {
    ['user' => $user, 'workspace' => $workspace] = auditUser('admin');

    $this->actingAs($user)
        ->post('/app/members', ['email' => 'newperson@example.com', 'role' => 'editor'])
        ->assertRedirect();

    $row = AuditLog::query()->where('workspace_id', $workspace->id)
        ->where('action', 'member.invited')
        ->first();

    expect($row)->not->toBeNull();
    expect($row->after['email'])->toBe('newperson@example.com');
});

test('audit log page lists rows for the workspace', function () {
    ['user' => $user, 'workspace' => $workspace] = auditUser('admin');

    AuditLogger::log(
        workspaceId: $workspace->id,
        action: 'agent.created',
        entityType: 'agent',
        entityId: 'agent-fake',
        after: ['name' => 'Visible row'],
    );

    $this->actingAs($user)
        ->get('/app/audit')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->has('rows', 1)
            ->where('rows.0.action', 'agent.created')
        );
});
