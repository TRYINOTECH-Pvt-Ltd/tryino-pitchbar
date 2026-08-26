<?php

use App\Enums\PlatformRole;
use App\Models\Agent;
use App\Models\AppSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Facades\Cache;

/**
 * Client report 2026-05-23: when the admin enabled the marketing
 * widget and selected an agent that was not yet published, the
 * blade gate silently substituted the demo agent in its place.
 * The buyer's marketing page then showed a stranger's persona name
 * (the seeded "Aria" demo agent) and they thought data was bleeding
 * across accounts.
 *
 * The blade gate at resources/views/app.blade.php is now narrower:
 *   admin explicitly enabled toggle + agent id set + agent published → mount that agent
 *   admin explicitly enabled toggle + agent id set + agent unpublished → mount nothing
 *     (NO silent demo substitution)
 *   admin did not enable toggle → demo fallback if DEMO=true (legacy behaviour preserved)
 */
function asSuperAdminForToggleTest(): User
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

test('marketing widget script tag does NOT mount when toggle is OFF and demo is OFF', function () {
    AppSetting::singleton()->forceFill([
        'marketing_widget_enabled' => false,
        'marketing_widget_agent_id' => null,
    ])->save();
    config(['demo.enabled' => false]);

    $response = $this->get('/');

    $response->assertOk();
    expect($response->getContent())->not->toContain('widget/widget.js');
});

test('marketing widget mounts the configured agent when toggle is ON and agent is published', function () {
    $agent = Agent::factory()->published()->create();
    AppSetting::singleton()->forceFill([
        'marketing_widget_enabled' => true,
        'marketing_widget_agent_id' => $agent->id,
    ])->save();
    config(['demo.enabled' => false]);

    $response = $this->get('/');

    $response->assertOk();
    $body = $response->getContent();
    expect($body)->toContain('widget/widget.js');
    expect($body)->toContain('data-agent-id="'.$agent->id.'"');
});

test('marketing widget skips the mount when the configured agent is UNPUBLISHED (no silent demo substitution)', function () {
    $draftAgent = Agent::factory()->create(['is_published' => false]);
    // Also seed a valid demo agent in a separate workspace so the
    // old buggy code path would have substituted it.
    $demoWorkspace = Workspace::factory()->create(['slug' => 'pitchbar-demo']);
    Agent::factory()->published()->create([
        'workspace_id' => $demoWorkspace->id,
        'name' => 'Aria',
    ]);
    AppSetting::singleton()->forceFill([
        'marketing_widget_enabled' => true,
        'marketing_widget_agent_id' => $draftAgent->id,
    ])->save();
    config(['demo.enabled' => true]);
    Cache::flush();

    $response = $this->get('/');

    $response->assertOk();
    $body = $response->getContent();
    // The blade gate must mount no widget at all — substituting the
    // demo agent here was the exact bug the buyer hit.
    expect($body)->not->toContain('widget/widget.js');
    expect($body)->not->toContain('data-agent-id="'.$draftAgent->id.'"');
});

test('saving marketing widget settings flushes the demo-agent cache so toggle changes apply immediately', function () {
    $admin = asSuperAdminForToggleTest();
    Cache::put('marketing.demo_agent_id', 'stale-cached-id', now()->addMinutes(5));

    $this->actingAs($admin)
        ->patch('/settings/system/marketing', [
            'marketing_home_content' => ['hero_title' => 'Hi'],
            'marketing_widget_enabled' => false,
        ])
        ->assertRedirect();

    // Cache should have been forgotten on save.
    expect(Cache::get('marketing.demo_agent_id'))->toBeNull();
});

test('widget Bar.tsx falls back to agent.name when persona.name is empty', function () {
    $source = (string) file_get_contents(resource_path('widget/src/ui/Bar.tsx'));

    // The fallback chain must be persona.name → agent.name → tr('AI assistant').
    expect($source)->toContain('agentName');
    expect($source)->toContain('state.init?.agent.name');
    // Quick structural check: the panelTitle assignment includes
    // both personaName and agentName before the literal fallback.
    expect($source)->toMatch('/panelTitle\s*=\s*[^;]*personaName\s*\?\?\s*agentName/s');
});
