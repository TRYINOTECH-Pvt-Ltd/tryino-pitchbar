<?php

use App\Jobs\Crawl\CrawlPageJob;
use App\Models\Agent;
use App\Models\Source;
use App\Models\Workspace;
use App\Services\Crawl\Contracts\Crawler;
use App\Services\Crawl\ReadabilityExtractor;
use App\Services\Crawl\RobotsTxtParser;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\Bus;
use Mockery as M;

uses(RefreshDatabase::class);

/**
 * The buyer's prod logs showed dozens of `MaxAttemptsExceededException`
 * stacking up for CrawlPageJob — the job was burning all 5 retry slots
 * on permanent failures (dead host, hard 4xx) and the Source row was
 * stranded in `status='crawling'`. The fix:
 *   1. Drop $tries from 5 → 3 — anything that still fails after a 30s/
 *      90s/180s backoff cascade is almost certainly permanent.
 *   2. Pin per-job $timeout = 90 + $failOnTimeout = true so the worker
 *      respects the crawl budget AND the failure callback runs on kill.
 *   3. Detect permanent failures (DNS, hard 4xx, malformed URL, TLS) and
 *      call $this->fail() so we skip the remaining retry slots.
 *   4. Humanize the customer-facing error so the operator sees
 *      "Could not reach this URL after 3 attempts" instead of the
 *      Laravel exception classname.
 */
function makeRetryPolicySource(string $url = 'https://example.com/page'): Source
{
    $workspace = Workspace::factory()->create();
    $agent = Agent::factory()->create(['workspace_id' => $workspace->id]);

    return Source::create([
        'agent_id' => $agent->id,
        'type' => 'url',
        'status' => 'crawling',
        'config' => ['url' => $url],
    ]);
}

function fakeRobotsAllowAllRetry(bool $allow = true): void
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

function fakeCrawlerThrowing(string $message): void
{
    app()->bind(Crawler::class, fn () => new class($message) implements Crawler
    {
        public function __construct(private string $message) {}

        public function content(string $url, array $opts = []): string
        {
            throw new RuntimeException($this->message);
        }
    });
}

test('CrawlPageJob exposes $tries = 3 with conservative backoff', function () {
    $job = new CrawlPageJob('fake', 'https://example.com');

    expect($job->tries)->toBe(3);
    expect($job->backoff())->toBe([30, 90, 180]);
});

test('CrawlPageJob sets a 90s per-job timeout with failOnTimeout flag', function () {
    $job = new CrawlPageJob('fake', 'https://example.com');

    expect($job->timeout)->toBe(90);
    expect($job->failOnTimeout)->toBeTrue();
});

function fakeQueueJob(): QueueJobContract
{
    $captured = (object) ['released' => false, 'releaseDelay' => 0, 'failedWith' => null];

    $mock = M::mock(QueueJobContract::class);
    $mock->shouldReceive('release')->andReturnUsing(function ($delay = 0) use ($captured) {
        $captured->released = true;
        $captured->releaseDelay = $delay;
    });
    $mock->shouldReceive('fail')->andReturnUsing(function ($e = null) use ($captured) {
        $captured->failedWith = $e instanceof Throwable ? $e : null;
    });
    $mock->shouldReceive('isReleased')->andReturnUsing(fn () => $captured->released);
    $mock->shouldReceive('isDeletedOrReleased')->andReturnUsing(
        fn () => $captured->released || $captured->failedWith !== null,
    );
    $mock->shouldReceive('hasFailed')->andReturnUsing(
        fn () => $captured->failedWith !== null,
    );
    // Sundry methods called by Laravel's queue plumbing during a job
    // run — none of them need to do anything for these unit-style tests.
    $mock->shouldReceive('uuid', 'getJobId', 'payload', 'attempts', 'getName',
        'resolveName', 'getConnectionName', 'getQueue', 'getRawBody', 'maxTries',
        'maxExceptions', 'timeout', 'retryUntil', 'delete', 'isDeleted',
        'markAsFailed', 'fire', 'resolveQueuedJobClass',
    )->andReturn(null);
    $mock->captured = $captured;

    return $mock;
}

test('429 rate-limit releases the job (does not burn a retry slot)', function () {
    Bus::fake();
    fakeRobotsAllowAllRetry();
    fakeCrawlerThrowing('HTTP 429 Too Many Requests — rate-limited');

    $source = makeRetryPolicySource();
    $job = new CrawlPageJob($source->id, 'https://example.com/page');
    $fake = fakeQueueJob();
    $job->setJob($fake);

    $job->handle(
        app(Crawler::class),
        app(ReadabilityExtractor::class),
        app(RobotsTxtParser::class),
    );

    expect($fake->captured->released)->toBeTrue();
    expect($fake->captured->releaseDelay)->toBe(60);
    // Source is still 'crawling' — the release means we'll retry later
    // on the same Source without ever calling failed().
    $source->refresh();
    expect($source->status)->toBe('crawling');
});

test('DNS resolution failure trips the permanent-failure classifier', function () {
    Bus::fake();
    fakeRobotsAllowAllRetry();
    fakeCrawlerThrowing('cURL error 6: Could not resolve host: dead-host.example');

    $source = makeRetryPolicySource('https://dead-host.example/page');
    $job = new CrawlPageJob($source->id, 'https://dead-host.example/page');
    $fake = fakeQueueJob();
    $job->setJob($fake);

    $job->handle(
        app(Crawler::class),
        app(ReadabilityExtractor::class),
        app(RobotsTxtParser::class),
    );

    expect($fake->captured->failedWith)->not->toBeNull();
    expect($fake->captured->failedWith->getMessage())->toContain('Could not resolve host');
});

test('hard HTTP 404 trips the permanent-failure classifier', function () {
    Bus::fake();
    fakeRobotsAllowAllRetry();
    fakeCrawlerThrowing('HTTP 404 Not Found');

    $source = makeRetryPolicySource();
    $job = new CrawlPageJob($source->id, 'https://example.com/page');
    $fake = fakeQueueJob();
    $job->setJob($fake);

    $job->handle(
        app(Crawler::class),
        app(ReadabilityExtractor::class),
        app(RobotsTxtParser::class),
    );

    expect($fake->captured->failedWith)->not->toBeNull();
});

test('transient 5xx still throws (uses normal retry chain)', function () {
    Bus::fake();
    fakeRobotsAllowAllRetry();
    fakeCrawlerThrowing('HTTP 502 Bad Gateway');

    $source = makeRetryPolicySource();
    $job = new CrawlPageJob($source->id, 'https://example.com/page');

    expect(fn () => $job->handle(
        app(Crawler::class),
        app(ReadabilityExtractor::class),
        app(RobotsTxtParser::class),
    ))->toThrow(RuntimeException::class);
});

test('failed() with MaxAttemptsExceededException writes a human-readable error', function () {
    $source = makeRetryPolicySource();

    (new CrawlPageJob($source->id, 'https://example.com/page'))
        ->failed(new MaxAttemptsExceededException(
            'App\Jobs\Crawl\CrawlPageJob has been attempted too many times.',
        ));

    $source->refresh();
    expect($source->status)->toBe('failed');
    // Customer should see "Could not reach this URL after 3 attempts"
    // — NOT the Laravel exception classname/stack.
    expect($source->error)->toContain('Could not reach this URL after 3 attempts');
    expect($source->error)->not->toContain('MaxAttemptsExceededException');
});

test('failed() with a timeout-shaped exception surfaces "Crawl timed out"', function () {
    $source = makeRetryPolicySource();

    (new CrawlPageJob($source->id, 'https://example.com/page'))
        ->failed(new RuntimeException('Maximum execution time exceeded'));

    $source->refresh();
    expect($source->status)->toBe('failed');
    expect($source->error)->toContain('Crawl timed out after 90s');
});
