<?php

use App\Models\WorkspaceApiToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('admin can revoke a token', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'admin']);

    $token = WorkspaceApiToken::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->delete('/settings/api-tokens/'.$token->id)
        ->assertRedirect();

    expect($token->refresh()->revoked_at)->not()->toBeNull();
});

test('revoking is idempotent', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'admin']);

    $token = WorkspaceApiToken::factory()->revoked()->create([
        'workspace_id' => $workspace->id,
    ]);
    $originalRevokedAt = $token->revoked_at;

    $this->actingAs($user)
        ->delete('/settings/api-tokens/'.$token->id)
        ->assertRedirect();

    expect($token->refresh()->revoked_at->equalTo($originalRevokedAt))->toBeTrue();
});

test('viewer cannot revoke', function () {
    ['user' => $user, 'workspace' => $workspace] = workspaceMember(['role' => 'viewer']);

    $token = WorkspaceApiToken::factory()->create(['workspace_id' => $workspace->id]);

    $this->actingAs($user)
        ->delete('/settings/api-tokens/'.$token->id)
        ->assertForbidden();

    expect($token->refresh()->revoked_at)->toBeNull();
});

test('cross-workspace revoke is rejected', function () {
    ['user' => $userA] = workspaceMember(['role' => 'admin']);
    ['workspace' => $workspaceB] = workspaceMember(['role' => 'admin']);

    $token = WorkspaceApiToken::factory()->create(['workspace_id' => $workspaceB->id]);

    $this->actingAs($userA)
        ->delete('/settings/api-tokens/'.$token->id)
        ->assertForbidden();
});
