<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;

/**
 * Buyer report 2026-05-24: workspace owner could not find their
 * workspace URL anywhere in the dashboard. They tried hitting the
 * bare slug at root (e.g. `https://app.test/<slug>`) and got an
 * unstyled 404. Two fixes covered here:
 *
 *   1. /settings/workspace exposes the slug + a public KB URL with
 *      copy buttons so the customer can share it.
 *   2. The root-level fallback resolves a bare workspace slug and
 *      redirects to /kb/{slug}, so the typo-paste reaches a real
 *      landing page when the workspace exists.
 */
function workspaceOwner(): array
{
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create([
        'owner_user_id' => $user->id,
        'slug' => 'acme-test-'.substr(bin2hex(random_bytes(3)), 0, 6),
    ]);
    WorkspaceUser::create([
        'workspace_id' => $workspace->id,
        'user_id' => $user->id,
        'role' => 'admin',
        'invited_at' => now(),
        'accepted_at' => now(),
    ]);
    $user->forceFill(['default_workspace_id' => $workspace->id])->save();

    return compact('user', 'workspace');
}

test('settings workspace page exposes slug + public KB url to the customer', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceOwner();

    $this->actingAs($user)
        ->get('/settings/workspace')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('settings/workspace')
            ->where('workspace.slug', $workspace->slug)
            ->where('workspace.public_kb_url', route('kb.index', ['workspace' => $workspace->slug])));
});

test('bare workspace slug at root redirects to the public knowledge base', function () {
    ['workspace' => $workspace] = workspaceOwner();

    $this->get("/{$workspace->slug}")
        ->assertRedirect(route('kb.index', ['workspace' => $workspace->slug]));
});

test('bare slug for a non-existent workspace 404s instead of redirecting', function () {
    $this->get('/does-not-exist-workspace-xyz')
        ->assertNotFound();
});

test('garbage path (non-slug shape) still 404s without hitting the database', function () {
    // Too short, contains illegal chars, has dot — none should even
    // hit the workspace lookup.
    $this->get('/AB')->assertNotFound();
    $this->get('/.env')->assertNotFound();
});

test('multi-segment paths bypass the workspace fallback entirely', function () {
    // /foo/bar must not be interpreted as workspace fallback —
    // otherwise it'd shadow nested controller misses.
    $this->get('/foo/bar')->assertNotFound();
});
