<?php

use App\Jobs\Crawl\CrawlSourceJob;
use App\Models\Agent;
use App\Models\Source;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;

function makeAgent(): Agent
{
    $ws = Workspace::factory()->create();

    return Agent::factory()->create(['workspace_id' => $ws->id]);
}

test('command requeues indexed sources older than --days', function () {
    Bus::fake();
    $agent = makeAgent();

    $stale = Source::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'indexed',
        'last_synced_at' => now()->subDays(10),
    ]);
    $fresh = Source::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'indexed',
        'last_synced_at' => now()->subDay(),
    ]);

    $this->artisan('pitchbar:refresh-stale-sources', ['--days' => 7])->assertSuccessful();

    Bus::assertDispatched(CrawlSourceJob::class, fn ($job) => $job->sourceId === $stale->id);
    Bus::assertNotDispatched(CrawlSourceJob::class, fn ($job) => $job->sourceId === $fresh->id);

    expect($stale->fresh()->status)->toBe('pending');
    expect($fresh->fresh()->status)->toBe('indexed');
});

test('command treats null last_synced_at as stale', function () {
    Bus::fake();
    $agent = makeAgent();

    $never = Source::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'indexed',
        'last_synced_at' => null,
    ]);

    $this->artisan('pitchbar:refresh-stale-sources', ['--days' => 7])->assertSuccessful();

    Bus::assertDispatched(CrawlSourceJob::class, fn ($job) => $job->sourceId === $never->id);
});

test('command skips failed/crawling sources', function () {
    Bus::fake();
    $agent = makeAgent();

    $failed = Source::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'failed',
        'last_synced_at' => now()->subDays(30),
    ]);
    $crawling = Source::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'crawling',
        'last_synced_at' => now()->subDays(30),
    ]);

    $this->artisan('pitchbar:refresh-stale-sources', ['--days' => 7])->assertSuccessful();

    Bus::assertNotDispatched(CrawlSourceJob::class);
    expect($failed->fresh()->status)->toBe('failed');
    expect($crawling->fresh()->status)->toBe('crawling');
});

test('--dry-run prints but does not dispatch', function () {
    Bus::fake();
    $agent = makeAgent();
    $stale = Source::factory()->create([
        'agent_id' => $agent->id,
        'status' => 'indexed',
        'last_synced_at' => now()->subDays(10),
    ]);

    $this->artisan('pitchbar:refresh-stale-sources', ['--days' => 7, '--dry-run' => true])
        ->assertSuccessful();

    Bus::assertNotDispatched(CrawlSourceJob::class);
    expect($stale->fresh()->status)->toBe('indexed');
});

test('command respects --limit', function () {
    Bus::fake();
    $agent = makeAgent();

    for ($i = 0; $i < 5; $i++) {
        Source::factory()->create([
            'agent_id' => $agent->id,
            'status' => 'indexed',
            'last_synced_at' => now()->subDays(10 + $i),
        ]);
    }

    $this->artisan('pitchbar:refresh-stale-sources', ['--days' => 7, '--limit' => 2])
        ->assertSuccessful();

    Bus::assertDispatchedTimes(CrawlSourceJob::class, 2);
});
