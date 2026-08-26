<?php

use App\Jobs\Crawl\CrawlPageJob;
use App\Jobs\Crawl\CrawlSourceJob;
use App\Jobs\Crawl\IngestGoogleDocJob;
use App\Models\Agent;
use App\Models\Document;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\Crawl\SourceRetrier;
use Illuminate\Support\Facades\Bus;

/**
 * The pitchbar:retry-sources self-healing sweep: transient failures
 * retry automatically (capped), permanent failures wait for a human,
 * and sources stranded mid-crawl by a dead worker get rescued — the
 * "user shouldn't have to click Reindex again and again" mechanism.
 */
function retrySweepSource(array $overrides = []): Source
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    $source = Source::create(array_merge([
        'agent_id' => $agent->id,
        'type' => 'url',
        'status' => 'failed',
        'config' => ['url' => 'https://example.com/page'],
        'error' => 'Crawl failed: Cloudflare Browser Rendering rate-limited (429).',
    ], $overrides));

    // Age the row past the sweep's 10-minute cooldown.
    Source::query()->withoutGlobalScopes()->whereKey($source->id)
        ->update(['updated_at' => now()->subMinutes(20)]);

    return $source->fresh();
}

test('a transiently-failed source is retried and the attempt is counted', function () {
    Bus::fake();
    $source = retrySweepSource();

    $this->artisan('pitchbar:retry-sources')->assertSuccessful();

    Bus::assertDispatched(CrawlSourceJob::class);

    $source->refresh();
    expect($source->status)->toBe('pending')
        ->and($source->config['auto_retry']['count'])->toBe(1);
});

test('a permanently-failed source is never auto-retried', function (string $error) {
    Bus::fake();
    $source = retrySweepSource(['error' => $error]);

    $this->artisan('pitchbar:retry-sources')->assertSuccessful();

    Bus::assertNothingDispatched();
    expect($source->fresh()->status)->toBe('failed');
})->with([
    'Blocked by robots.txt: https://example.com/page',
    "Page returned a 'not found' response (check the URL is correct): https://example.com/x",
    'Page is behind a login wall: https://example.com/members',
    'Google connection expired or was revoked — reconnect Google under Integrations. (Refresh failed: Token has been expired or revoked.)',
    'This workspace has reached its crawl quota for the month.',
]);

test('the retry cap stops the sweep after 3 attempts in one episode', function () {
    Bus::fake();
    $source = retrySweepSource();
    $config = $source->config;
    $config['auto_retry'] = ['count' => 3, 'at' => now()->subHour()->toIso8601String()];
    $source->forceFill(['config' => $config])->save();
    Source::query()->withoutGlobalScopes()->whereKey($source->id)
        ->update(['updated_at' => now()->subMinutes(20)]);

    $this->artisan('pitchbar:retry-sources')->assertSuccessful();

    Bus::assertNothingDispatched();
    expect($source->fresh()->status)->toBe('failed');
});

test('the retry counter decays after 24 quiet hours — a new episode gets fresh retries', function () {
    Bus::fake();
    $source = retrySweepSource();
    $config = $source->config;
    $config['auto_retry'] = ['count' => 3, 'at' => now()->subHours(30)->toIso8601String()];
    $source->forceFill(['config' => $config])->save();
    Source::query()->withoutGlobalScopes()->whereKey($source->id)
        ->update(['updated_at' => now()->subMinutes(20)]);

    $this->artisan('pitchbar:retry-sources')->assertSuccessful();

    Bus::assertDispatched(CrawlSourceJob::class);
    expect($source->fresh()->config['auto_retry']['count'])->toBe(1);
});

test('a source stranded in crawling is rescued regardless of its error column', function () {
    Bus::fake();
    $source = retrySweepSource(['status' => 'crawling', 'error' => null, 'type' => 'google_doc', 'config' => ['google_file_id' => 'abc']]);
    Source::query()->withoutGlobalScopes()->whereKey($source->id)
        ->update(['updated_at' => now()->subHour()]);

    $this->artisan('pitchbar:retry-sources')->assertSuccessful();

    Bus::assertDispatched(IngestGoogleDocJob::class);
});

test('a recently-active crawling source is NOT touched (still working)', function () {
    Bus::fake();
    $source = retrySweepSource(['status' => 'crawling', 'error' => null]);
    Source::query()->withoutGlobalScopes()->whereKey($source->id)
        ->update(['updated_at' => now()->subMinutes(5)]);

    $this->artisan('pitchbar:retry-sources')->assertSuccessful();

    Bus::assertNothingDispatched();
});

test('a recently-failed source waits out the cooldown before the sweep takes it', function () {
    Bus::fake();
    $source = retrySweepSource();
    Source::query()->withoutGlobalScopes()->whereKey($source->id)
        ->update(['updated_at' => now()->subMinutes(2)]);

    $this->artisan('pitchbar:retry-sources')->assertSuccessful();

    Bus::assertNothingDispatched();
});

test('the legacy "No URL configured" scar on an auto source heals through the fixed path', function () {
    Bus::fake();
    $source = retrySweepSource(['type' => 'auto', 'config' => [], 'error' => 'No URL configured']);
    Document::factory()->create([
        'source_id' => $source->id,
        'agent_id' => $source->agent_id,
        'url' => 'https://example.com/contact',
    ]);
    Source::query()->withoutGlobalScopes()->whereKey($source->id)
        ->update(['updated_at' => now()->subMinutes(20)]);

    $this->artisan('pitchbar:retry-sources')->assertSuccessful();

    Bus::assertDispatched(CrawlPageJob::class);
    expect($source->fresh()->status)->toBe('crawling');
});

test('dry-run reports but dispatches nothing', function () {
    Bus::fake();
    retrySweepSource();

    $this->artisan('pitchbar:retry-sources --dry-run')->assertSuccessful();

    Bus::assertNothingDispatched();
});

test('the transient classifier separates retryable from permanent errors', function () {
    // Transient — the sweep may retry these.
    foreach ([
        'Crawl failed: Cloudflare Browser Rendering rate-limited (429).',
        'Crawl timed out after 90s — the target page took too long to respond.',
        'Google API error: export failed: HTTP 503',
        'Queue unavailable: Connection refused [tcp://127.0.0.1:6379]',
        'Could not reach this URL after 3 attempts. Most likely the target is slow, blocked, or unreachable from our crawler.',
        'No URL configured',
    ] as $transient) {
        expect(SourceRetrier::isTransient($transient))->toBeTrue("expected transient: {$transient}");
    }

    // Permanent — a human has to act first.
    foreach ([
        'Blocked by robots.txt: https://example.com',
        "Page returned a 'not found' response (check the URL is correct): https://example.com/x",
        'Page is behind a paywall: https://example.com/story',
        'The uploaded file produced no indexed content. Re-upload the file to retry.',
        'Google connection expired or was revoked — reconnect Google under Integrations. (Refresh failed: Token has been expired or revoked.)',
        'This workspace has reached its crawl quota for the month.',
        null,
        '',
    ] as $permanent) {
        expect(SourceRetrier::isTransient($permanent))->toBeFalse('expected permanent: '.var_export($permanent, true));
    }
});
