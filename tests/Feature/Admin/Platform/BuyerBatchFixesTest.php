<?php

use App\Enums\PlatformRole;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Lead;
use App\Models\Plan;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

/**
 * Regression suite for the v2 buyer-reported batch — admin-side count
 * + label fixes. Each test pins a single previously-broken behavior:
 *
 *   - Workspace detail shows real conversation/lead counts
 *   - Agent list "Reach" column uses real per-agent counts
 *   - Lead list resolves Workspace + Agent via closure eager loads
 *   - Subscription MRR sums plan price across paid plan_id (not Stripe-only)
 *   - SourceController::displayFor renders friendly "Uploaded file" for type=file
 */
beforeEach(function () {
    Plan::query()->delete();
});

function buyerBatchSuperAdmin(): User
{
    return User::factory()->create([
        'email' => 'super@example.com',
        'role' => PlatformRole::SuperAdmin,
    ]);
}

test('admin workspaces show: conversation + lead counts use whereIn (not scoped whereHas)', function () {
    $admin = buyerBatchSuperAdmin();
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    Conversation::factory()->count(2)->create([
        'agent_id' => $agent->id,
        'is_playground' => false,
    ]);
    Conversation::factory()->create([
        'agent_id' => $agent->id,
        'is_playground' => true,
    ]);
    Lead::factory()->create(['agent_id' => $agent->id]);

    $this->actingAs($admin)
        ->get("/admin/workspaces/{$workspace->id}")
        ->assertInertia(fn ($p) => $p
            ->where('metrics.conversations', 2)
            ->where('metrics.leads', 1));
});

test('admin agents list: Reach counts ignore the admin\'s CurrentWorkspace', function () {
    $admin = buyerBatchSuperAdmin();
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    Source::factory()->count(3)->create(['agent_id' => $agent->id]);
    Conversation::factory()->count(4)->create([
        'agent_id' => $agent->id,
        'is_playground' => false,
    ]);
    Conversation::factory()->create([
        'agent_id' => $agent->id,
        'is_playground' => true,
    ]);

    $response = $this->actingAs($admin)->get('/admin/agents');
    $page = $response->inertiaPage();
    $agents = collect(data_get($page, 'props.agents', []));
    $match = $agents->firstWhere('id', $agent->id);

    expect($match)->not->toBeNull();
    expect($match['sources_count'])->toBe(3);
    expect($match['conversations_count'])->toBe(4);
});

test('admin leads list: Workspace + Agent columns are populated via closure eager loads', function () {
    $admin = buyerBatchSuperAdmin();
    $workspace = Workspace::factory()->create(['name' => 'Acme Corp']);
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'name' => 'Bot 9000',
    ]);
    Lead::factory()->create([
        'agent_id' => $agent->id,
        'email' => 'lead@example.com',
    ]);

    $this->actingAs($admin)
        ->get('/admin/leads')
        ->assertInertia(fn ($p) => $p
            ->has('leads', 1)
            ->where('leads.0.agent.name', 'Bot 9000')
            ->where('leads.0.workspace.name', 'Acme Corp'));
});

test('admin subscriptions MRR uses workspaces.plan_id, not Stripe-only loop', function () {
    $admin = buyerBatchSuperAdmin();

    $pro = Plan::create([
        'name' => 'Pro', 'slug' => 'pro-batch',
        'monthly_conversations' => 3000,
        'price_cents' => 24900,
        'is_active' => true,
    ]);

    Workspace::factory()->count(3)->create(['plan_id' => $pro->id]);

    $this->actingAs($admin)
        ->get('/admin/subscriptions')
        ->assertInertia(fn ($p) => $p
            ->where('totals.mrr_cents', 3 * 24900)
            ->where('totals.active_count', 3));
});

test('admin subscriptions MRR ignores lifetime plans', function () {
    $admin = buyerBatchSuperAdmin();

    $ltd = Plan::create([
        'name' => 'Lifetime', 'slug' => 'ltd-batch',
        'monthly_conversations' => 1000,
        'price_cents' => 49900,
        'billing_model' => Plan::BILLING_LIFETIME,
        'is_active' => true,
    ]);

    Workspace::factory()->count(2)->create(['plan_id' => $ltd->id]);

    $this->actingAs($admin)
        ->get('/admin/subscriptions')
        ->assertInertia(fn ($p) => $p->where('totals.mrr_cents', 0));
});

test('uploaded file source renders friendly label, not "(no url)"', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    Source::create([
        'agent_id' => $agent->id,
        'type' => 'file',
        'status' => 'indexed',
        'config' => ['filenames' => ['quarterly-report.pdf']],
    ]);

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/sources")
        ->assertInertia(fn ($p) => $p
            ->where('sources.0.display.title', 'quarterly-report.pdf')
            ->where('sources.0.display.subtitle', 'Uploaded file'));
});
