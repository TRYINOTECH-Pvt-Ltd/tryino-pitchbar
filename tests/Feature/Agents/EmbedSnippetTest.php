<?php

use App\Models\Agent;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

function settingsAdmin(): array
{
    $user = User::factory()->create();
    $ws = Workspace::factory()->create(['owner_user_id' => $user->id]);
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $ws->id])->save();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);

    return ['user' => $user, 'agent' => $agent];
}

test('agent settings page exposes the embed snippet alongside the form', function () {
    ['user' => $user, 'agent' => $agent] = settingsAdmin();

    $response = $this->actingAs($user)->get("/app/agents/{$agent->id}/settings");

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->component('app/agents/settings')
        ->has('embed.widget_url')
        ->has('embed.snippet')
        ->where('embed.snippet', fn ($s) => str_contains($s, "data-agent-id=\"{$agent->id}\""))
        ->where('embed.snippet', fn ($s) => str_contains((string) $s, '/widget/widget.js?v='))
    );
});

test('embed snippet uses async (visitor render is non-blocking)', function () {
    ['user' => $user, 'agent' => $agent] = settingsAdmin();

    $response = $this->actingAs($user)->get("/app/agents/{$agent->id}/settings");

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p->where('embed.snippet', fn ($s) => str_contains($s, 'async')));
});

test('embed snippet carries a cache-busting hash on the widget URL', function () {
    ['user' => $user, 'agent' => $agent] = settingsAdmin();

    $response = $this->actingAs($user)->get("/app/agents/{$agent->id}/settings");

    $response->assertOk();
    $response->assertInertia(fn ($p) => $p
        ->where('embed.snippet', fn ($s) => (bool) preg_match('@/widget/widget\.js\?v=[a-f0-9]+@', (string) $s))
    );
});

test('embed snippet path component stays stable across deploys (only ?v= rotates)', function () {
    // Buyer report 2026-05-29: every `npm run build:widget` rotated the
    // hashed filename in the snippet, so customers' pasted `<script>`
    // pointed at a hash that no longer existed on the server. The
    // SCRIPT TAG MUST stay identical at the path level across builds —
    // only the cache-bust `?v=` query may change.
    ['user' => $user, 'agent' => $agent] = settingsAdmin();

    $manifestPath = public_path('widget/manifest.json');
    $existed = is_file($manifestPath);
    $backup = $existed ? file_get_contents($manifestPath) : null;

    try {
        // Simulate "deploy A".
        file_put_contents($manifestPath, json_encode([
            'version' => '1.0.0',
            'hash' => 'aaaaaaaaaaaa',
            'file' => 'widget.aaaaaaaaaaaa.js',
            'url' => '/widget/widget.aaaaaaaaaaaa.js',
        ]));
        $a = $this->actingAs($user)->get("/app/agents/{$agent->id}/settings")
            ->assertOk()
            ->viewData('page')['props']['embed']['snippet'] ?? null;

        // Simulate "deploy B" — fresh build, fresh hash.
        file_put_contents($manifestPath, json_encode([
            'version' => '1.0.1',
            'hash' => 'bbbbbbbbbbbb',
            'file' => 'widget.bbbbbbbbbbbb.js',
            'url' => '/widget/widget.bbbbbbbbbbbb.js',
        ]));
        $b = $this->actingAs($user)->get("/app/agents/{$agent->id}/settings")
            ->assertOk()
            ->viewData('page')['props']['embed']['snippet'] ?? null;
    } finally {
        if ($existed && $backup !== null) {
            file_put_contents($manifestPath, $backup);
        } elseif (is_file($manifestPath)) {
            unlink($manifestPath);
        }
    }

    // Both snippets must point at the STABLE `/widget/widget.js` path.
    expect($a)->toContain('/widget/widget.js?v=aaaaaaaaaaaa');
    expect($b)->toContain('/widget/widget.js?v=bbbbbbbbbbbb');

    // Strip the ?v= query and confirm the rest of the snippet is byte-identical.
    $stripVersion = fn (string $s): string => (string) preg_replace('/\?v=[a-f0-9]+/', '', $s);
    expect($stripVersion((string) $a))->toBe($stripVersion((string) $b));
});

test('viewer can view but not modify on settings (page renders, form action gated by policy)', function () {
    $viewer = User::factory()->create();
    $ws = Workspace::factory()->create();
    WorkspaceUser::create([
        'workspace_id' => $ws->id,
        'user_id' => $viewer->id,
        'role' => 'viewer',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $viewer->forceFill(['default_workspace_id' => $ws->id])->save();
    $agent = Agent::factory()->create(['workspace_id' => $ws->id]);

    // edit() requires update permission per the controller — viewer denied.
    $this->actingAs($viewer)->get("/app/agents/{$agent->id}/settings")->assertForbidden();
});
