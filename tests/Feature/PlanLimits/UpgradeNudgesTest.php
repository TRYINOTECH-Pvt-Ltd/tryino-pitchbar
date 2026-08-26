<?php

use App\Enums\PlatformRole;
use App\Models\Agent;
use App\Models\Plan;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function planLimitsUser(int $sourcesLimit = 3, int $agentsLimit = 5): array
{
    $plan = Plan::factory()->create([
        'sources_limit' => $sourcesLimit,
        'agents_limit' => $agentsLimit,
        'is_active' => true,
    ]);
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'plan_id' => $plan->id,
    ]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return ['user' => $user, 'workspace' => $workspace, 'plan' => $plan];
}

test('workspaceLimits shared prop exposes per-resource snapshot', function () {
    ['user' => $user, 'workspace' => $workspace] = planLimitsUser(3, 5);
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    Source::create(['agent_id' => $agent->id, 'type' => 'url', 'status' => 'indexed']);

    $this->actingAs($user)
        ->get(route('agents.sources.index', ['agent' => $agent->id]))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->has('workspaceLimits.source', fn ($s) => $s
                ->where('limit', 3)
                ->where('current', 1)
                ->where('allowed', true)
                ->where('remaining', 2)
            )
            ->has('workspaceLimits.agent', fn ($a) => $a
                ->where('limit', 5)
                ->where('current', 1)
                ->where('allowed', true)
                ->etc()
            )
        );
});

test('workspaceLimits.source.allowed flips false once limit reached', function () {
    ['user' => $user, 'workspace' => $workspace] = planLimitsUser(2, 5);
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);
    Source::create(['agent_id' => $agent->id, 'type' => 'url', 'status' => 'indexed']);
    Source::create(['agent_id' => $agent->id, 'type' => 'url', 'status' => 'indexed']);

    $this->actingAs($user)
        ->get(route('agents.sources.index', ['agent' => $agent->id]))
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('workspaceLimits.source.allowed', false)
            ->where('workspaceLimits.source.current', 2)
            ->where('workspaceLimits.source.limit', 2)
        );
});

test('super_admin gets null workspaceLimits on /admin pages', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    WorkspaceUser::create([
        'workspace_id' => Workspace::factory()->create(['owner_user_id' => $admin->id])->id,
        'user_id' => $admin->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    $response = $this->actingAs($admin)->get('/admin');
    if ($response->status() === 200) {
        $response->assertInertia(fn ($p) => $p->where('workspaceLimits', null));
    } else {
        // Some installs redirect /admin → /admin/dashboard. Test it
        // again on the redirect destination.
        $this->actingAs($admin)
            ->get($response->headers->get('Location') ?? '/admin')
            ->assertInertia(fn ($p) => $p->where('workspaceLimits', null));
    }
});
