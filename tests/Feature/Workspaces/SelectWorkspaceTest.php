<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

test('member can select a workspace they belong to', function () {
    $user = User::factory()->create();
    $a = Workspace::factory()->create();
    $b = Workspace::factory()->create();

    foreach ([$a, $b] as $ws) {
        WorkspaceUser::create([
            'workspace_id' => $ws->id,
            'user_id' => $user->id,
            'role' => 'admin',
            'invited_at' => now(),
            'accepted_at' => now(),
        ]);
    }

    $user->forceFill(['default_workspace_id' => $a->id])->save();

    $response = $this->actingAs($user)->post(route('workspaces.select', ['workspace' => $b->id]));

    $response->assertRedirect();
    expect($user->fresh()->default_workspace_id)->toBe($b->id);
});

test('non-member is forbidden from selecting a workspace', function () {
    $user = User::factory()->create();
    $other = Workspace::factory()->create();

    $response = $this->actingAs($user)->post(route('workspaces.select', ['workspace' => $other->id]));

    $response->assertForbidden();
});
