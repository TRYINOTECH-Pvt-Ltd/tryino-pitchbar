<?php

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;

/**
 * Client report 2026-05-23: a user already paying for a Pro plan
 * created a second workspace and it silently arrived on the Free
 * plan. Expectation is that the second workspace inherits the
 * owner's paid plan — the buyer already paid for the seat.
 *
 * The fix lives in CreateWorkspaceForUser::handle()'s optional
 * `inheritFrom` parameter + WorkspaceController::store passing the
 * user's oldest workspace as the source.
 */
test('second workspace inherits the owner primary workspace plan', function () {
    $paidPlan = Plan::create([
        'name' => 'Pro',
        'slug' => 'pro-'.bin2hex(random_bytes(3)),
        'monthly_conversations' => 10000,
        'price_cents' => 4900,
        'workspaces_limit' => null,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'plan_id' => $paidPlan->id,
    ]);

    $this->actingAs($user)
        ->post(route('workspaces.store'), ['name' => 'Second Workspace'])
        ->assertRedirect(route('dashboard'));

    $second = Workspace::query()
        ->withoutGlobalScopes()
        ->where('owner_user_id', $user->id)
        ->where('name', 'Second Workspace')
        ->first();

    expect($second)->not->toBeNull();
    expect($second->plan_id)->toBe($paidPlan->id);
});

test('second workspace inherits lifetime plan status from primary', function () {
    $lifetimePlan = Plan::create([
        'name' => 'Lifetime Pro',
        'slug' => 'ltd-'.bin2hex(random_bytes(3)),
        'monthly_conversations' => 50000,
        'price_cents' => 49900,
        'billing_model' => 'one_time',
        'is_active' => true,
    ]);
    $monthlyPlan = Plan::create([
        'name' => 'Pro Monthly',
        'slug' => 'monthly-'.bin2hex(random_bytes(3)),
        'monthly_conversations' => 10000,
        'price_cents' => 4900,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'plan_id' => $monthlyPlan->id,
        'lifetime_plan_id' => $lifetimePlan->id,
        'lifetime_purchased_at' => now()->subMonth(),
    ]);

    $this->actingAs($user)
        ->post(route('workspaces.store'), ['name' => 'Second LTD'])
        ->assertRedirect(route('dashboard'));

    $second = Workspace::query()
        ->withoutGlobalScopes()
        ->where('owner_user_id', $user->id)
        ->where('name', 'Second LTD')
        ->first();

    expect($second->plan_id)->toBe($monthlyPlan->id);
    expect($second->lifetime_plan_id)->toBe($lifetimePlan->id);
    expect($second->lifetime_purchased_at)->not->toBeNull();
});

test('first workspace via action falls back to default Free plan when no inheritFrom given', function () {
    Plan::create([
        'name' => 'Free',
        'slug' => 'free-default-'.bin2hex(random_bytes(3)),
        'monthly_conversations' => 100,
        'price_cents' => 0,
        'is_default_for_signup' => true,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceForUser::class)->handle($user);

    expect($workspace->plan_id)->not->toBeNull();
    $plan = Plan::find($workspace->plan_id);
    expect($plan->is_default_for_signup)->toBeTrue();
});

test('inheritFrom with null plan_id falls back to default plan lookup', function () {
    Plan::create([
        'name' => 'Free',
        'slug' => 'free-empty-'.bin2hex(random_bytes(3)),
        'monthly_conversations' => 100,
        'price_cents' => 0,
        'is_default_for_signup' => true,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $orphan = Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'plan_id' => null,
    ]);

    $workspace = app(CreateWorkspaceForUser::class)->handle($user, 'Inherits Default', $orphan);

    expect($workspace->plan_id)->not->toBeNull();
    $plan = Plan::find($workspace->plan_id);
    expect($plan->is_default_for_signup)->toBeTrue();
});

test('preferred_currency carries over from primary to second workspace', function () {
    $plan = Plan::create([
        'name' => 'EUR Pro',
        'slug' => 'eur-pro-'.bin2hex(random_bytes(3)),
        'monthly_conversations' => 10000,
        'price_cents' => 4900,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'plan_id' => $plan->id,
        'preferred_currency' => 'EUR',
    ]);

    $this->actingAs($user)
        ->post(route('workspaces.store'), ['name' => 'EUR Second'])
        ->assertRedirect(route('dashboard'));

    $second = Workspace::query()
        ->withoutGlobalScopes()
        ->where('owner_user_id', $user->id)
        ->where('name', 'EUR Second')
        ->first();

    expect($second->preferred_currency)->toBe('EUR');
});
