<?php

use App\Jobs\Crawl\CrawlPageJob;
use App\Models\Agent;
use App\Models\Document;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\Crawl\AutoIndexPageVisit;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

beforeEach(function () {
    Bus::fake();
    Cache::flush();
});

function autoIndexAgent(array $overrides = []): Agent
{
    $workspace = Workspace::factory()->create();

    return Agent::factory()->create([
        'workspace_id' => $workspace->id,
        'auto_index_visited_pages' => true,
        'allowed_origins' => ['https://shop.example.com'],
        ...$overrides,
    ]);
}

test('dispatches a crawl when conditions match and creates an auto Source', function () {
    $agent = autoIndexAgent();

    $ok = app(AutoIndexPageVisit::class)->attempt($agent, 'https://shop.example.com/products/macbook-air-m5');

    expect($ok)->toBeTrue();

    $source = Source::query()->withoutWorkspaceScope()
        ->where('agent_id', $agent->id)
        ->where('type', 'auto')
        ->first();
    expect($source)->not->toBeNull();

    Bus::assertDispatched(CrawlPageJob::class, fn ($job) => $job->sourceId === $source->id
        && $job->url === 'https://shop.example.com/products/macbook-air-m5');
});

test('does nothing when the toggle is off', function () {
    $agent = autoIndexAgent(['auto_index_visited_pages' => false]);

    $ok = app(AutoIndexPageVisit::class)->attempt($agent, 'https://shop.example.com/x');

    expect($ok)->toBeFalse();
    Bus::assertNotDispatched(CrawlPageJob::class);
});

test('refuses URLs whose origin is not in allowed_origins', function () {
    $agent = autoIndexAgent(['allowed_origins' => ['https://shop.example.com']]);

    $ok = app(AutoIndexPageVisit::class)->attempt($agent, 'https://malicious.com/anywhere');

    expect($ok)->toBeFalse();
    Bus::assertNotDispatched(CrawlPageJob::class);
});

test('refuses to dispatch when allowed_origins is empty (allow-all is unsafe for auto-indexing)', function () {
    $agent = autoIndexAgent(['allowed_origins' => []]);

    $ok = app(AutoIndexPageVisit::class)->attempt($agent, 'https://anything.com/page');

    expect($ok)->toBeFalse();
});

test('refuses to dispatch with wildcard allowed_origins when no request origin is provided', function () {
    $agent = autoIndexAgent(['allowed_origins' => ['*']]);

    $ok = app(AutoIndexPageVisit::class)->attempt($agent, 'https://shop.example.com/x');

    expect($ok)->toBeFalse();
});

test('wildcard allowed_origins still allows auto-index when page_url matches the verified request origin', function () {
    // The classic "I just put * in allowed_origins for now" flow — must
    // not silently break auto-index. The InitController already gated
    // the request's Origin header against allowed_origins; we just have
    // to make sure the page_url is on that same origin.
    $agent = autoIndexAgent(['allowed_origins' => ['*']]);

    $ok = app(AutoIndexPageVisit::class)
        ->attempt($agent, 'https://shop.example.com/products/red-pen', 'https://shop.example.com');

    expect($ok)->toBeTrue();
    Bus::assertDispatched(CrawlPageJob::class, fn ($job) => $job->url === 'https://shop.example.com/products/red-pen');
});

test('wildcard allowed_origins refuses to crawl a page on a DIFFERENT origin than the request', function () {
    // Defense: a malicious site can't send a fake page_url pointing at
    // an unrelated domain just because allowed_origins contains '*'.
    $agent = autoIndexAgent(['allowed_origins' => ['*']]);

    $ok = app(AutoIndexPageVisit::class)
        ->attempt($agent, 'https://attacker.example/x', 'https://shop.example.com');

    expect($ok)->toBeFalse();
    Bus::assertNotDispatched(CrawlPageJob::class);
});

test('skips private-looking paths', function () {
    $agent = autoIndexAgent();

    foreach ([
        'https://shop.example.com/account',
        'https://shop.example.com/admin/orders',
        'https://shop.example.com/checkout',
        'https://shop.example.com/cart/add',
        'https://shop.example.com/login',
        'https://shop.example.com/my-account/profile',
    ] as $url) {
        expect(app(AutoIndexPageVisit::class)->attempt($agent, $url))->toBeFalse();
    }

    Bus::assertNotDispatched(CrawlPageJob::class);
});

test('skips URLs already indexed for this agent (dedup)', function () {
    $agent = autoIndexAgent();

    Document::create([
        'id' => (string) Str::uuid7(),
        'source_id' => Source::factory()->create(['agent_id' => $agent->id])->id,
        'agent_id' => $agent->id,
        'url' => 'https://shop.example.com/products/macbook-air-m5',
        'title' => 'Already crawled',
        'content_hash' => str_repeat('a', 64),
        'fetched_at' => now(),
    ]);

    $ok = app(AutoIndexPageVisit::class)->attempt($agent, 'https://shop.example.com/products/macbook-air-m5');

    expect($ok)->toBeFalse();
    Bus::assertNotDispatched(CrawlPageJob::class);
});

test('normalizes URLs (trailing slash, query strings, fragments) before dedup check', function () {
    $agent = autoIndexAgent();

    Document::create([
        'id' => (string) Str::uuid7(),
        'source_id' => Source::factory()->create(['agent_id' => $agent->id])->id,
        'agent_id' => $agent->id,
        'url' => 'https://shop.example.com/products/macbook',
        'title' => 't',
        'content_hash' => str_repeat('b', 64),
        'fetched_at' => now(),
    ]);

    // Same canonical URL but with trailing slash + tracking params + fragment
    $ok = app(AutoIndexPageVisit::class)
        ->attempt($agent, 'https://shop.example.com/products/macbook/?utm_source=foo#hash');

    expect($ok)->toBeFalse();
});

test('honors per-agent rate limit (30/hour)', function () {
    $agent = autoIndexAgent();
    $service = app(AutoIndexPageVisit::class);

    // Pre-fill the rate-limit bucket to exhausted.
    $key = "auto-index:agent:{$agent->id}:hour:".now()->format('YmdH');
    Cache::put($key, AutoIndexPageVisit::MAX_PER_HOUR, now()->addHour());

    $ok = $service->attempt($agent, 'https://shop.example.com/products/x');

    expect($ok)->toBeFalse();
    Bus::assertNotDispatched(CrawlPageJob::class);
});

test('reuses the same auto Source on subsequent dispatches', function () {
    $agent = autoIndexAgent();
    $service = app(AutoIndexPageVisit::class);

    $service->attempt($agent, 'https://shop.example.com/products/a');
    $service->attempt($agent, 'https://shop.example.com/products/b');

    $autoSourceCount = Source::query()->withoutWorkspaceScope()
        ->where('agent_id', $agent->id)
        ->where('type', 'auto')
        ->count();
    expect($autoSourceCount)->toBe(1);

    Bus::assertDispatchedTimes(CrawlPageJob::class, 2);
});

test('rejects malformed URLs and non-http schemes silently', function () {
    $agent = autoIndexAgent();
    $service = app(AutoIndexPageVisit::class);

    expect($service->attempt($agent, 'not-a-url'))->toBeFalse();
    expect($service->attempt($agent, 'javascript:alert(1)'))->toBeFalse();
    expect($service->attempt($agent, 'file:///etc/passwd'))->toBeFalse();

    Bus::assertNotDispatched(CrawlPageJob::class);
});

test('refuses private/internal hosts even when the owner listed them in allowed_origins', function () {
    // Worst-case: owner accidentally added intranet to allowed_origins.
    foreach ([
        'http://localhost',
        'http://localhost:3000',
        'http://127.0.0.1',
        'http://10.0.0.5',
        'http://192.168.1.1',
        'http://172.16.0.5',
        'http://169.254.169.254',  // AWS metadata service
        'http://intranet.local',
        'http://wiki.internal',
        'http://[::1]',
    ] as $url) {
        $origin = preg_replace('#^(https?://[^/]+).*$#', '$1', $url);
        $agent = autoIndexAgent(['allowed_origins' => [$origin]]);

        $ok = app(AutoIndexPageVisit::class)->attempt($agent, $url.'/anything');
        expect($ok)->toBeFalse("expected refusal for {$url}");
    }

    Bus::assertNotDispatched(CrawlPageJob::class);
});

test('strips user:pass credentials from URLs before storing/dispatching', function () {
    $agent = autoIndexAgent();

    $ok = app(AutoIndexPageVisit::class)
        ->attempt($agent, 'https://alice:supersecret@shop.example.com/products/x');

    expect($ok)->toBeTrue();
    Bus::assertDispatched(CrawlPageJob::class, function ($job) {
        // Credentials must NOT appear in the URL passed to the crawler.
        return ! str_contains($job->url, 'alice')
            && ! str_contains($job->url, 'supersecret')
            && $job->url === 'https://shop.example.com/products/x';
    });
});

test('flips an auto Source out of "failed" back to "crawling" when a new visit comes in', function () {
    $agent = autoIndexAgent();

    // Simulate a previous run that left the auto Source failed.
    $stale = Source::create([
        'agent_id' => $agent->id,
        'type' => 'auto',
        'status' => 'failed',
        'error' => 'old crawl failed',
        'config' => ['label' => 'Auto-indexed from visitors'],
    ]);

    $ok = app(AutoIndexPageVisit::class)->attempt($agent, 'https://shop.example.com/products/new');

    expect($ok)->toBeTrue();
    $stale->refresh();
    expect($stale->status)->toBe('crawling');
    expect($stale->error)->toBeNull();
});

test('rate limit holds steady under repeated calls (atomic increment is monotonic)', function () {
    $agent = autoIndexAgent();
    $service = app(AutoIndexPageVisit::class);

    // Fire MAX_PER_HOUR distinct URLs — all should pass.
    for ($i = 0; $i < AutoIndexPageVisit::MAX_PER_HOUR; $i++) {
        expect($service->attempt($agent, "https://shop.example.com/products/{$i}"))->toBeTrue();
    }

    // The next one (over budget) must be rejected.
    expect($service->attempt($agent, 'https://shop.example.com/products/over-limit'))->toBeFalse();

    Bus::assertDispatchedTimes(CrawlPageJob::class, AutoIndexPageVisit::MAX_PER_HOUR);
});

test('factory default has auto_index_visited_pages on', function () {
    // Regression: catches a forgotten factory state if someone changes the column default.
    $agent = autoIndexAgent();
    expect($agent->auto_index_visited_pages)->toBeTrue();
});
