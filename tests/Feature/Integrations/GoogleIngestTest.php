<?php

use App\Jobs\Crawl\CrawlSourceJob;
use App\Jobs\Crawl\IndexDocumentJob;
use App\Jobs\Crawl\IngestGoogleDocJob;
use App\Jobs\Crawl\SyncGoogleSheetJob;
use App\Models\Agent;
use App\Models\Document;
use App\Models\IntegrationConnection;
use App\Models\Source;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Integrations\Google\GoogleClient;
use App\Services\Integrations\Google\GoogleException;
use App\Services\Integrations\Google\GoogleTokenStore;
use App\Support\SourceErrorPresenter;
use Illuminate\Support\Facades\Bus;

function googleWorkspace(bool $connect = true): array
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

    if ($connect) {
        IntegrationConnection::create([
            'workspace_id' => $ws->id,
            'kind' => 'google',
            'credentials_encrypted' => [
                'access_token' => 'ya29.access',
                'refresh_token' => '1//refresh',
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            'status' => 'active',
        ]);
    }

    return ['user' => $user, 'workspace' => $ws, 'agent' => $agent];
}

test('storeGoogleDoc accepts a docs URL and dispatches the job', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = googleWorkspace();

    $url = 'https://docs.google.com/document/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/edit';

    $this->actingAs($user)
        ->post("/app/agents/{$agent->id}/sources/google-doc", ['doc' => $url])
        ->assertRedirect();

    $source = Source::query()->withoutGlobalScopes()
        ->where('agent_id', $agent->id)
        ->where('type', 'google_doc')
        ->first();

    expect($source)->not->toBeNull();
    expect($source->config['google_file_id'])->toBe('1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789');
    Bus::assertDispatched(IngestGoogleDocJob::class);
});

test('storeGoogleDoc rejects when Google is not connected', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = googleWorkspace(connect: false);

    $this->actingAs($user)
        ->from("/app/agents/{$agent->id}/sources")
        ->post("/app/agents/{$agent->id}/sources/google-doc", ['doc' => '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789'])
        ->assertSessionHasErrors('doc');

    Bus::assertNotDispatched(IngestGoogleDocJob::class);
});

test('IngestGoogleDocJob exports the doc, creates Document, dispatches IndexDocumentJob', function () {
    Bus::fake([IndexDocumentJob::class]);
    ['agent' => $agent] = googleWorkspace();

    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'google_doc',
        'status' => 'pending',
        'config' => ['google_file_id' => 'doc-123'],
    ]);

    $client = Mockery::mock(GoogleClient::class);
    $client->shouldReceive('getDoc')->once()
        ->andReturn([
            'title' => 'Onboarding Runbook',
            'text' => str_repeat('This is the onboarding runbook content. ', 25),
            'modified_time' => '2026-05-01T10:00:00Z',
        ]);

    (new IngestGoogleDocJob($source->id))->handle($client, app(GoogleTokenStore::class));

    $source->refresh();
    expect($source->status)->toBe('indexed');
    expect($source->error)->toBeNull();
    $doc = Document::query()->withoutGlobalScopes()->where('source_id', $source->id)->first();
    expect($doc)->not->toBeNull();
    expect($doc->title)->toBe('Onboarding Runbook');
    expect($doc->url)->toBe('gdoc://doc-123');
    Bus::assertDispatched(IndexDocumentJob::class);
});

test('IngestGoogleDocJob fails when Google is not connected', function () {
    ['agent' => $agent] = googleWorkspace(connect: false);

    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'google_doc',
        'status' => 'pending',
        'config' => ['google_file_id' => 'doc-x'],
    ]);

    (new IngestGoogleDocJob($source->id))->handle(
        app(GoogleClient::class),
        app(GoogleTokenStore::class),
    );

    $source->refresh();
    expect($source->status)->toBe('failed');
    expect($source->error)->toContain('Google is not connected');
});

test('GoogleTokenStore refreshes when expired and persists the new token', function () {
    ['workspace' => $ws] = googleWorkspace();
    $integration = IntegrationConnection::query()->withoutGlobalScopes()
        ->where('workspace_id', $ws->id)->where('kind', 'google')->first();

    // Mark as expired.
    $integration->update([
        'credentials_encrypted' => [
            'access_token' => 'stale',
            'refresh_token' => '1//refresh',
            'expires_at' => now()->subMinute()->toIso8601String(),
        ],
    ]);

    $client = Mockery::mock(GoogleClient::class);
    $client->shouldReceive('refresh')->once()->with('1//refresh')
        ->andReturn(['access_token' => 'fresh', 'expires_in' => 3600]);

    $store = new GoogleTokenStore($client);
    $token = $store->activeAccessToken($integration);

    expect($token)->toBe('fresh');
    $integration->refresh();
    expect($integration->credentials_encrypted['access_token'])->toBe('fresh');
});

test('GoogleTokenStore returns the cached token when not expired', function () {
    ['workspace' => $ws] = googleWorkspace();
    $integration = IntegrationConnection::query()->withoutGlobalScopes()
        ->where('workspace_id', $ws->id)->where('kind', 'google')->first();

    $client = Mockery::mock(GoogleClient::class);
    $client->shouldNotReceive('refresh');

    $store = new GoogleTokenStore($client);
    expect($store->activeAccessToken($integration))->toBe('ya29.access');
});

test('a revoked refresh token flips the connection to expired and tells the owner to reconnect', function () {
    // Client report (2026-07-03): a Google Doc source failed with the
    // catch-all "Couldn't index this page." while the real cause was
    // "Refresh failed: Token has been expired or revoked." — and the
    // connection stayed 'active', so nothing anywhere suggested a
    // reconnect.
    ['workspace' => $ws, 'agent' => $agent] = googleWorkspace();
    $integration = IntegrationConnection::query()->withoutGlobalScopes()
        ->where('workspace_id', $ws->id)->where('kind', 'google')->first();
    $integration->update([
        'credentials_encrypted' => [
            'access_token' => 'stale',
            'refresh_token' => '1//dead',
            'expires_at' => now()->subMinute()->toIso8601String(),
        ],
    ]);

    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'google_doc',
        'status' => 'pending',
        'config' => ['google_file_id' => 'doc-revoked'],
    ]);

    $client = Mockery::mock(GoogleClient::class);
    $client->shouldReceive('refresh')->once()
        ->andThrow(new GoogleException(
            'Refresh failed: Token has been expired or revoked.',
        ));

    (new IngestGoogleDocJob($source->id))->handle($client, new GoogleTokenStore($client));

    $source->refresh();
    $integration->refresh();

    expect($source->status)->toBe('failed')
        ->and($source->error)->toContain('reconnect Google')
        // The connection now reflects reality — the Integrations page
        // shows the reconnect state and new Google sources are blocked
        // with a clear message instead of being created doomed.
        ->and($integration->status)->toBe('expired');

    // And the customer-facing line is actionable, not the catch-all.
    $presented = SourceErrorPresenter::present($source->error);
    expect($presented)->toContain('Reconnect Google')
        ->and($presented)->not->toBe("Couldn't index this page.");
});

test('a transient refresh failure keeps the connection active', function () {
    ['workspace' => $ws, 'agent' => $agent] = googleWorkspace();
    $integration = IntegrationConnection::query()->withoutGlobalScopes()
        ->where('workspace_id', $ws->id)->where('kind', 'google')->first();
    $integration->update([
        'credentials_encrypted' => [
            'access_token' => 'stale',
            'refresh_token' => '1//refresh',
            'expires_at' => now()->subMinute()->toIso8601String(),
        ],
    ]);

    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'google_doc',
        'status' => 'pending',
        'config' => ['google_file_id' => 'doc-transient'],
    ]);

    $client = Mockery::mock(GoogleClient::class);
    $client->shouldReceive('refresh')->once()
        ->andThrow(new GoogleException('Refresh failed: unknown'));

    (new IngestGoogleDocJob($source->id))->handle($client, new GoogleTokenStore($client));

    $source->refresh();
    $integration->refresh();

    expect($source->status)->toBe('failed')
        ->and($integration->status)->toBe('active');
});

test('an exhausted sheet sync marks the source failed instead of stranding it in crawling', function () {
    // SyncGoogleSheetJob was the only indexing job with no failed()
    // handler — retries exhausted -> the source sat in 'crawling'
    // forever (audit 2026-07-04).
    ['agent' => $agent] = googleWorkspace();
    $source = Source::create([
        'agent_id' => $agent->id,
        'type' => 'google_sheet',
        'status' => 'crawling',
        'config' => ['spreadsheet_id' => 'sheet-1', 'sheet_title' => 'Prices'],
    ]);

    (new SyncGoogleSheetJob($source->id))
        ->failed(new RuntimeException('upstream 500'));

    $source->refresh();
    expect($source->status)->toBe('failed')
        ->and($source->error)->toContain('upstream 500');
});

test('a Google Docs link pasted into the URL form becomes a Google Doc source', function () {
    // Client pasted a docs.google.com link into "Add URL" — the web
    // crawler can never read it (JS app + bot protection), so the form
    // now absorbs it into the proper source type (2026-07-04).
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = googleWorkspace();

    $this->actingAs($user)
        ->post("/app/agents/{$agent->id}/sources", [
            'type' => 'url',
            'url' => 'https://docs.google.com/document/d/1xnTuS-PlqdIHf8xcBkl_5AYf_MywcCrsAkaqXtVtbFl/edit?usp=sharing',
        ])
        ->assertRedirect();

    $source = Source::query()->withoutGlobalScopes()
        ->where('agent_id', $agent->id)->first();

    expect($source)->not->toBeNull()
        ->and($source->type)->toBe('google_doc')
        ->and($source->config['google_file_id'])->toBe('1xnTuS-PlqdIHf8xcBkl_5AYf_MywcCrsAkaqXtVtbFl');
    Bus::assertDispatched(IngestGoogleDocJob::class);
    Bus::assertNotDispatched(CrawlSourceJob::class);
});

test('a Google Docs link in the URL form without a Google connection guides instead of crawling', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = googleWorkspace(connect: false);

    $this->actingAs($user)
        ->from("/app/agents/{$agent->id}/sources")
        ->post("/app/agents/{$agent->id}/sources", [
            'type' => 'url',
            'url' => 'https://docs.google.com/document/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/edit',
        ])
        ->assertSessionHasErrors('url');

    expect(Source::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->exists())->toBeFalse();
    Bus::assertNothingDispatched();
});

test('a Google Sheets link in the URL form points at the Sheet tab', function () {
    Bus::fake();
    ['user' => $user, 'agent' => $agent] = googleWorkspace();

    $this->actingAs($user)
        ->from("/app/agents/{$agent->id}/sources")
        ->post("/app/agents/{$agent->id}/sources", [
            'type' => 'url',
            'url' => 'https://docs.google.com/spreadsheets/d/1SheetIdSheetIdSheetIdSheetId/edit#gid=0',
        ])
        ->assertSessionHasErrors('url');

    expect(Source::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->exists())->toBeFalse();
    Bus::assertNothingDispatched();
});
