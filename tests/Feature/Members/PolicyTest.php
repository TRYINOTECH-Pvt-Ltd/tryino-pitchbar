<?php

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function joinAs(Workspace $ws, User $user, string $role): void
{
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $user->id,
        'role' => $role,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
}

test('AgentPolicy::create allows admin/editor and rejects viewer', function () {
    $admin = User::factory()->create();
    $editor = User::factory()->create();
    $viewer = User::factory()->create();
    $workspace = Workspace::factory()->create();

    joinAs($workspace, $admin, 'admin');
    joinAs($workspace, $editor, 'editor');
    joinAs($workspace, $viewer, 'viewer');

    expect($admin->can('create', [Agent::class, $workspace]))->toBeTrue();
    expect($editor->can('create', [Agent::class, $workspace]))->toBeTrue();
    expect($viewer->can('create', [Agent::class, $workspace]))->toBeFalse();
});

test('AgentPolicy::update returns false across workspaces', function () {
    $userA = User::factory()->create();
    $wsA = Workspace::factory()->create();
    $wsB = Workspace::factory()->create();
    joinAs($wsA, $userA, 'admin');

    $agentB = Agent::factory()->create(['workspace_id' => $wsB->id]);

    expect($userA->can('update', $agentB))->toBeFalse();
});

test('WorkspacePolicy::manageBilling restricted to owner only', function () {
    $owner = User::factory()->create();
    $admin = User::factory()->create();
    $workspace = Workspace::factory()->create();
    joinAs($workspace, $owner, 'owner');
    joinAs($workspace, $admin, 'admin');

    expect($owner->can('manageBilling', $workspace))->toBeTrue();
    expect($admin->can('manageBilling', $workspace))->toBeFalse();
});
