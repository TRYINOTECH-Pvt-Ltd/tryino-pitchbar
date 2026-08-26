<?php

use App\Enums\PlatformRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function orphanCustomer(): User
{
    return User::factory()->create([
        'role' => PlatformRole::Customer,
        'default_workspace_id' => null,
    ]);
}

test('orphan customer hitting /app/agents is redirected to /dashboard with a friendly flash', function () {
    $user = orphanCustomer();

    $this->actingAs($user)
        ->get('/app/agents')
        ->assertRedirect('/dashboard')
        ->assertSessionHas('error');
});

test('orphan customer hitting /app/billing is also redirected to /dashboard', function () {
    $user = orphanCustomer();

    $this->actingAs($user)
        ->get('/app/billing')
        ->assertRedirect('/dashboard');
});

test('/dashboard itself stays accessible to orphan users (renders empty state)', function () {
    $user = orphanCustomer();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk();
});

test('user whose default_workspace_id points to a stale workspace is healed to first valid membership', function () {
    $user = User::factory()->create(['role' => PlatformRole::Customer]);
    $stale = Workspace::factory()->create();
    // Pin the stale workspace as default but DO NOT add membership.
    $user->forceFill(['default_workspace_id' => $stale->id])->save();

    // Real workspace they're a member of.
    $real = Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $real->id,
        'user_id' => $user->id,
        'role' => 'owner',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    // Hitting /app/agents should NOT 404; the middleware finds the real
    // workspace via membership and heals default_workspace_id.
    $this->actingAs($user)->get('/app/agents')->assertOk();

    expect($user->fresh()->default_workspace_id)->toBe($real->id);
});

test('user with workspace memberships but no default_workspace_id auto-binds the first one', function () {
    $user = User::factory()->create([
        'role' => PlatformRole::Customer,
        'default_workspace_id' => null,
    ]);
    $ws = Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $user->id,
        'role' => 'editor',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $this->actingAs($user)->get('/app/agents')->assertOk();

    expect($user->fresh()->default_workspace_id)->toBe($ws->id);
});

test('super-admin cannot impersonate a user with zero workspaces', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $orphan = orphanCustomer();

    $this->actingAs($admin)
        ->from('/admin/users')
        ->post("/admin/impersonate/{$orphan->id}/start")
        ->assertRedirect()
        ->assertSessionHas('error');

    // The session should NOT have flipped to the orphan user.
    expect(auth()->id())->toBe($admin->id);
});

test('super-admin can still impersonate a user with at least one workspace', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);
    $ws = Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $customer->id,
        'role' => 'owner',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $customer->forceFill(['default_workspace_id' => $ws->id])->save();

    $this->actingAs($admin)
        ->post("/admin/impersonate/{$customer->id}/start")
        ->assertRedirect('/dashboard');

    expect(auth()->id())->toBe($customer->id);
});
