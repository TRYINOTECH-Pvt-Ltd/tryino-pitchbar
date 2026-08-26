<?php

use App\Console\Commands\Queue\TickCommand;
use App\Models\AppSetting;
use Illuminate\Support\Facades\Process;

test('queue-tick rejects missing token with 503 when nothing is configured', function () {
    $s = AppSetting::singleton();
    $s->internal_queue_token = null;
    $s->save();
    AppSetting::flushSingleton();

    $this->postJson('/api/v1/internal/queue-tick')
        ->assertStatus(503)
        ->assertJsonPath('error.code', 'queue_tick_disabled');
});

test('queue-tick rejects wrong token with 401', function () {
    $s = AppSetting::singleton();
    $s->internal_queue_token = 'right-token';
    $s->save();
    AppSetting::flushSingleton();

    $this->postJson('/api/v1/internal/queue-tick', [], [
        'X-Pitchbar-Token' => 'wrong-token',
    ])->assertStatus(401);
});

test('queue-tick accepts the configured token and returns stats', function () {
    $s = AppSetting::singleton();
    $s->internal_queue_token = 'good-token';
    $s->save();
    AppSetting::flushSingleton();

    // Card #490 — the worker now runs OUT of process (running it inline
    // poisoned the Octane worker), so the endpoint's contract is tested
    // against a faked subprocess. The command's own behaviour is covered
    // by QueueWorkerQueueCoverageTest.
    Process::fake([
        '*' => Process::result(
            output: '{"processed":0,"failed_in_tick":0,"remaining_pending":0,"failed_total":0,"elapsed_s":0.1}',
        ),
    ]);

    $response = $this->postJson('/api/v1/internal/queue-tick', [
        'max_jobs' => 1,
        'max_time' => 5,
    ], [
        'X-Pitchbar-Token' => 'good-token',
    ])->assertOk();

    $data = $response->json('data');
    expect($data)->toHaveKeys(['exit_code', 'stats']);
    expect($data['stats'])->toHaveKeys([
        'processed',
        'failed_in_tick',
        'remaining_pending',
        'failed_total',
        'elapsed_s',
    ]);
});

test('queue-tick default queue list includes index so IndexDocumentJob actually runs', function () {
    // Regression guard for the "129 jobs pending, no failures" symptom.
    // IndexDocumentJob (file uploads, crawled pages, Notion / Google
    // ingest) dispatches onto the `index` queue. Pre-fix the default
    // tick command only ran `crawl,default`, leaving every PDF / TXT /
    // page index stranded forever. The fix is a one-line change to the
    // signature default — this test pins it so a future "narrow the
    // default" tweak can't quietly regress it.
    $command = new TickCommand;

    // Reflect the signature so we read it without booting the kernel.
    $signature = (new ReflectionClass($command))->getProperty('signature');
    $signature->setAccessible(true);
    $value = (string) $signature->getValue($command);

    expect($value)->toContain('--queues=crawl,index,default');
});

test('queue-tick signature defaults give CrawlPageJob room to breathe', function () {
    // Buyer report: dozens of MaxAttemptsExceededException for
    // CrawlPageJob. Root cause was a 25s tick + 20s per-job timeout,
    // killing crawls that legitimately take 30-60s on Cloudflare
    // Browser Rendering. Fix: bump --max-time default to 55 (still
    // inside the 60s cron cadence) and expose --job-timeout so it
    // scales with the tick budget. Pin both defaults here so a future
    // "tighten the budget" change can't quietly regress us back.
    $command = new TickCommand;

    $signature = (new ReflectionClass($command))->getProperty('signature');
    $signature->setAccessible(true);
    $value = (string) $signature->getValue($command);

    expect($value)->toContain('--max-time=55');
    expect($value)->toContain('--job-timeout=120');
});

test('queue-tick uses constant-time comparison (length-mismatched tokens are 401)', function () {
    $s = AppSetting::singleton();
    $s->internal_queue_token = 'long-secret-token-that-is-quite-long';
    $s->save();
    AppSetting::flushSingleton();

    $this->postJson('/api/v1/internal/queue-tick', [], [
        'X-Pitchbar-Token' => 'short',
    ])->assertStatus(401);
});
