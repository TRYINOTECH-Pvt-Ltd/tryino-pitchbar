<?php

use App\Models\Agent;
use App\Models\IntegrationConnection;
use App\Models\Invitation;
use App\Models\Plan;
use App\Models\Source;
use App\Models\User;
use App\Models\WebhookSubscription;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Models\WorkspaceApiToken;
use App\Models\WorkspaceUser;
use App\Services\Billing\PlanLimits;

function asPlanMember(Plan $plan, string $role = 'owner'): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'plan_id' => $plan->id,
    ]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => $role,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return ['user' => $user, 'workspace' => $workspace];
}

/**
 * Regression suite for the per-resource plan limits. Verifies:
 *
 * - NULL limit columns mean "unlimited" (back-compat with pre-1.3 plans
 *   that don't have the new dials set).
 * - 0 means "hard block".
 * - Positive integer caps work both at and below the limit.
 * - Each controller actually consults PlanLimits.
 */
beforeEach(function () {
    Plan::query()->delete();
});

function planWithLimits(array $overrides = []): Plan
{
    return Plan::create(array_merge([
        'name' => 'Test',
        'slug' => 'test-'.bin2hex(random_bytes(3)),
        'monthly_conversations' => 1000,
        'price_cents' => 0,
        'is_active' => true,
    ], $overrides));
}

test('null limit means unlimited', function () {
    $plan = planWithLimits(['agents_limit' => null]);
    $workspace = Workspace::factory()->create(['plan_id' => $plan->id]);

    $limits = app(PlanLimits::class);

    Agent::factory()->count(50)->create(['workspace_id' => $workspace->id]);

    expect($limits->canCreateAgent($workspace))->toBeTrue();
});

test('zero limit hard-blocks the feature', function () {
    $plan = planWithLimits(['integrations_limit' => 0]);
    $workspace = Workspace::factory()->create(['plan_id' => $plan->id]);

    $limits = app(PlanLimits::class);
    expect($limits->canCreateIntegration($workspace))->toBeFalse();

    $check = $limits->check($workspace, PlanLimits::RESOURCE_INTEGRATION);
    expect($check['allowed'])->toBeFalse();
    expect($check['limit'])->toBe(0);
});

test('agents_limit caps creation at the configured count', function () {
    $plan = planWithLimits(['agents_limit' => 2]);
    $workspace = Workspace::factory()->create(['plan_id' => $plan->id]);

    $limits = app(PlanLimits::class);

    Agent::factory()->create(['workspace_id' => $workspace->id]);
    expect($limits->canCreateAgent($workspace))->toBeTrue();

    Agent::factory()->create(['workspace_id' => $workspace->id]);
    expect($limits->canCreateAgent($workspace))->toBeFalse();
});

test('soft-deleted agents do NOT count toward the agent plan limit', function () {
    // Client report 2026-05-22: customer on a Free plan (1 agent max)
    // had a single live agent but the banner showed "2 of 1 used"
    // because countAgents used withoutGlobalScopes(), which strips the
    // SoftDeletingScope alongside WorkspaceScope. Trashed rows tallied
    // against the quota.
    $plan = planWithLimits(['agents_limit' => 1]);
    $workspace = Workspace::factory()->create(['plan_id' => $plan->id]);

    $live = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $trashed = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $trashed->delete();

    $limits = app(PlanLimits::class);
    $check = $limits->check($workspace, 'agent');

    expect($check['current'])->toBe(1);
    expect($check['limit'])->toBe(1);
    expect($check['allowed'])->toBeFalse();
    // Sanity: trashed row is still in the DB, just not counted.
    expect(Agent::withTrashed()
        ->withoutGlobalScopes(['App\\Scopes\\WorkspaceScope'])
        ->where('workspace_id', $workspace->id)
        ->count())->toBe(2);
    unset($live);
});

test('sources_limit counts every source under the workspace agents', function () {
    $plan = planWithLimits(['sources_limit' => 3]);
    $workspace = Workspace::factory()->create(['plan_id' => $plan->id]);
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    Source::factory()->count(3)->create(['agent_id' => $agent->id]);

    $limits = app(PlanLimits::class);
    expect($limits->canCreateSource($workspace))->toBeFalse();
});

test('integrations_limit counts BOTH connections and webhook subscriptions', function () {
    $plan = planWithLimits(['integrations_limit' => 2]);
    $workspace = Workspace::factory()->create(['plan_id' => $plan->id]);

    IntegrationConnection::factory()->create(['workspace_id' => $workspace->id]);
    WebhookSubscription::factory()->create(['workspace_id' => $workspace->id]);

    $limits = app(PlanLimits::class);
    expect($limits->canCreateIntegration($workspace))->toBeFalse();
});

test('members_limit counts accepted seats + pending invitations', function () {
    $plan = planWithLimits(['members_limit' => 3]);
    $workspace = Workspace::factory()->create(['plan_id' => $plan->id]);

    $u = User::factory()->create();
    WorkspaceUser::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $u->id,
    ]);

    Invitation::create([
        'workspace_id' => $workspace->id,
        'email' => 'a@example.com',
        'role' => 'viewer',
        'token' => 't1'.bin2hex(random_bytes(8)),
        'expires_at' => now()->addDays(7),
        'invited_by_user_id' => $u->id,
    ]);
    Invitation::create([
        'workspace_id' => $workspace->id,
        'email' => 'b@example.com',
        'role' => 'viewer',
        'token' => 't2'.bin2hex(random_bytes(8)),
        'expires_at' => now()->addDays(7),
        'invited_by_user_id' => $u->id,
    ]);

    $limits = app(PlanLimits::class);
    $check = $limits->check($workspace, PlanLimits::RESOURCE_MEMBER);
    expect($check['current'])->toBe(3);
    expect($check['allowed'])->toBeFalse();
});

test('expired invitations do NOT count toward member limit', function () {
    $plan = planWithLimits(['members_limit' => 1]);
    $workspace = Workspace::factory()->create(['plan_id' => $plan->id]);
    $u = User::factory()->create();

    Invitation::create([
        'workspace_id' => $workspace->id,
        'email' => 'expired@example.com',
        'role' => 'viewer',
        'token' => 'exp'.bin2hex(random_bytes(8)),
        'expires_at' => now()->subDay(),
        'invited_by_user_id' => $u->id,
    ]);

    $limits = app(PlanLimits::class);
    expect($limits->canInviteMember($workspace))->toBeTrue();
});

test('api_access flag gates token creation', function () {
    $plan = planWithLimits(['api_access' => false]);
    $workspace = Workspace::factory()->create(['plan_id' => $plan->id]);

    $limits = app(PlanLimits::class);
    expect($limits->apiAccessEnabled($workspace))->toBeFalse();
});

test('workspace with no plan keeps unlimited everything (back-compat)', function () {
    $workspace = Workspace::factory()->create(['plan_id' => null]);
    $limits = app(PlanLimits::class);

    expect($limits->canCreateAgent($workspace))->toBeTrue();
    expect($limits->canCreateSource($workspace))->toBeTrue();
    expect($limits->canCreateWorkflow($workspace))->toBeTrue();
    expect($limits->canCreateIntegration($workspace))->toBeTrue();
    expect($limits->canInviteMember($workspace))->toBeTrue();
    expect($limits->apiAccessEnabled($workspace))->toBeTrue();
});

test('reasonFor returns plan-specific copy', function () {
    $limits = app(PlanLimits::class);
    expect($limits->reasonFor(PlanLimits::RESOURCE_AGENT, 5))
        ->toContain("plan's limit of 5 agents");

    expect($limits->reasonFor(PlanLimits::RESOURCE_INTEGRATION, 0))
        ->toContain('not included on your current plan');
});

test('AgentController::store blocks when the agent limit is reached', function () {
    $plan = planWithLimits(['agents_limit' => 1]);
    ['user' => $user, 'workspace' => $workspace] = asPlanMember($plan);
    Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->post('/app/agents', [
            'name' => 'Second',
            'language_default' => 'en',
            'allowed_origins' => ['https://example.com'],
            'system_prompt' => 'Be helpful.',
            'confidence_threshold' => 0.5,
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Agent::query()->where('workspace_id', $workspace->id)->count())
        ->toBe(1);
});

test('WorkflowController::store blocks at workflow limit', function () {
    $plan = planWithLimits(['workflows_limit' => 1]);
    ['user' => $user, 'workspace' => $workspace] = asPlanMember($plan);
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    Workflow::factory()->create(['workspace_id' => $workspace->id, 'agent_id' => $agent->id]);

    $this->actingAs($user)
        ->post('/app/workflows', [
            'name' => 'Capped flow',
            'trigger_kind' => 'keyword',
            'keywords' => ['hello'],
            'match_mode' => 'any',
            'agent_id' => $agent->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Workflow::query()->where('workspace_id', $workspace->id)->count())->toBe(1);
});

test('MemberController::store blocks at member limit', function () {
    $plan = planWithLimits(['members_limit' => 0]);
    ['user' => $user, 'workspace' => $workspace] = asPlanMember($plan);

    $this->actingAs($user)
        ->post('/app/members', [
            'email' => 'new@example.com',
            'role' => 'viewer',
        ])
        ->assertSessionHas('error');

    expect(Invitation::query()->where('workspace_id', $workspace->id)->count())->toBe(0);
});

test('WorkspaceApiTokenController::store blocks when api_access=false', function () {
    $plan = planWithLimits(['api_access' => false]);
    ['user' => $user, 'workspace' => $workspace] = asPlanMember($plan);

    $this->actingAs($user)
        ->post('/settings/api-tokens', [
            'name' => 'WP plugin',
            'abilities' => ['wp:integration'],
        ])
        ->assertSessionHas('error');

    expect(WorkspaceApiToken::query()
        ->where('workspace_id', $workspace->id)
        ->count())->toBe(0);
});

test('first workspace is always permitted regardless of plan limit', function () {
    $user = User::factory()->create();
    $limits = app(PlanLimits::class);

    $result = $limits->checkWorkspaceCreation($user);

    expect($result['allowed'])->toBeTrue();
    expect($result['current'])->toBe(0);
    expect($result['limit'])->toBeNull();
});

test('workspaces_limit null on primary plan means unlimited workspace creation', function () {
    $plan = planWithLimits(['workspaces_limit' => null]);
    ['user' => $user] = asPlanMember($plan);

    $limits = app(PlanLimits::class);
    $result = $limits->checkWorkspaceCreation($user);

    expect($result['allowed'])->toBeTrue();
    expect($result['limit'])->toBeNull();
    expect($result['current'])->toBe(1);
});

test('workspaces_limit of 1 blocks a second workspace creation', function () {
    $plan = planWithLimits(['workspaces_limit' => 1]);
    ['user' => $user] = asPlanMember($plan);

    $limits = app(PlanLimits::class);
    $result = $limits->checkWorkspaceCreation($user);

    expect($result['allowed'])->toBeFalse();
    expect($result['limit'])->toBe(1);
    expect($result['current'])->toBe(1);
    expect($result['remaining'])->toBe(0);
    expect($limits->canCreateWorkspace($user))->toBeFalse();
});

test('workspaces_limit of 3 allows creation up to limit', function () {
    $plan = planWithLimits(['workspaces_limit' => 3]);
    $user = User::factory()->create();

    Workspace::factory()->count(2)->create([
        'owner_user_id' => $user->id,
        'plan_id' => $plan->id,
    ]);

    $limits = app(PlanLimits::class);
    $result = $limits->checkWorkspaceCreation($user);

    expect($result['allowed'])->toBeTrue();
    expect($result['current'])->toBe(2);
    expect($result['remaining'])->toBe(1);

    Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'plan_id' => $plan->id,
    ]);

    expect(app(PlanLimits::class)->canCreateWorkspace($user))->toBeFalse();
});

test('plan workspaces_limit field is persisted and serialized', function () {
    $plan = planWithLimits(['workspaces_limit' => 5]);

    expect($plan->fresh()->workspaces_limit)->toBe(5);

    $plan->update(['workspaces_limit' => null]);
    expect($plan->fresh()->workspaces_limit)->toBeNull();
});
