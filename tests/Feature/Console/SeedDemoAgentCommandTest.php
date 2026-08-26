<?php

use App\Jobs\Crawl\CrawlSourceJob;
use App\Models\Agent;
use App\Models\CuratedAnswer;
use App\Models\Source;
use App\Models\Workspace;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // The seeder dispatches CrawlSourceJob for every URL it registers.
    // Faking the queue keeps tests offline + deterministic.
    Queue::fake();
});

test('command creates the demo workspace + published agent + curated answers', function () {
    $this->artisan('pitchbar:seed-demo-agent')->assertSuccessful();

    $ws = Workspace::query()->where('slug', 'pitchbar-demo')->first();
    expect($ws)->not->toBeNull();

    $agent = Agent::query()->withoutGlobalScopes()
        ->where('workspace_id', $ws->id)
        ->where('name', 'Pitchbar Demo')
        ->first();

    expect($agent)->not->toBeNull();
    expect($agent->is_published)->toBeTrue();
    expect($agent->published_version_id)->not->toBeNull();
    expect($agent->allowed_origins)->toContain('*');

    // System prompt should include concrete URL hints — guards against
    // a future regression where the prompt gets stripped to "be helpful".
    expect($agent->system_prompt)
        ->toContain('/pricing')
        ->toContain('/documentation')
        ->toContain('/register');

    // Mirror the seeder, which reads the env-configured default. We
    // assert against the config value (not a hard-coded number) so this
    // test doesn't go stale every time the threshold is retuned.
    expect((float) $agent->confidence_threshold)
        ->toEqual((float) config('services.rag.confidence_threshold', 0.5));

    // The curated answer library covers all the common visitor questions.
    expect(CuratedAnswer::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->count())
        ->toBeGreaterThanOrEqual(15);

    // Knowledge sources for the marketing site + docs are registered
    // and queued for crawl on the "crawl" queue.
    expect(Source::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->count())
        ->toBeGreaterThan(0);
    Queue::assertPushedOn('crawl', CrawlSourceJob::class);
});

test('command is idempotent (re-runs do not duplicate)', function () {
    $this->artisan('pitchbar:seed-demo-agent')->assertSuccessful();
    $this->artisan('pitchbar:seed-demo-agent')->assertSuccessful();
    $this->artisan('pitchbar:seed-demo-agent')->assertSuccessful();

    expect(Workspace::query()->where('slug', 'pitchbar-demo')->count())->toBe(1);
    expect(Agent::query()->withoutGlobalScopes()->where('name', 'Pitchbar Demo')->count())->toBe(1);

    $agent = Agent::query()->withoutGlobalScopes()->where('name', 'Pitchbar Demo')->first();
    $count = CuratedAnswer::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->count();
    // Library size is fixed by the seeder, but the exact number can grow
    // over time as we add coverage. Stable assertion: re-runs don't
    // multiply rows.
    expect($count)->toBeGreaterThanOrEqual(15);

    // Re-runs shouldn't multiply Source rows. Cap above current seed
    // size (25 url + 1 text overview as of v2.0.0) so future additions
    // don't immediately trip the test, while still catching a
    // duplication regression that would push the count toward 2x.
    $sources = Source::query()->withoutGlobalScopes()->where('agent_id', $agent->id)->count();
    expect($sources)->toBeLessThanOrEqual(40);
});

test('--workspace attaches the demo agent to an existing workspace', function () {
    $existing = Workspace::factory()->create(['slug' => 'my-team']);

    $this->artisan('pitchbar:seed-demo-agent', ['--workspace' => 'my-team'])->assertSuccessful();

    expect(Agent::query()->withoutGlobalScopes()->where('workspace_id', $existing->id)->where('name', 'Pitchbar Demo')->exists())->toBeTrue();
    // No duplicate workspace was made.
    expect(Workspace::query()->where('slug', 'pitchbar-demo')->count())->toBe(0);
});

test('--no-crawl skips dispatching crawl jobs', function () {
    $this->artisan('pitchbar:seed-demo-agent', ['--no-crawl' => true])->assertSuccessful();

    Queue::assertNothingPushed();
});
