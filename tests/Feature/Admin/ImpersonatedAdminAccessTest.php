<?php

use App\Enums\PlatformRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

/**
 * Buyer report 2026-05-24: super-admin clicked "Impersonate" on a
 * customer to debug their billing screen, then tried to hop back to
 * `/admin/plans/create` to create a new plan in the same session.
 * EnsureSuperAdmin checked `auth()->user()` (the impersonated customer)
 * and 404'd. Now the middleware falls back to the impersonator stamped
 * on the session.
 */
function impersonationSetup(): array
{
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);
    $workspace = Workspace::factory()->create(['owner_user_id' => $customer->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $customer->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $customer->forceFill(['default_workspace_id' => $workspace->id])->save();

    return compact('admin', 'customer');
}

test('super-admin can reach /admin/* while impersonating a customer', function () {
    ['admin' => $admin, 'customer' => $customer] = impersonationSetup();

    $this->actingAs($customer)
        ->withSession(['impersonator_id' => $admin->id])
        ->get('/admin/plans/create')
        ->assertOk()
        ->assertInertia(fn ($p) => $p->component('admin/plans/create'));
});

test('regular customer (no impersonator session) still 404s on /admin/*', function () {
    ['customer' => $customer] = impersonationSetup();

    $this->actingAs($customer)
        ->get('/admin/plans/create')
        ->assertNotFound();
});

test('impersonator session set but not a super-admin does not unlock /admin', function () {
    ['customer' => $customer] = impersonationSetup();
    $randomNonAdmin = User::factory()->create(['role' => PlatformRole::Customer]);

    $this->actingAs($customer)
        ->withSession(['impersonator_id' => $randomNonAdmin->id])
        ->get('/admin/plans/create')
        ->assertNotFound();
});
