<?php

use App\Actions\Workspaces\CreateWorkspaceForUser;
use App\Enums\PlatformRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Carbon\CarbonInterface;

use function Pest\Laravel\actingAs;

/**
 * Create a customer who owns a workspace on the given plan, with a
 * PlanSubscription in the given status/window, and that workspace set as
 * their current (default) workspace.
 *
 * @return array{user: User, workspace: Workspace}
 */
function trialMember(Plan $plan, string $status, CarbonInterface $periodEnd): array
{
    $user = User::factory()->create(['role' => PlatformRole::Customer]);
    $workspace = Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'plan_id' => $plan->id,
    ]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'owner',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    PlanSubscription::factory()->create([
        'workspace_id' => $workspace->id,
        'plan_id' => $plan->id,
        'status' => $status,
        'current_period_end' => $periodEnd,
    ]);

    return ['user' => $user, 'workspace' => $workspace->fresh()];
}

// ─── Sign-up stamps the trial window ──────────────────────────────────

it('starts a trialing subscription when the default signup plan is a trial', function () {
    Plan::query()->delete();
    Plan::factory()->create([
        'is_default_for_signup' => true,
        'is_trial' => true,
        'trial_days' => 7,
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceForUser::class)->handle($user);

    $sub = PlanSubscription::query()->withoutGlobalScopes()
        ->where('workspace_id', $workspace->id)->firstOrFail();

    expect($sub->status)->toBe('trialing');
    expect($sub->current_period_end->isBetween(now()->addDays(6), now()->addDays(8)))->toBeTrue();
});

it('keeps the open-ended active window for a non-trial default plan', function () {
    Plan::query()->delete();
    Plan::factory()->create([
        'is_default_for_signup' => true,
        'is_trial' => false,
    ]);

    $user = User::factory()->create();
    $workspace = app(CreateWorkspaceForUser::class)->handle($user);

    $sub = PlanSubscription::query()->withoutGlobalScopes()
        ->where('workspace_id', $workspace->id)->firstOrFail();

    expect($sub->status)->toBe('active');
    expect($sub->current_period_end->greaterThan(now()->addWeeks(3)))->toBeTrue();
});

it('falls back to 14 days when a trial plan has no explicit length', function () {
    $plan = Plan::factory()->create(['is_trial' => true, 'trial_days' => null]);

    expect($plan->trialLengthInDays())->toBe(14);
});

// ─── Workspace trial helpers ──────────────────────────────────────────

it('reports an in-progress trial as active, not expired', function () {
    $plan = Plan::factory()->create(['is_trial' => true, 'trial_days' => 14]);
    ['workspace' => $workspace] = trialMember($plan, 'trialing', now()->addDays(10));

    expect($workspace->onTrial())->toBeTrue();
    expect($workspace->trialExpired())->toBeFalse();
    expect($workspace->trialDaysLeft())->toBe(10);
});

it('reports a lapsed trial as expired with zero days left', function () {
    $plan = Plan::factory()->create(['is_trial' => true, 'trial_days' => 14]);
    ['workspace' => $workspace] = trialMember($plan, 'trialing', now()->subDay());

    expect($workspace->trialExpired())->toBeTrue();
    expect($workspace->onTrial())->toBeFalse();
    expect($workspace->trialDaysLeft())->toBe(0);
});

it('treats an upgraded workspace as not trial-expired even past the original trial date', function () {
    $plan = Plan::factory()->create(['is_trial' => true, 'trial_days' => 14]);
    ['workspace' => $workspace] = trialMember($plan, 'trialing', now()->subDay());

    // They upgraded — the single per-workspace row flips trialing → active
    // (unique workspace_id means there's never a second row).
    PlanSubscription::query()->withoutGlobalScopes()
        ->where('workspace_id', $workspace->id)
        ->update(['status' => 'active', 'current_period_end' => now()->addMonth()]);

    expect($workspace->fresh()->trialExpired())->toBeFalse();
    expect($workspace->fresh()->hasActivePaidAccess())->toBeTrue();
});

// ─── The wall middleware ──────────────────────────────────────────────

it('redirects an expired-trial workspace to billing when it hits an app surface', function () {
    $plan = Plan::factory()->create(['is_trial' => true, 'trial_days' => 14]);
    ['user' => $user] = trialMember($plan, 'trialing', now()->subDay());

    actingAs($user)->get('/app/agents')->assertRedirect(route('billing.show'));
});

it('lets an active-trial workspace through to app surfaces', function () {
    $plan = Plan::factory()->create(['is_trial' => true, 'trial_days' => 14]);
    ['user' => $user] = trialMember($plan, 'trialing', now()->addDays(5));

    actingAs($user)->get('/app/agents')->assertOk();
});

it('keeps the billing page reachable for an expired-trial workspace so they can upgrade', function () {
    $plan = Plan::factory()->create(['is_trial' => true, 'trial_days' => 14]);
    ['user' => $user] = trialMember($plan, 'trialing', now()->subDay());

    actingAs($user)->get('/app/billing')->assertOk();
});

// ─── Admin plan configuration ─────────────────────────────────────────

it('lets a super_admin create a trial plan with a length', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    actingAs($admin)->post('/admin/plans', [
        'name' => 'Trial',
        'monthly_conversations' => 500,
        'price_cents' => 0,
        'is_trial' => true,
        'trial_days' => 30,
    ])->assertRedirect();

    $plan = Plan::query()->where('name', 'Trial')->firstOrFail();
    expect($plan->is_trial)->toBeTrue();
    expect($plan->trial_days)->toBe(30);
});

it('clears trial_days when a plan is saved without the trial flag', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $plan = Plan::factory()->create(['is_trial' => true, 'trial_days' => 14]);

    actingAs($admin)->patch("/admin/plans/{$plan->id}", [
        'name' => $plan->name,
        'monthly_conversations' => 500,
        'price_cents' => 0,
        'is_trial' => false,
        'trial_days' => 30,
    ])->assertRedirect();

    expect($plan->fresh()->trial_days)->toBeNull();
    expect($plan->fresh()->is_trial)->toBeFalse();
});
