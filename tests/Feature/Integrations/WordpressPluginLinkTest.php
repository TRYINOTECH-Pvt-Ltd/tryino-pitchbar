<?php

use App\Enums\PlatformRole;
use App\Models\AppSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function wpPluginUser(): array
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

test('integrations page surfaces super-admin-customized wordpress plugin link', function () {
    AppSetting::singleton()->forceFill([
        'wordpress_plugin_download_url' => 'https://replibar.com/plugin/pitchbar.zip',
        'wordpress_plugin_help_text' => 'Grab the plugin from our self-hosted mirror:',
        // Ensure the WordPress integration card is enabled so the page renders it.
        'integrations_enabled' => ['wordpress' => true],
    ])->save();

    ['user' => $user] = wpPluginUser();

    $this->actingAs($user)
        ->get('/app/integrations')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('wordpressPlugin.download_url', 'https://replibar.com/plugin/pitchbar.zip')
            ->where('wordpressPlugin.help_text', 'Grab the plugin from our self-hosted mirror:'));
});

test('super_admin can persist wordpress plugin link via system settings', function () {
    $admin = User::factory()->create(['role' => PlatformRole::SuperAdmin]);

    $this->actingAs($admin)
        ->patch('/settings/system/wordpress_plugin', [
            'wordpress_plugin_download_url' => 'https://replibar.com/plugin',
            'wordpress_plugin_help_text' => 'Download from our directory.',
        ])
        ->assertRedirect();

    expect(AppSetting::singleton()->wordpress_plugin_download_url)
        ->toBe('https://replibar.com/plugin');
    expect(AppSetting::singleton()->wordpress_plugin_help_text)
        ->toBe('Download from our directory.');
});

test('integrations page falls back to default copy when override is empty', function () {
    AppSetting::singleton()->forceFill([
        'wordpress_plugin_download_url' => null,
        'wordpress_plugin_help_text' => null,
        'integrations_enabled' => ['wordpress' => true],
    ])->save();

    ['user' => $user] = wpPluginUser();

    $this->actingAs($user)
        ->get('/app/integrations')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->where('wordpressPlugin.download_url', null)
            ->where('wordpressPlugin.help_text', null));
});
