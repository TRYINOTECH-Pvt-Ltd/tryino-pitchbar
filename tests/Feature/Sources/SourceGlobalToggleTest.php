<?php

use App\Models\Agent;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @return array{user: User, agent: Agent} */
function globalToggleActor(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    return ['user' => $user, 'agent' => $agent];
}

test('the owner can flag a source to answer on every page', function () {
    ['user' => $user, 'agent' => $agent] = globalToggleActor();
    $source = Source::create(['agent_id' => $agent->id, 'type' => 'text', 'status' => 'indexed', 'config' => []]);

    expect(Source::hasGlobalFor($agent->id))->toBeFalse();

    $this->actingAs($user)
        ->patch("/app/sources/{$source->id}/global", ['is_global' => true])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($source->fresh()->is_global)->toBeTrue()
        // Cache gate flipped so retrieval starts honoring the flag at once.
        ->and(Source::hasGlobalFor($agent->id))->toBeTrue();
});

test('unflagging a global source turns it back off and bumps the cache version', function () {
    ['user' => $user, 'agent' => $agent] = globalToggleActor();
    $source = Source::create(['agent_id' => $agent->id, 'type' => 'text', 'status' => 'indexed', 'config' => [], 'is_global' => true]);
    $before = Source::globalVersionFor($agent->id);

    $this->actingAs($user)
        ->patch("/app/sources/{$source->id}/global", ['is_global' => false])
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($source->fresh()->is_global)->toBeFalse()
        ->and(Source::globalVersionFor($agent->id))->toBeGreaterThan($before);
});

test('is_global is required and boolean', function () {
    ['user' => $user, 'agent' => $agent] = globalToggleActor();
    $source = Source::create(['agent_id' => $agent->id, 'type' => 'text', 'status' => 'indexed', 'config' => []]);

    $this->actingAs($user)
        ->patch("/app/sources/{$source->id}/global", [])
        ->assertSessionHasErrors('is_global');
});

test('a user from another workspace cannot flag the source (tenancy)', function () {
    ['agent' => $agent] = globalToggleActor();
    $source = Source::create(['agent_id' => $agent->id, 'type' => 'text', 'status' => 'indexed', 'config' => []]);

    // A stranger with their own workspace is denied — the controller
    // authorizes the toggle against the parent agent, which they can't
    // update. The source is never touched.
    ['user' => $stranger] = globalToggleActor();

    $this->actingAs($stranger)
        ->patch("/app/sources/{$source->id}/global", ['is_global' => true])
        ->assertForbidden();

    expect($source->fresh()->is_global)->toBeFalse();
});
