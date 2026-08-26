<?php

use App\Enums\PlatformRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function authSuperAdmin(): User
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

function authCustomer(): User
{
    $user = User::factory()->create(['role' => PlatformRole::Customer]);
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

test('super_admin can access /admin/pages', function () {
    $admin = authSuperAdmin();

    $this->actingAs($admin)
        ->get('/admin/pages')
        ->assertOk();
});

test('customer-role user cannot access /admin/pages', function () {
    $customer = authCustomer();

    $response = $this->actingAs($customer)->get('/admin/pages');

    expect($response->status())->toBeIn([302, 403, 404]);
});

test('guest cannot access /admin/pages', function () {
    $this->get('/admin/pages')->assertRedirect('/login');
});

test('customer sidebar does not include any /admin/* link', function () {
    // The customer-facing AppSidebar component shouldn't render any
    // /admin/* navigation entry. If a future commit accidentally adds
    // one, this guard fires before the UI ships.
    $sidebar = file_get_contents(resource_path('js/components/app-sidebar.tsx'));

    $matches = [];
    preg_match_all("#'(/admin/[^']*)'#", $sidebar, $matches);

    expect($matches[1])->toBe([]);
});
