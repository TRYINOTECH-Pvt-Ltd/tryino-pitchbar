<?php

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function launcherAsMember(string $role = 'admin'): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
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

beforeEach(function () {
    Storage::fake('public');
});

test('admin can upload a launcher icon and theme is updated', function () {
    ['user' => $user, 'workspace' => $workspace] = launcherAsMember('admin');
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id, 'theme' => ['primary' => '#000']]);

    $file = UploadedFile::fake()->image('logo.png', 64, 64);

    $this->actingAs($user)
        ->post(route('agents.launcher-icon.store', ['agent' => $agent->id]), ['icon' => $file])
        ->assertRedirect();

    Storage::disk('public')->assertExists('agent-launcher-icons/'.$agent->id.'.png');

    $agent->refresh();
    expect($agent->theme['launcher_icon_url'] ?? null)
        ->toContain('agent-launcher-icons/'.$agent->id.'.png');
    expect($agent->theme['primary'])->toBe('#000');
});

test('upload rejects non-image files', function () {
    ['user' => $user, 'workspace' => $workspace] = launcherAsMember('admin');
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $file = UploadedFile::fake()->create('evil.txt', 1, 'text/plain');

    $this->actingAs($user)
        ->post(route('agents.launcher-icon.store', ['agent' => $agent->id]), ['icon' => $file])
        ->assertSessionHasErrors('icon');

    Storage::disk('public')->assertDirectoryEmpty('agent-launcher-icons');
});

test('upload rejects files over 256KB', function () {
    ['user' => $user, 'workspace' => $workspace] = launcherAsMember('admin');
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $file = UploadedFile::fake()->create('huge.png', 300, 'image/png');

    $this->actingAs($user)
        ->post(route('agents.launcher-icon.store', ['agent' => $agent->id]), ['icon' => $file])
        ->assertSessionHasErrors('icon');
});

test('destroy clears the icon and removes the file', function () {
    ['user' => $user, 'workspace' => $workspace] = launcherAsMember('admin');
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->post(route('agents.launcher-icon.store', ['agent' => $agent->id]), [
            'icon' => UploadedFile::fake()->image('logo.webp', 64, 64),
        ])
        ->assertRedirect();

    Storage::disk('public')->assertExists('agent-launcher-icons/'.$agent->id.'.webp');

    $this->actingAs($user)
        ->delete(route('agents.launcher-icon.destroy', ['agent' => $agent->id]))
        ->assertRedirect();

    Storage::disk('public')->assertMissing('agent-launcher-icons/'.$agent->id.'.webp');

    $agent->refresh();
    expect(array_key_exists('launcher_icon_url', (array) $agent->theme))->toBeFalse();
});

test('cross-tenant upload is forbidden', function () {
    ['user' => $userA] = launcherAsMember('admin');

    $otherOwner = User::factory()->create();
    $otherWorkspace = Workspace::factory()->create(['owner_user_id' => $otherOwner->id]);
    $foreignAgent = Agent::factory()->create(['workspace_id' => $otherWorkspace->id]);

    $response = $this->actingAs($userA)
        ->post(route('agents.launcher-icon.store', ['agent' => $foreignAgent->id]), [
            'icon' => UploadedFile::fake()->image('logo.png', 64, 64),
        ]);

    // Either the BelongsToWorkspace global scope hides the foreign agent
    // (404) or AgentPolicy::update denies (403) — both are valid blocks
    // and depend on which check fires first for the resolved binding.
    expect($response->status())->toBeIn([403, 404]);
    Storage::disk('public')->assertDirectoryEmpty('agent-launcher-icons');
});

test('viewer role cannot upload a launcher icon', function () {
    ['user' => $user, 'workspace' => $workspace] = launcherAsMember('viewer');
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->post(route('agents.launcher-icon.store', ['agent' => $agent->id]), [
            'icon' => UploadedFile::fake()->image('logo.png', 64, 64),
        ])
        ->assertForbidden();
});

test('uploading a second time replaces the existing file even when extension changes', function () {
    ['user' => $user, 'workspace' => $workspace] = launcherAsMember('admin');
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->post(route('agents.launcher-icon.store', ['agent' => $agent->id]), [
            'icon' => UploadedFile::fake()->image('first.png', 64, 64),
        ])
        ->assertRedirect();
    Storage::disk('public')->assertExists('agent-launcher-icons/'.$agent->id.'.png');

    $this->actingAs($user)
        ->post(route('agents.launcher-icon.store', ['agent' => $agent->id]), [
            'icon' => UploadedFile::fake()->image('second.webp', 64, 64),
        ])
        ->assertRedirect();

    Storage::disk('public')->assertExists('agent-launcher-icons/'.$agent->id.'.webp');
    Storage::disk('public')->assertMissing('agent-launcher-icons/'.$agent->id.'.png');
});
