<?php

use App\Enums\PlatformRole;
use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;

test('admin /admin/workspaces returns true agent count across foreign workspaces', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $foreign = Workspace::factory()->create();
    Agent::factory()->count(3)->create(['workspace_id' => $foreign->id]);

    $own = Workspace::factory()->create(['owner_user_id' => $admin->id]);
    Agent::factory()->count(1)->create(['workspace_id' => $own->id]);
    $admin->forceFill(['default_workspace_id' => $own->id])->save();

    $this->actingAs($admin)
        ->get('/admin/workspaces')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->has('workspaces')
            ->where(
                'workspaces',
                fn ($rows) => collect($rows)->firstWhere('id', $foreign->id)['agents_count'] === 3
                    && collect($rows)->firstWhere('id', $own->id)['agents_count'] === 1,
            )
        );
});
