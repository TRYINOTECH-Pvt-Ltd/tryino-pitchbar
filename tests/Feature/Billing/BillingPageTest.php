<?php

use App\Models\Plan;
use App\Models\UsageEvent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Str;

function billingTestUser(string $role = 'owner', ?string $planSlug = 'free'): array
{
    foreach ([
        ['name' => 'Free', 'slug' => 'free', 'monthly_conversations' => 100, 'price_cents' => 0],
        ['name' => 'Standard', 'slug' => 'standard', 'monthly_conversations' => 500, 'price_cents' => 4900],
        ['name' => 'Pro', 'slug' => 'pro', 'monthly_conversations' => 3000, 'price_cents' => 24900],
        ['name' => 'Custom', 'slug' => 'custom', 'monthly_conversations' => 0, 'price_cents' => 0],
    ] as $row) {
        Plan::query()->updateOrCreate(
            ['slug' => $row['slug']],
            [...$row, 'features' => [], 'is_active' => true],
        );
    }

    $plan = $planSlug ? Plan::query()->where('slug', $planSlug)->first() : null;
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'plan_id' => $plan?->id,
    ]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return ['user' => $user, 'workspace' => $workspace, 'plan' => $plan];
}

test('owner can view billing page with plans + summary', function () {
    ['user' => $user, 'workspace' => $workspace] = billingTestUser('owner', 'free');

    UsageEvent::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'kind' => 'conversation',
        'quantity' => 25,
        'occurred_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/app/billing')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('app/billing')
            ->has('plans', 4)
            ->where('summary.used', 25)
            ->where('summary.limit', 100)
            ->where('summary.plan.slug', 'free')
        );
});

test('viewer cannot view billing page', function () {
    ['user' => $user] = billingTestUser('viewer', 'free');

    $this->actingAs($user)
        ->get('/app/billing')
        ->assertForbidden();
});

test('billing summary is shared on every Inertia response so the banner can show it', function () {
    ['user' => $user, 'workspace' => $workspace] = billingTestUser('owner', 'free');

    UsageEvent::create([
        'id' => (string) Str::uuid(),
        'workspace_id' => $workspace->id,
        'kind' => 'conversation',
        'quantity' => 90,
        'occurred_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertInertia(fn ($page) => $page
            ->where('billingSummary.used', 90)
            ->where('billingSummary.limit', 100)
            ->where('billingSummary.percent', 90)
        );
});

test('billing page normalizes object-based plan feature flags for display', function () {
    ['user' => $user] = billingTestUser('owner', 'standard');

    Plan::query()->where('slug', 'standard')->update([
        'features' => ['remove_branding' => true],
    ]);

    $response = $this->actingAs($user)
        ->get('/app/billing')
        ->assertOk();

    $plans = collect($response->viewData('page')['props']['plans']);

    expect($plans->firstWhere('slug', 'standard')['features'])
        ->toBe(['Remove Pitchbar branding']);
});

test('billing page rebrands "Remove <brand> branding" feature when site_title differs', function () {
    config(['branding.site_title' => 'RepliBar']);

    ['user' => $user] = billingTestUser('owner', 'standard');

    Plan::query()->where('slug', 'standard')->update([
        'features' => ['remove_branding' => true],
    ]);

    $response = $this->actingAs($user)
        ->get('/app/billing')
        ->assertOk();

    $plans = collect($response->viewData('page')['props']['plans']);

    expect($plans->firstWhere('slug', 'standard')['features'])
        ->toBe(['Remove RepliBar branding']);
});
