<?php

use App\Enums\PlatformRole;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function impersonationTarget(): User
{
    $target = User::factory()->create(['role' => PlatformRole::Customer]);
    // ImpersonateController refuses to impersonate users with no workspaces
    // (every /app/* page would 404 — pointless to impersonate into).
    $ws = Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $target->id,
        'role' => 'owner',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $target->forceFill(['default_workspace_id' => $ws->id])->save();

    return $target;
}

test('admin can start impersonating a customer; session swaps; audit row written', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $target = impersonationTarget();

    $response = $this->actingAs($admin)->post("/admin/impersonate/{$target->id}/start");

    $response->assertRedirect('/dashboard');
    expect(auth()->id())->toBe($target->id);
    expect(session('impersonator_id'))->toBe($admin->id);

    expect(AuditLog::query()->where('action', 'impersonate.start')->where('user_id', $admin->id)->exists())->toBeTrue();
});

test('dashboard receives impersonation context while the admin is acting as a customer', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $target = impersonationTarget();

    $this->actingAs($admin)->post("/admin/impersonate/{$target->id}/start");

    $this->get('/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('dashboard')
            ->where('auth.user.email', $target->email)
            ->where('impersonating.email', $admin->email)
            ->where('impersonating.name', $admin->name),
        );
});

test('stop impersonating returns to original admin and writes a stop audit row', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $target = impersonationTarget();

    $this->actingAs($admin)->post("/admin/impersonate/{$target->id}/start");
    expect(auth()->id())->toBe($target->id);

    $this->post('/impersonate/stop')->assertRedirect('/admin');
    expect(auth()->id())->toBe($admin->id);
    expect(session('impersonator_id'))->toBeNull();

    expect(AuditLog::query()->where('action', 'impersonate.stop')->exists())->toBeTrue();
});

test('admin cannot impersonate another super_admin', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $other = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)->post("/admin/impersonate/{$other->id}/start");

    expect(auth()->id())->toBe($admin->id); // unchanged
    expect(session('impersonator_id'))->toBeNull();
});

test('customer cannot start impersonation (middleware blocks with 404)', function () {
    $customer = User::factory()->create(['role' => PlatformRole::Customer]);
    $other = User::factory()->create(['role' => PlatformRole::Customer]);

    $this->actingAs($customer)
        ->post("/admin/impersonate/{$other->id}/start")
        ->assertNotFound();

    expect(auth()->id())->toBe($customer->id);
});
