<?php

use App\Jobs\Crawl\CrawlPageJob;
use App\Jobs\Crawl\IndexDocumentJob;
use App\Models\Agent;
use App\Models\Document;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\Crawl\Contracts\Crawler;
use App\Services\Crawl\ReadabilityExtractor;
use App\Services\Crawl\RobotsTxtParser;
use Illuminate\Support\Facades\Bus;

/**
 * The crawl pipeline must never leave a Source stuck in 'crawling'.
 * Every terminal path of CrawlPageJob — whether a real exception, a silent
 * skip (robots.txt block, empty body), or success — must converge the
 * source into either 'indexed' (any doc exists) or 'failed' (no doc, with
 * a human-readable reason in `error`).
 */
function makeSource(string $type = 'url', string $url = 'https://example.com/page'): Source
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    return Source::create([
        'agent_id' => $agent->id,
        'type' => $type,
        'status' => 'crawling',
        'config' => ['url' => $url],
    ]);
}

function fakeCrawlerReturning(string $html): void
{
    app()->bind(Crawler::class, fn () => new class($html) implements Crawler
    {
        public function __construct(private string $html) {}

        public function content(string $url, array $opts = []): string
        {
            return $this->html;
        }
    });
}

function fakeRobotsAllowAll(bool $allow = true): void
{
    app()->bind(RobotsTxtParser::class, fn () => new class($allow) extends RobotsTxtParser
    {
        public function __construct(private bool $allow)
        {
            // skip parent ctor — we don't need its HTTP client.
        }

        public function isAllowed(string $url, string $userAgent = '*'): bool
        {
            return $this->allow;
        }
    });
}

test('source is marked failed when robots.txt blocks the URL', function () {
    Bus::fake();
    fakeRobotsAllowAll(false);
    fakeCrawlerReturning('<html></html>');

    $source = makeSource();

    (new CrawlPageJob($source->id, 'https://example.com/page'))
        ->handle(app(Crawler::class), app(ReadabilityExtractor::class), app(RobotsTxtParser::class));

    $source->refresh();
    expect($source->status)->toBe('failed');
    expect($source->error)->toContain('robots.txt');
});

test('source is marked failed when the rendered page has too little content', function () {
    Bus::fake();
    fakeRobotsAllowAll(true);
    fakeCrawlerReturning('<html><body>Just a moment...</body></html>');

    $source = makeSource();

    (new CrawlPageJob($source->id, 'https://example.com/page'))
        ->handle(app(Crawler::class), app(ReadabilityExtractor::class), app(RobotsTxtParser::class));

    $source->refresh();
    expect($source->status)->toBe('failed');
    expect($source->error)->toContain('too little content');
});

test('source flips to indexed when a real page is crawled', function () {
    Bus::fake();
    fakeRobotsAllowAll(true);
    $body = str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 30);
    fakeCrawlerReturning("<html><head><title>Hi</title></head><body><p>{$body}</p></body></html>");

    $source = makeSource();

    (new CrawlPageJob($source->id, 'https://example.com/page'))
        ->handle(app(Crawler::class), app(ReadabilityExtractor::class), app(RobotsTxtParser::class));

    $source->refresh();
    expect($source->status)->toBe('indexed');
    expect($source->error)->toBeNull();
    expect($source->last_synced_at)->not->toBeNull();
    Bus::assertDispatched(IndexDocumentJob::class);
});

test('source is marked failed when the page is a login wall', function () {
    Bus::fake();
    fakeRobotsAllowAll(true);

    $body = 'Please sign in to continue. '
        .str_repeat('You need to be logged in to view this content. ', 10);
    fakeCrawlerReturning("<html><head><title>Sign in</title></head><body><h1>Sign in</h1><p>{$body}</p></body></html>");

    $source = makeSource(url: 'https://members.example.com/article');

    (new CrawlPageJob($source->id, 'https://members.example.com/article'))
        ->handle(app(Crawler::class), app(ReadabilityExtractor::class), app(RobotsTxtParser::class));

    $source->refresh();
    expect($source->status)->toBe('failed');
    expect($source->error)->toContain('login wall');
});

test('source is marked failed when the page is a paywall', function () {
    Bus::fake();
    fakeRobotsAllowAll(true);

    $body = 'Subscribe to read this article. '
        .str_repeat('Become a member to access premium content. ', 10);
    fakeCrawlerReturning("<html><head><title>Premium</title></head><body><p>{$body}</p></body></html>");

    $source = makeSource(url: 'https://news.example.com/story');

    (new CrawlPageJob($source->id, 'https://news.example.com/story'))
        ->handle(app(Crawler::class), app(ReadabilityExtractor::class), app(RobotsTxtParser::class));

    $source->refresh();
    expect($source->status)->toBe('failed');
    expect($source->error)->toContain('paywall');
});

test('source is marked failed when the page requires JavaScript', function () {
    Bus::fake();
    fakeRobotsAllowAll(true);

    $body = 'Please enable JavaScript to continue. '
        .str_repeat('This site requires JavaScript to view content. ', 10);
    fakeCrawlerReturning("<html><head><title>Loading</title></head><body><noscript>JS required</noscript><p>{$body}</p></body></html>");

    $source = makeSource(url: 'https://spa.example.com/page');

    (new CrawlPageJob($source->id, 'https://spa.example.com/page'))
        ->handle(app(Crawler::class), app(ReadabilityExtractor::class), app(RobotsTxtParser::class));

    $source->refresh();
    expect($source->status)->toBe('failed');
    expect($source->error)->toContain('JavaScript');
});

test('source is marked failed when the page is a cookie-consent gate', function () {
    Bus::fake();
    fakeRobotsAllowAll(true);

    $body = 'This website uses cookies to ensure you get the best experience. '
        .str_repeat('Accept cookies to continue browsing this site. ', 10);
    fakeCrawlerReturning("<html><head><title>Cookies</title></head><body><h1>Cookie Notice</h1><p>{$body}</p></body></html>");

    $source = makeSource(url: 'https://eu-site.example.com/page');

    (new CrawlPageJob($source->id, 'https://eu-site.example.com/page'))
        ->handle(app(Crawler::class), app(ReadabilityExtractor::class), app(RobotsTxtParser::class));

    $source->refresh();
    expect($source->status)->toBe('failed');
    expect($source->error)->toContain('cookie-consent');
});

test('blocker detector does not fire on real product pages that mention login in passing', function () {
    Bus::fake();
    fakeRobotsAllowAll(true);

    // 1500+ char real product copy. Login is mentioned only deep in the body.
    $body = 'The MacBook Air M5 Chip 13-inch features a stunning Liquid Retina display. '
        .str_repeat('Apple silicon delivers incredible performance for work and play. ', 30)
        .'Sign in to your Apple ID after purchase to sync your apps.';
    fakeCrawlerReturning("<html><head><title>MacBook Air M5</title></head><body><h1>MacBook Air M5</h1><p>{$body}</p></body></html>");

    $source = makeSource(url: 'https://shop.example.com/macbook');

    (new CrawlPageJob($source->id, 'https://shop.example.com/macbook'))
        ->handle(app(Crawler::class), app(ReadabilityExtractor::class), app(RobotsTxtParser::class));

    $source->refresh();
    expect($source->status)->toBe('indexed');
    expect($source->error)->toBeNull();
});

test('source is marked failed when the page is a soft-404 (200 OK but not-found body)', function () {
    Bus::fake();
    fakeRobotsAllowAll(true);

    // Real 404 boilerplate from startech.com.bd — long enough to clear the
    // 200-char minimum, but it's still a "page cannot be found" page.
    $title = 'The page you requested cannot be found!';
    $body = 'Oops! The page you requested cannot be found. '
        .str_repeat('Continue shopping. View categories. Browse products. ', 12);
    fakeCrawlerReturning("<html><head><title>{$title}</title></head><body><h1>{$title}</h1><p>{$body}</p></body></html>");

    $source = makeSource(url: 'https://www.startech.com.bd/wrong-url');

    (new CrawlPageJob($source->id, 'https://www.startech.com.bd/wrong-url'))
        ->handle(app(Crawler::class), app(ReadabilityExtractor::class), app(RobotsTxtParser::class));

    $source->refresh();
    expect($source->status)->toBe('failed');
    expect($source->error)->toContain('not found');
});

test('failed() callback transitions the source to failed when retries exhaust', function () {
    Bus::fake();
    $source = makeSource();

    (new CrawlPageJob($source->id, 'https://example.com/page'))
        ->failed(new RuntimeException('upstream 500'));

    $source->refresh();
    expect($source->status)->toBe('failed');
    expect($source->error)->toContain('upstream 500');
});

test('the same page added as a second source gets its own document (Smart Segments per-segment scoping)', function () {
    Bus::fake();
    fakeRobotsAllowAll(true);
    $body = str_repeat('Storage units in Meppel with an all-in monthly price and 24/7 access. ', 20);
    fakeCrawlerReturning("<html><head><title>Opslag</title></head><body><p>{$body}</p></body></html>");

    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    // Source A (e.g. the "Auto-indexed from visitors" source) crawls the
    // page first and owns the content.
    $sourceA = Source::create([
        'agent_id' => $agent->id,
        'type' => 'url',
        'status' => 'crawling',
        'config' => ['url' => 'https://example.com/opslagruimtes'],
    ]);
    (new CrawlPageJob($sourceA->id, 'https://example.com/opslagruimtes'))
        ->handle(app(Crawler::class), app(ReadabilityExtractor::class), app(RobotsTxtParser::class));

    // Source B — a dedicated source the admin added to scope this page to a
    // segment — crawls the IDENTICAL content.
    $sourceB = Source::create([
        'agent_id' => $agent->id,
        'type' => 'url',
        'status' => 'crawling',
        'config' => ['url' => 'https://example.com/opslagruimtes'],
    ]);
    (new CrawlPageJob($sourceB->id, 'https://example.com/opslagruimtes'))
        ->handle(app(Crawler::class), app(ReadabilityExtractor::class), app(RobotsTxtParser::class));

    // Each source owns its OWN document — without this, source B would be
    // deduped to zero pages and could never be scoped to its segment.
    expect(Document::query()->withoutWorkspaceScope()->where('source_id', $sourceA->id)->count())->toBe(1)
        ->and(Document::query()->withoutWorkspaceScope()->where('source_id', $sourceB->id)->count())->toBe(1);

    $sourceB->refresh();
    expect($sourceB->status)->toBe('indexed')->and($sourceB->error)->toBeNull();

    // Two distinct document rows that happen to share the content hash.
    $docs = Document::query()->withoutWorkspaceScope()->where('agent_id', $agent->id)->get();
    expect($docs)->toHaveCount(2)
        ->and($docs->pluck('content_hash')->unique())->toHaveCount(1);

    // Each source's page was queued for chunking/embedding independently.
    Bus::assertDispatchedTimes(IndexDocumentJob::class, 2);
});

test('re-crawling the same source with unchanged content stays idempotent (no duplicate doc)', function () {
    Bus::fake();
    fakeRobotsAllowAll(true);
    $body = str_repeat('Unchanged page content that will be crawled twice. ', 20);
    fakeCrawlerReturning("<html><head><title>Same</title></head><body><p>{$body}</p></body></html>");

    $source = makeSource(url: 'https://example.com/stable');

    foreach (range(1, 2) as $ignored) {
        (new CrawlPageJob($source->id, 'https://example.com/stable'))
            ->handle(app(Crawler::class), app(ReadabilityExtractor::class), app(RobotsTxtParser::class));
    }

    expect(Document::query()->withoutWorkspaceScope()->where('source_id', $source->id)->count())->toBe(1);
    // Only the first crawl produced a new document to index.
    Bus::assertDispatchedTimes(IndexDocumentJob::class, 1);
});

test('once a doc exists, a later silent-skip leaves status as indexed', function () {
    Bus::fake();
    fakeRobotsAllowAll(true);

    // Pre-existing successful doc on the source — simulates earlier sitemap page.
    $source = makeSource('sitemap');
    Document::create([
        'source_id' => $source->id,
        'agent_id' => $source->agent_id,
        'url' => 'https://example.com/already-indexed',
        'title' => 'ok',
        'content_hash' => hash('sha256', 'whatever'),
        'fetched_at' => now(),
    ]);

    fakeCrawlerReturning('<html></html>'); // empty body → silent skip

    (new CrawlPageJob($source->id, 'https://example.com/another-page'))
        ->handle(app(Crawler::class), app(ReadabilityExtractor::class), app(RobotsTxtParser::class));

    $source->refresh();
    expect($source->status)->toBe('indexed');
    expect($source->error)->toBeNull();
});
