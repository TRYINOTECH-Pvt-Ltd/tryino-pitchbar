<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\LiveChat\WorkspacePresence;

beforeEach(function () {
    $this->presence = new WorkspacePresence;
});

function presenceMember(Workspace $w, array $userOverrides = [], string $role = 'admin'): User
{
    $user = User::factory()->create($userOverrides);
    WorkspaceUser::create([
        'workspace_id' => $w->id,
        'user_id' => $user->id,
        'role' => $role,
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);

    return $user;
}

test('counts opted-in members active in the last 2 minutes', function () {
    $workspace = Workspace::factory()->create();

    $available = presenceMember($workspace, [
        'live_chat_available' => true,
        'last_active_at' => now()->subSeconds(30),
    ]);

    expect($this->presence->activeOperatorCount($workspace))->toBe(1);
    expect($this->presence->activeOperators($workspace)->pluck('id')->all())
        ->toContain($available->id);
});

test('ignores members who have not opted into live chat', function () {
    $workspace = Workspace::factory()->create();

    presenceMember($workspace, [
        'live_chat_available' => false,
        'last_active_at' => now(),
    ]);

    expect($this->presence->activeOperatorCount($workspace))->toBe(0);
});

test('ignores members whose heartbeat is stale', function () {
    $workspace = Workspace::factory()->create();

    presenceMember($workspace, [
        'live_chat_available' => true,
        'last_active_at' => now()->subMinutes(5),
    ]);

    expect($this->presence->activeOperatorCount($workspace))->toBe(0);
});

test('ignores members of other workspaces', function () {
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    presenceMember($b, [
        'live_chat_available' => true,
        'last_active_at' => now(),
    ]);

    expect($this->presence->activeOperatorCount($a))->toBe(0);
    expect($this->presence->activeOperatorCount($b))->toBe(1);
});

test('ignores invitations that were never accepted', function () {
    $workspace = Workspace::factory()->create();
    $user = User::factory()->create([
        'live_chat_available' => true,
        'last_active_at' => now(),
    ]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => null,
    ]);

    expect($this->presence->activeOperatorCount($workspace))->toBe(0);
});
