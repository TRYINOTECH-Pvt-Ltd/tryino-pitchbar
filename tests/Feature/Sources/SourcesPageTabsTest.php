<?php

use App\Models\Agent;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function tabAdmin(): array
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
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    return ['user' => $user, 'workspace' => $workspace, 'agent' => $agent];
}

test('sources page exposes integration booleans (both false by default)', function () {
    // Buyer report 2026-05-29: Knowledge sources page lacked a
    // Google Docs tab. Now both tabs ship; the page also reports
    // whether Google + Notion are connected so the tab can render
    // a "connect first" notice instead of letting the operator
    // paste into a doomed form.
    ['user' => $user, 'agent' => $agent] = tabAdmin();

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/sources")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('app/agents/sources')
            ->has('integrations')
            ->where('integrations.google', false)
            ->where('integrations.notion', false)
        );
});

test('sources page reports google + notion as connected when both rows exist', function () {
    ['user' => $user, 'workspace' => $workspace, 'agent' => $agent] = tabAdmin();

    IntegrationConnection::query()->withoutGlobalScopes()->create([
        'workspace_id' => $workspace->id,
        'kind' => 'google',
        'status' => 'active',
        'credentials_encrypted' => ['access_token' => 't', 'refresh_token' => 'r', 'expires_at' => now()->addHour()->toIso8601String(), 'scope' => 'x'],
    ]);
    IntegrationConnection::query()->withoutGlobalScopes()->create([
        'workspace_id' => $workspace->id,
        'kind' => 'notion',
        'status' => 'active',
        'credentials_encrypted' => ['access_token' => 't', 'workspace_id' => 'w'],
    ]);

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/sources")
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('integrations.google', true)
            ->where('integrations.notion', true)
        );
});

test('inactive integrations are not reported as connected', function () {
    ['user' => $user, 'workspace' => $workspace, 'agent' => $agent] = tabAdmin();

    IntegrationConnection::query()->withoutGlobalScopes()->create([
        'workspace_id' => $workspace->id,
        'kind' => 'google',
        'status' => 'revoked',
        'credentials_encrypted' => ['access_token' => 't'],
    ]);

    $this->actingAs($user)
        ->get("/app/agents/{$agent->id}/sources")
        ->assertOk()
        ->assertInertia(fn ($p) => $p->where('integrations.google', false));
});
