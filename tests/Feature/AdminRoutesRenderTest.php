<?php

use App\Enums\PlatformRole;
use App\Models\Agent;
use App\Models\BehaviorRule;
use App\Models\ContentGap;
use App\Models\Conversation;
use App\Models\CtaRule;
use App\Models\CuratedAnswer;
use App\Models\Document;
use App\Models\Experiment;
use App\Models\Lead;
use App\Models\Source;
use App\Models\User;
use App\Models\Variant;
use App\Models\Visitor;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

/**
 * Smoke test: every admin Inertia route (both the per-workspace customer area
 * and the platform /admin/* area) must render without throwing. Catches
 * "missing page" and silly crashes (e.g. property on null) that unit tests
 * don't hit.
 */
function asAdminRender(): array
{
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

    return ['user' => $user, 'workspace' => $workspace];
}

test('every customer-facing Inertia page renders without crashing', function () {
    ['user' => $user, 'workspace' => $workspace] = asAdminRender();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    $source = Source::factory()->create(['agent_id' => $agent->id]);
    Document::factory()->create(['source_id' => $source->id, 'agent_id' => $agent->id]);
    CuratedAnswer::factory()->create(['agent_id' => $agent->id]);
    BehaviorRule::factory()->create(['agent_id' => $agent->id]);
    CtaRule::factory()->create(['agent_id' => $agent->id]);
    $exp = Experiment::factory()->create(['agent_id' => $agent->id]);
    Variant::factory()->create(['experiment_id' => $exp->id]);

    $visitor = Visitor::factory()->create(['agent_id' => $agent->id]);
    $conv = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'visitor_id' => $visitor->id,
        'is_lead' => true,
    ]);
    $lead = Lead::factory()->create(['conversation_id' => $conv->id, 'agent_id' => $agent->id]);

    ContentGap::factory()->create(['agent_id' => $agent->id]);

    $routes = [
        '/dashboard',
        '/app/agents',
        '/app/agents/create',
        "/app/agents/{$agent->id}",
        "/app/agents/{$agent->id}/settings",
        "/app/agents/{$agent->id}/customize",
        "/app/agents/{$agent->id}/sources",
        "/app/agents/{$agent->id}/curated",
        "/app/agents/{$agent->id}/behavior",
        "/app/agents/{$agent->id}/ctas",
        "/app/agents/{$agent->id}/playground",
        "/app/agents/{$agent->id}/experiments",
        '/app/inbox',
        "/app/inbox/{$lead->id}",
        '/app/analytics',
        '/app/analytics/content-gaps',
        '/app/members',
    ];

    foreach ($routes as $route) {
        $response = $this->actingAs($user)->get($route);
        $status = $response->getStatusCode();
        expect($status)->toBeIn([200, 302], "Route {$route} returned {$status}");
    }
});

test('every platform /admin/* page renders for a super_admin', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $workspace = Workspace::factory()->create();
    Agent::factory()->create(['workspace_id' => $workspace->id]);
    Lead::factory()->create();

    $routes = [
        '/admin',
        '/admin/workspaces',
        "/admin/workspaces/{$workspace->id}",
        '/admin/users',
        '/admin/agents',
        '/admin/conversations',
        '/admin/leads',
        '/admin/subscriptions',
        '/admin/usage',
        '/admin/jobs/failed',
    ];

    foreach ($routes as $route) {
        $response = $this->actingAs($admin)->get($route);
        $status = $response->getStatusCode();
        expect($status)->toBeIn([200, 302], "Route {$route} returned {$status}");
    }
});
