<?php

use App\Jobs\Crawl\SyncGoogleSheetJob;
use App\Models\Agent;
use App\Models\IntegrationConnection;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function googleSheetAdmin(): array
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
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    return ['user' => $user, 'workspace' => $workspace, 'agent' => $agent];
}

test('store google-sheet refuses when Google integration is not connected', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = googleSheetAdmin();

    $this->actingAs($user)
        ->post(
            "/app/agents/{$agent->id}/sources/google-sheet",
            [
                'spreadsheet' => 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz12345/edit',
                'sheet_title' => 'Sheet1',
            ],
        )
        ->assertRedirect()
        ->assertSessionHasErrors('spreadsheet');

    Bus::assertNotDispatched(SyncGoogleSheetJob::class);
});

test('store google-sheet dispatches SyncGoogleSheetJob when Google is connected', function () {
    Bus::fake();
    ['user' => $user, 'workspace' => $workspace, 'agent' => $agent] = googleSheetAdmin();

    IntegrationConnection::query()->withoutGlobalScopes()->create([
        'workspace_id' => $workspace->id,
        'kind' => 'google',
        'status' => 'active',
        'credentials_encrypted' => [
            'access_token' => 'tok',
            'refresh_token' => 'ref',
            'expires_at' => now()->addHour()->toIso8601String(),
        ],
    ]);

    $this->actingAs($user)
        ->post(
            "/app/agents/{$agent->id}/sources/google-sheet",
            [
                'spreadsheet' => 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz12345/edit',
                'sheet_title' => 'Pricing',
            ],
        )
        ->assertRedirect();

    $source = Source::query()
        ->withoutWorkspaceScope()
        ->where('agent_id', $agent->id)
        ->firstOrFail();

    expect($source->type)->toBe('google_sheet');
    expect($source->config['spreadsheet_id'])
        ->toBe('1AbCdEfGhIjKlMnOpQrStUvWxYz12345');
    expect($source->config['sheet_title'])->toBe('Pricing');

    Bus::assertDispatched(SyncGoogleSheetJob::class);
});

test('store google-sheet rejects an unrecognised id', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = googleSheetAdmin();

    $this->actingAs($user)
        ->post(
            "/app/agents/{$agent->id}/sources/google-sheet",
            [
                'spreadsheet' => 'not-a-spreadsheet',
                'sheet_title' => 'Sheet1',
            ],
        )
        ->assertRedirect()
        ->assertSessionHasErrors('spreadsheet');

    Bus::assertNotDispatched(SyncGoogleSheetJob::class);
});
