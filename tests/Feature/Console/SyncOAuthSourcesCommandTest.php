<?php

use App\Jobs\Crawl\IngestGoogleDocJob;
use App\Jobs\Crawl\IngestNotionPageJob;
use App\Models\Agent;
use App\Models\Source;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;

function syncAgent(): Agent
{
    $ws = Workspace::factory()->create();

    return Agent::factory()->create(['workspace_id' => $ws->id]);
}

test('command dispatches IngestNotionPageJob for stale notion sources', function () {
    Bus::fake();
    $agent = syncAgent();

    $stale = Source::factory()->create([
        'agent_id' => $agent->id,
        'type' => 'notion',
        'status' => 'indexed',
        'last_synced_at' => now()->subHours(3),
        'config' => ['notion_page_id' => 'p1'],
    ]);

    $this->artisan('pitchbar:sync-oauth-sources', ['--hours' => 1])->assertSuccessful();

    Bus::assertDispatched(IngestNotionPageJob::class, fn ($j) => $j->sourceId === $stale->id);
});

test('command dispatches IngestGoogleDocJob for stale google_doc sources', function () {
    Bus::fake();
    $agent = syncAgent();

    $stale = Source::factory()->create([
        'agent_id' => $agent->id,
        'type' => 'google_doc',
        'status' => 'indexed',
        'last_synced_at' => now()->subHours(3),
        'config' => ['google_file_id' => 'doc-x'],
    ]);

    $this->artisan('pitchbar:sync-oauth-sources', ['--hours' => 1])->assertSuccessful();

    Bus::assertDispatched(IngestGoogleDocJob::class, fn ($j) => $j->sourceId === $stale->id);
});

test('command leaves fresh sources alone', function () {
    Bus::fake();
    $agent = syncAgent();

    Source::factory()->create([
        'agent_id' => $agent->id,
        'type' => 'notion',
        'status' => 'indexed',
        'last_synced_at' => now()->subMinutes(10),
        'config' => ['notion_page_id' => 'p1'],
    ]);

    $this->artisan('pitchbar:sync-oauth-sources', ['--hours' => 1])->assertSuccessful();

    Bus::assertNotDispatched(IngestNotionPageJob::class);
});

test('command ignores non-OAuth source types (url, sitemap)', function () {
    Bus::fake();
    $agent = syncAgent();

    Source::factory()->create([
        'agent_id' => $agent->id,
        'type' => 'url',
        'status' => 'indexed',
        'last_synced_at' => now()->subDays(5),
    ]);
    Source::factory()->create([
        'agent_id' => $agent->id,
        'type' => 'sitemap',
        'status' => 'indexed',
        'last_synced_at' => now()->subDays(5),
    ]);

    $this->artisan('pitchbar:sync-oauth-sources', ['--hours' => 1])->assertSuccessful();

    Bus::assertNotDispatched(IngestNotionPageJob::class);
    Bus::assertNotDispatched(IngestGoogleDocJob::class);
});

test('--types restricts the subset', function () {
    Bus::fake();
    $agent = syncAgent();

    Source::factory()->create([
        'agent_id' => $agent->id,
        'type' => 'notion',
        'status' => 'indexed',
        'last_synced_at' => now()->subHours(3),
        'config' => ['notion_page_id' => 'p1'],
    ]);
    Source::factory()->create([
        'agent_id' => $agent->id,
        'type' => 'google_doc',
        'status' => 'indexed',
        'last_synced_at' => now()->subHours(3),
        'config' => ['google_file_id' => 'doc-x'],
    ]);

    $this->artisan('pitchbar:sync-oauth-sources', ['--hours' => 1, '--types' => 'notion'])
        ->assertSuccessful();

    Bus::assertDispatched(IngestNotionPageJob::class);
    Bus::assertNotDispatched(IngestGoogleDocJob::class);
});

test('--dry-run prints but does not dispatch', function () {
    Bus::fake();
    $agent = syncAgent();
    $stale = Source::factory()->create([
        'agent_id' => $agent->id,
        'type' => 'notion',
        'status' => 'indexed',
        'last_synced_at' => now()->subHours(3),
        'config' => ['notion_page_id' => 'p1'],
    ]);

    $this->artisan('pitchbar:sync-oauth-sources', ['--hours' => 1, '--dry-run' => true])
        ->assertSuccessful();

    Bus::assertNotDispatched(IngestNotionPageJob::class);
    expect($stale->fresh()->status)->toBe('indexed');
});

test('command resets status to pending before dispatching', function () {
    Bus::fake();
    $agent = syncAgent();

    $stale = Source::factory()->create([
        'agent_id' => $agent->id,
        'type' => 'notion',
        'status' => 'indexed',
        'error' => 'old error',
        'last_synced_at' => now()->subHours(3),
        'config' => ['notion_page_id' => 'p1'],
    ]);

    $this->artisan('pitchbar:sync-oauth-sources', ['--hours' => 1])->assertSuccessful();

    expect($stale->fresh()->status)->toBe('pending');
    expect($stale->fresh()->error)->toBeNull();
});
