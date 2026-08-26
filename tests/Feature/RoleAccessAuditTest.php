<?php

use App\Enums\PlatformRole;
use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function ownerCustomer(): array
{
    $user = User::factory()->create(['role' => PlatformRole::Customer]);
    $ws = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $user->id,
        'role' => 'owner',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $ws->id])->save();

    return ['user' => $user, 'workspace' => $ws];
}

function superAdminWithWorkspace(): User
{
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    // Even with a workspace, super-admin shouldn't end up on customer surfaces.
    $ws = Workspace::factory()->create(['owner_user_id' => $admin->id]);
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $admin->id,
        'role' => 'owner',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $admin->forceFill(['default_workspace_id' => $ws->id])->save();

    return $admin;
}

// ── Customer surfaces redirect super-admin to /admin ────────────────

test('super-admin GET /dashboard redirects to /admin', function () {
    $admin = superAdminWithWorkspace();
    $this->actingAs($admin)->get('/dashboard')->assertRedirect('/admin');
});

test('super-admin GET /onboarding redirects to /admin', function () {
    $admin = superAdminWithWorkspace();
    $this->actingAs($admin)->get('/onboarding')->assertRedirect('/admin');
});

test('super-admin GET /app/agents redirects to /admin', function () {
    $admin = superAdminWithWorkspace();
    $this->actingAs($admin)->get('/app/agents')->assertRedirect('/admin');
});

test('super-admin GET /app/inbox redirects to /admin', function () {
    $admin = superAdminWithWorkspace();
    $this->actingAs($admin)->get('/app/inbox')->assertRedirect('/admin');
});

test('super-admin GET /app/analytics redirects to /admin', function () {
    $admin = superAdminWithWorkspace();
    $this->actingAs($admin)->get('/app/analytics')->assertRedirect('/admin');
});

test('super-admin GET /app/integrations redirects to /admin', function () {
    $admin = superAdminWithWorkspace();
    $this->actingAs($admin)->get('/app/integrations')->assertRedirect('/admin');
});

test('super-admin GET /app/members redirects to /admin', function () {
    $admin = superAdminWithWorkspace();
    $this->actingAs($admin)->get('/app/members')->assertRedirect('/admin');
});

test('super-admin GET /app/billing redirects to /admin (was /admin/subscriptions; now also caught earlier)', function () {
    $admin = superAdminWithWorkspace();
    // Either redirect target is acceptable — the point is they don't see /app/billing.
    $response = $this->actingAs($admin)->get('/app/billing');
    expect($response->getStatusCode())->toBe(302);
    expect($response->headers->get('Location'))->toMatch('#/admin#');
});

// ── Customers continue to work normally ─────────────────────────────

test('customer can access /dashboard', function () {
    ['user' => $user] = ownerCustomer();
    $this->actingAs($user)->get('/dashboard')->assertOk();
});

test('customer can access /app/agents', function () {
    ['user' => $user] = ownerCustomer();
    $this->actingAs($user)->get('/app/agents')->assertOk();
});

test('customer can access /app/billing', function () {
    ['user' => $user] = ownerCustomer();
    $this->actingAs($user)->get('/app/billing')->assertOk();
});

// ── Customers cannot reach admin surfaces (existing super_admin guard) ──

test('customer GET /admin returns 404', function () {
    ['user' => $user] = ownerCustomer();
    $this->actingAs($user)->get('/admin')->assertNotFound();
});

test('customer GET /admin/subscriptions returns 404', function () {
    ['user' => $user] = ownerCustomer();
    $this->actingAs($user)->get('/admin/subscriptions')->assertNotFound();
});

test('customer GET /admin/users returns 404', function () {
    ['user' => $user] = ownerCustomer();
    $this->actingAs($user)->get('/admin/users')->assertNotFound();
});

// ── Impersonation: when admin becomes a customer, customer surfaces work ──

test('after impersonation, the now-customer session can hit /dashboard normally', function () {
    $admin = superAdminWithWorkspace();
    ['user' => $customer, 'workspace' => $ws] = ownerCustomer();
    Agent::factory()->create(['workspace_id' => $ws->id]);

    // Start impersonation. Auth flips to the customer for subsequent requests.
    $this->actingAs($admin)
        ->post("/admin/impersonate/{$customer->id}/start")
        ->assertRedirect('/dashboard');

    // Now the auth identity is the customer — RedirectSuperAdmin shouldn't fire.
    $this->get('/dashboard')->assertOk();
    $this->get('/app/agents')->assertOk();
});

// ── Anonymous users redirected to login as before (no behavior change) ──

test('anonymous user GET /dashboard redirects to /login', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('anonymous user GET /admin redirects to /login (auth before super_admin)', function () {
    $this->get('/admin')->assertRedirect('/login');
});
