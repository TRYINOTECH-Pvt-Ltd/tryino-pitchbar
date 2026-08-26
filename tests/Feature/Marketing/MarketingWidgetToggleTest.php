<?php

use App\Enums\PlatformRole;
use App\Models\Agent;
use App\Models\AppSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function asSuperAdmin(): User
{
    $user = User::factory()->create(['role' => PlatformRole::SuperAdmin]);
    $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return $user;
}

test('super_admin can enable the marketing widget and pick an agent', function () {
    $admin = asSuperAdmin();
    $agent = Agent::factory()->published()->create();

    $response = $this->actingAs($admin)
        ->patch('/settings/system/marketing', [
            'marketing_home_content' => ['hero_title' => 'Hi'],
            'marketing_widget_enabled' => true,
            'marketing_widget_agent_id' => $agent->id,
        ]);

    $response->assertRedirect();

    $settings = AppSetting::singleton();
    expect($settings->marketing_widget_enabled)->toBeTrue();
    expect($settings->marketing_widget_agent_id)->toBe($agent->id);
});

test('marketing widget agent_id must be a valid uuid', function () {
    $admin = asSuperAdmin();

    $this->actingAs($admin)
        ->patch('/settings/system/marketing', [
            'marketing_home_content' => ['hero_title' => 'Hi'],
            'marketing_widget_enabled' => true,
            'marketing_widget_agent_id' => 'not-a-uuid',
        ])
        ->assertSessionHasErrors('marketing_widget_agent_id');
});

test('marketing widget defaults to off on a fresh install', function () {
    AppSetting::singleton();
    $settings = AppSetting::singleton();

    expect((bool) $settings->marketing_widget_enabled)->toBeFalse();
    expect($settings->marketing_widget_agent_id)->toBeNull();
});

test('toggling off keeps the agent_id in place but the gate ignores it', function () {
    $admin = asSuperAdmin();
    $agent = Agent::factory()->published()->create();
    AppSetting::singleton()->forceFill([
        'marketing_widget_enabled' => true,
        'marketing_widget_agent_id' => $agent->id,
    ])->save();

    $this->actingAs($admin)
        ->patch('/settings/system/marketing', [
            'marketing_home_content' => ['hero_title' => 'Hi'],
            'marketing_widget_enabled' => false,
            'marketing_widget_agent_id' => $agent->id,
        ])
        ->assertRedirect();

    $settings = AppSetting::singleton();
    expect((bool) $settings->marketing_widget_enabled)->toBeFalse();
    // Selected agent is preserved so toggling back on doesn't lose the pick.
    expect($settings->marketing_widget_agent_id)->toBe($agent->id);
});

test('agent_options on the marketing summary lists every published agent', function () {
    $admin = asSuperAdmin();
    $published = Agent::factory()->published()->create(['name' => 'Public Bot']);
    Agent::factory()->create(['is_published' => false, 'name' => 'Draft Bot']);

    $response = $this->actingAs($admin)->get('/settings/marketing');

    $response->assertOk();
    $options = collect($response->viewData('page')['props']['sections']['marketing']['agent_options'] ?? []);
    expect($options->pluck('id')->contains($published->id))->toBeTrue();
    expect($options->pluck('label')->contains('Draft Bot'))->toBeFalse();
});

test('enabling the marketing widget auto-adds APP_URL to the agent allowed_origins', function () {
    config(['app.url' => 'https://my-pitchbar.example.com']);
    $admin = asSuperAdmin();
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => ['https://customer-site.example.com'],
    ]);

    $this->actingAs($admin)
        ->patch('/settings/system/marketing', [
            'marketing_home_content' => ['hero_title' => 'Hi'],
            'marketing_widget_enabled' => true,
            'marketing_widget_agent_id' => $agent->id,
        ])
        ->assertRedirect();

    $agent->refresh();
    expect($agent->allowed_origins)->toContain('https://my-pitchbar.example.com');
    expect($agent->allowed_origins)->toContain('https://customer-site.example.com');
});

test('enabling the marketing widget is a no-op when APP_URL already in allowed_origins', function () {
    config(['app.url' => 'https://my-pitchbar.example.com']);
    $admin = asSuperAdmin();
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => ['https://my-pitchbar.example.com'],
    ]);

    $this->actingAs($admin)
        ->patch('/settings/system/marketing', [
            'marketing_home_content' => ['hero_title' => 'Hi'],
            'marketing_widget_enabled' => true,
            'marketing_widget_agent_id' => $agent->id,
        ])
        ->assertRedirect();

    $agent->refresh();
    expect(count($agent->allowed_origins))->toBe(1);
    expect($agent->allowed_origins[0])->toBe('https://my-pitchbar.example.com');
});

test('disabling the marketing widget does NOT modify allowed_origins', function () {
    config(['app.url' => 'https://my-pitchbar.example.com']);
    $admin = asSuperAdmin();
    $agent = Agent::factory()->published()->create([
        'allowed_origins' => ['https://customer-site.example.com'],
    ]);

    $this->actingAs($admin)
        ->patch('/settings/system/marketing', [
            'marketing_home_content' => ['hero_title' => 'Hi'],
            'marketing_widget_enabled' => false,
            'marketing_widget_agent_id' => $agent->id,
        ])
        ->assertRedirect();

    $agent->refresh();
    expect($agent->allowed_origins)->toBe(['https://customer-site.example.com']);
});

test('admin can clear marketing_widget_agent_id by sending null', function () {
    $admin = asSuperAdmin();
    $agent = Agent::factory()->published()->create();
    AppSetting::singleton()->forceFill([
        'marketing_widget_enabled' => true,
        'marketing_widget_agent_id' => $agent->id,
    ])->save();

    $this->actingAs($admin)
        ->patch('/settings/system/marketing', [
            'marketing_home_content' => ['hero_title' => 'Hi'],
            'marketing_widget_enabled' => true,
            'marketing_widget_agent_id' => null,
        ])
        ->assertRedirect();

    $settings = AppSetting::singleton();
    expect($settings->marketing_widget_agent_id)->toBeNull();
});

test('saving widget-only payload (without marketing_home_content) succeeds', function () {
    // Buyer reported 2026-05-21: clicking "Save marketing widget" 422'd
    // because the validation rule was `required|array` for
    // marketing_home_content, but the widget sub-form only sends widget
    // fields. Now `sometimes|array` — both forms share the endpoint.
    $admin = asSuperAdmin();
    $agent = Agent::factory()->published()->create();

    $response = $this->actingAs($admin)
        ->patch('/settings/system/marketing', [
            'marketing_widget_enabled' => true,
            'marketing_widget_agent_id' => $agent->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    $settings = AppSetting::singleton();
    expect((bool) $settings->marketing_widget_enabled)->toBeTrue();
    expect($settings->marketing_widget_agent_id)->toBe($agent->id);
});

test('customer-role user cannot patch marketing settings', function () {
    $user = User::factory()->create(['role' => PlatformRole::Customer]);
    $ws = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $ws->id])->save();

    $response = $this->actingAs($user)
        ->patch('/settings/system/marketing', [
            'marketing_home_content' => ['hero_title' => 'Hi'],
            'marketing_widget_enabled' => true,
        ]);

    expect($response->status())->toBeIn([302, 403, 404]);
});
