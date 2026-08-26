<?php

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

/**
 * Client report 2026-05-23: a customer's workspace had a phantom agent
 * visible in /admin/agents but hidden from the customer's own
 * /app/agents because its workspace_id pointed at a workspace the
 * customer no longer had as their current context. Admin had no
 * delete action, so the orphan couldn't be removed. These tests
 * cover (a) super_admin can DELETE /admin/agents/{agent} for any
 * agent regardless of CurrentWorkspace, and (b) the route binding
 * bypasses WorkspaceScope so the same 404 the customer hit doesn't
 * block the admin.
 */
function superAdmin(): User
{
    return User::factory()->create(['role' => 'super_admin']);
}

function workspaceWithAgent(): array
{
    $owner = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $owner->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    return ['workspace' => $workspace, 'agent' => $agent, 'owner' => $owner];
}

test('super_admin can delete an agent from /admin/agents/{agent}', function () {
    $admin = superAdmin();
    ['agent' => $agent] = workspaceWithAgent();

    $response = $this->actingAs($admin)
        ->delete("/admin/agents/{$agent->id}");

    $response->assertRedirect(route('admin.agents.index'));
    // Platform destroy was migrated from soft-delete to hard-delete on
    // audit 2026-05-30 so it matches customer-side semantics and the
    // FK cascade actually fires (orphan rows no longer remain in
    // conversations / sources / leads / etc.). Assert the row is gone
    // entirely.
    $row = Agent::query()
        ->withoutGlobalScopes()
        ->find($agent->id);
    expect($row)->toBeNull();
});

test('destroy bypasses WorkspaceScope so an agent in any workspace is reachable', function () {
    $admin = superAdmin();
    // Give the admin a CurrentWorkspace context that does NOT match the
    // target agent's workspace — mirrors the production scenario where
    // CurrentWorkspace resolves to admin's own workspace while the
    // orphan lives in a customer's workspace.
    $adminWs = Workspace::factory()->create(['owner_user_id' => $admin->id]);
    $admin->forceFill(['default_workspace_id' => $adminWs->id])->save();
    WorkspaceUser::create([
        'workspace_id' => $adminWs->id,
        'user_id' => $admin->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    ['agent' => $agent] = workspaceWithAgent();

    $this->actingAs($admin)
        ->delete("/admin/agents/{$agent->id}")
        ->assertRedirect();

    $row = Agent::query()
        ->withoutGlobalScopes()
        ->find($agent->id);
    expect($row)->toBeNull();
});

test('non-admin cannot delete an agent via /admin/agents', function () {
    $regular = User::factory()->create(['role' => 'customer']);
    ['agent' => $agent] = workspaceWithAgent();

    $response = $this->actingAs($regular)
        ->delete("/admin/agents/{$agent->id}");

    // Either 403 (gate denied) or 404 (route hidden) is acceptable —
    // both express "this is not a customer-side affordance".
    expect(in_array($response->status(), [403, 404], true))->toBeTrue();
    $still = Agent::query()->withoutGlobalScopes()->find($agent->id);
    expect($still)->not->toBeNull();
});

test('platform agent PATCH still works regardless of CurrentWorkspace', function () {
    $admin = superAdmin();
    ['agent' => $agent] = workspaceWithAgent();

    $response = $this->actingAs($admin)
        ->patch("/admin/agents/{$agent->id}", ['name' => 'Renamed by admin']);

    $response->assertRedirect();
    $fresh = Agent::query()->withoutGlobalScopes()->find($agent->id);
    expect($fresh)->not->toBeNull();
    expect($fresh->name)->toBe('Renamed by admin');
});

test('destroy returns 404 for a non-existent agent id', function () {
    $admin = superAdmin();

    $this->actingAs($admin)
        ->delete('/admin/agents/01999999-9999-9999-9999-999999999999')
        ->assertNotFound();
});
