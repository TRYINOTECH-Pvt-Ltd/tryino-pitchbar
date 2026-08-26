<?php

use App\Models\WorkspaceApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can forget (hard-delete) an active token', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'admin']);

    $token = WorkspaceApiToken::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->delete("/settings/api-tokens/{$token->id}/forget")
        ->assertRedirect();

    expect(WorkspaceApiToken::query()->find($token->id))->toBeNull();
});

test('admin can forget an already-revoked token', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'admin']);

    $token = WorkspaceApiToken::factory()->revoked()->create([
        'workspace_id' => $workspace->id,
    ]);

    $this->actingAs($user)
        ->delete("/settings/api-tokens/{$token->id}/forget")
        ->assertRedirect();

    expect(WorkspaceApiToken::query()->find($token->id))->toBeNull();
});

test('purge-revoked hard-deletes only revoked tokens for the workspace', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'admin']);

    $active = WorkspaceApiToken::factory()->create(['workspace_id' => $workspace->id]);
    $oldRevoked = WorkspaceApiToken::factory()->revoked()->create(['workspace_id' => $workspace->id]);
    $newerRevoked = WorkspaceApiToken::factory()->revoked()->create(['workspace_id' => $workspace->id]);

    // Foreign-workspace revoked token must stay — purge is per-workspace.
    ['workspace' => $foreignWs] = workspaceMember(['role' => 'admin']);
    $foreignRevoked = WorkspaceApiToken::factory()->revoked()->create(['workspace_id' => $foreignWs->id]);

    $this->actingAs($user)
        ->post('/settings/api-tokens/purge-revoked')
        ->assertRedirect();

    expect(WorkspaceApiToken::query()->find($active->id))->not->toBeNull();
    expect(WorkspaceApiToken::query()->find($oldRevoked->id))->toBeNull();
    expect(WorkspaceApiToken::query()->find($newerRevoked->id))->toBeNull();
    expect(WorkspaceApiToken::query()->find($foreignRevoked->id))->not->toBeNull();
});

test('viewer cannot forget a token', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'viewer']);

    $token = WorkspaceApiToken::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->delete("/settings/api-tokens/{$token->id}/forget")
        ->assertForbidden();

    expect(WorkspaceApiToken::query()->find($token->id))->not->toBeNull();
});

test('cross-workspace forget is forbidden', function () {
    ['user' => $userA] = workspaceMember(['role' => 'admin']);
    ['workspace' => $workspaceB] = workspaceMember(['role' => 'admin']);

    $token = WorkspaceApiToken::factory()->create(['workspace_id' => $workspaceB->id]);

    $this->actingAs($userA)
        ->delete("/settings/api-tokens/{$token->id}/forget")
        ->assertForbidden();

    expect(WorkspaceApiToken::query()->find($token->id))->not->toBeNull();
});
