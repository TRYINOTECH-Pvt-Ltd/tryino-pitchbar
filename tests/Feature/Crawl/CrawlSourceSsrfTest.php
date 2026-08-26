<?php

use App\Jobs\Crawl\CrawlPageJob;
use App\Jobs\Crawl\CrawlSourceJob;
use App\Models\Agent;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\Crawl\PlainHttpCrawler;
use App\Services\Crawl\SitemapDiscoverer;
use App\Support\UrlSafetyGuard;
use Illuminate\Support\Facades\Bus;

/**
 * Pins the SSRF gate on the explicit "Add Source" flow. Without this
 * guard, a workspace admin could enter `http://169.254.169.254/...`
 * (AWS metadata), `http://localhost:6379/` (loopback Redis), or
 * `http://10.0.0.5/admin` (intranet) and the crawler would happily
 * fetch + store the response into their own KB.
 *
 * CrawlSourceJob runs first; its `UrlSafetyGuard::assertSafe` call
 * flips the source to `status=failed` BEFORE any CrawlPageJob is
 * dispatched. We assert the source row carries a clear reason and
 * no page job ever lands on the queue.
 */
function ssrfTestSource(string $url, string $type = 'url'): Source
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    return Source::create([
        'agent_id' => $agent->id,
        'type' => $type,
        'status' => 'pending',
        'config' => ['url' => $url],
    ]);
}

test('CrawlSourceJob rejects AWS metadata URL + sets a clear failure reason', function () {
    Bus::fake();

    $source = ssrfTestSource('http://169.254.169.254/latest/meta-data/');

    (new CrawlSourceJob($source->id))->handle(
        app(SitemapDiscoverer::class),
        app(UrlSafetyGuard::class),
    );

    $source->refresh();
    expect($source->status)->toBe('failed');
    expect($source->error)->toContain('internal');
    Bus::assertNotDispatched(CrawlPageJob::class);
});

test('CrawlSourceJob rejects loopback + 10/8 + 192.168/16 URLs', function (string $url) {
    Bus::fake();
    $source = ssrfTestSource($url);

    (new CrawlSourceJob($source->id))->handle(
        app(SitemapDiscoverer::class),
        app(UrlSafetyGuard::class),
    );

    expect($source->fresh()->status)->toBe('failed');
    Bus::assertNotDispatched(CrawlPageJob::class);
})->with([
    'localhost' => ['http://localhost/'],
    '127.0.0.1' => ['http://127.0.0.1/admin'],
    '10/8' => ['http://10.0.0.5/'],
    '192.168/16' => ['http://192.168.1.1/router'],
    '::1' => ['http://[::1]/'],
]);

test('CrawlSourceJob rejects non-http(s) schemes (file://, gopher://)', function () {
    Bus::fake();
    $source = ssrfTestSource('file:///etc/passwd');

    (new CrawlSourceJob($source->id))->handle(
        app(SitemapDiscoverer::class),
        app(UrlSafetyGuard::class),
    );

    expect($source->fresh()->status)->toBe('failed');
    expect($source->fresh()->error)->toContain('scheme');
    Bus::assertNotDispatched(CrawlPageJob::class);
});

test('CrawlSourceJob still dispatches CrawlPageJob for ordinary public URLs', function () {
    Bus::fake();
    $source = ssrfTestSource('https://example.com/path');

    (new CrawlSourceJob($source->id))->handle(
        app(SitemapDiscoverer::class),
        app(UrlSafetyGuard::class),
    );

    expect($source->fresh()->status)->toBe('crawling');
    Bus::assertDispatched(CrawlPageJob::class);
});

test('PlainHttpCrawler refuses unsafe URLs at the fetch layer (defence-in-depth)', function () {
    $crawler = new PlainHttpCrawler(
        app(UrlSafetyGuard::class),
    );

    expect(fn () => $crawler->content('http://169.254.169.254/latest/'))
        ->toThrow(RuntimeException::class, 'unsafe URL');
    expect(fn () => $crawler->content('http://127.0.0.1:6379/info'))
        ->toThrow(RuntimeException::class, 'unsafe URL');
});
