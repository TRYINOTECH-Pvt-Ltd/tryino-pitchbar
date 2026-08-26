<?php

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function widgetCustomizationAdmin(): array
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

test('admin can persist launcher_size, color_scheme, header_logo_url', function () {
    ['user' => $user, 'workspace' => $workspace] = widgetCustomizationAdmin();
    $agent = Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'theme' => ['primary' => '#000'],
    ]);

    $this->actingAs($user)
        ->patch(route('agents.update', ['agent' => $agent->id]), [
            'theme' => [
                'primary' => '#000',
                'launcher_size' => 'lg',
                'color_scheme' => 'auto',
                'header_logo_url' => 'https://example.com/logo.svg',
            ],
        ])
        ->assertRedirect();

    $agent->refresh();
    expect($agent->theme['launcher_size'])->toBe('lg');
    expect($agent->theme['color_scheme'])->toBe('auto');
    expect($agent->theme['header_logo_url'])->toBe('https://example.com/logo.svg');
});

test('launcher_size rejects invalid values', function () {
    ['user' => $user, 'workspace' => $workspace] = widgetCustomizationAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->patch(route('agents.update', ['agent' => $agent->id]), [
            'theme' => ['launcher_size' => 'xxl'],
        ])
        ->assertSessionHasErrors('theme.launcher_size');
});

test('color_scheme rejects invalid values', function () {
    ['user' => $user, 'workspace' => $workspace] = widgetCustomizationAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->patch(route('agents.update', ['agent' => $agent->id]), [
            'theme' => ['color_scheme' => 'rainbow'],
        ])
        ->assertSessionHasErrors('theme.color_scheme');
});

test('header_logo_url rejects non-URL strings', function () {
    ['user' => $user, 'workspace' => $workspace] = widgetCustomizationAdmin();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->patch(route('agents.update', ['agent' => $agent->id]), [
            'theme' => ['header_logo_url' => 'not-a-url'],
        ])
        ->assertSessionHasErrors('theme.header_logo_url');
});
