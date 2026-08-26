<?php

use App\Enums\PlatformRole;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function plansSeeded(): void
{
    foreach ([
        ['name' => 'Free', 'slug' => 'free', 'monthly_conversations' => 100, 'price_cents' => 0],
        ['name' => 'Standard', 'slug' => 'standard', 'monthly_conversations' => 500, 'price_cents' => 4900],
        ['name' => 'Pro', 'slug' => 'pro', 'monthly_conversations' => 3000, 'price_cents' => 24900],
    ] as $row) {
        Plan::query()->updateOrCreate(
            ['slug' => $row['slug']],
            [...$row, 'features' => [], 'is_active' => true],
        );
    }
}

function makeOwner(?string $planSlug = null): array
{
    plansSeeded();
    $plan = $planSlug ? Plan::query()->where('slug', $planSlug)->first() : null;
    $user = User::factory()->create(['role' => PlatformRole::Customer]);
    $workspace = Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'plan_id' => $plan?->id,
    ]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return ['user' => $user, 'workspace' => $workspace];
}

function makeSuperAdmin(): User
{
    plansSeeded();
    $user = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    // Super-admins still need a default workspace for the layout shell —
    // production reality is they often have one for testing.
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return $user;
}

test('super-admin hitting /app/billing is redirected to /admin', function () {
    // RedirectSuperAdmin catches the whole customer surface before
    // BillingController can run its own /admin/subscriptions redirect.
    // Either way they don't see the customer billing page.
    $admin = makeSuperAdmin();

    $this->actingAs($admin)
        ->get('/app/billing')
        ->assertRedirect('/admin');
});

test('customer owner can still access /app/billing normally', function () {
    ['user' => $user] = makeOwner('free');

    $this->actingAs($user)
        ->get('/app/billing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('app/billing'));
});

test('billingSummary shared prop is populated for regular customers', function () {
    ['user' => $user] = makeOwner('free');

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('billingSummary.limit', 100)
            ->where('billingSummary.used', 0));
});

test('non-admin gets 404 on /admin/subscriptions', function () {
    ['user' => $user] = makeOwner();

    $this->actingAs($user)
        ->get('/admin/subscriptions')
        ->assertNotFound();
});

test('super-admin can view /admin/subscriptions and gets MRR + plan rollup + workspace list', function () {
    $admin = makeSuperAdmin();
    // A few customer workspaces with paid plans so the rollup has data.
    makeOwner('standard');
    makeOwner('pro');
    makeOwner('free');

    $this->actingAs($admin)
        ->get('/admin/subscriptions')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/subscriptions/index')
            ->has('totals.mrr_cents')
            ->has('totals.active_count')
            ->has('totals.past_due_count')
            ->has('totals.total_workspaces')
            ->has('plans')
            ->has('workspaces')
        );
});

test('admin subscriptions page rolls up workspaces by plan correctly', function () {
    $admin = makeSuperAdmin();
    makeOwner('standard');
    makeOwner('standard');
    makeOwner('pro');

    $this->actingAs($admin)
        ->get('/admin/subscriptions')
        ->assertOk()
        ->assertInertia(function ($page) {
            // The "workspaces" rows should include all 3 customers (admin's
            // own workspace has plan_id=null so isn't surfaced).
            $page->where('workspaces', function ($workspaces) {
                $standard = collect($workspaces)->where('plan_slug', 'standard')->count();
                $pro = collect($workspaces)->where('plan_slug', 'pro')->count();
                expect($standard)->toBe(2);
                expect($pro)->toBe(1);

                return true;
            });
        });
});
