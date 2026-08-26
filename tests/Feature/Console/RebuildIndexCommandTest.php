<?php

use App\Jobs\Crawl\CrawlSourceJob;
use App\Jobs\Crawl\IndexTextSourceJob;
use App\Models\Agent;
use App\Models\Source;
use App\Services\Vector\Contracts\QdrantClient;
use Illuminate\Support\Facades\Queue;

/**
 * Regression guard for the blengi-reported 2026-07-05 incident: a
 * bge-base→bge-m3 switch ran `vector:rebuild-index`, which recreated the
 * index but re-embedded ONLY documents with text on disk. URL/crawled
 * sources (the bulk of a real KB) came back empty — "Queued 0/132" — and
 * the bot was knowledge-less until a manual re-crawl. The command now
 * routes every source through SourceRetrier, so all types refill.
 */
it('recreates the index at the model dimension and re-indexes every source type', function () {
    $agent = Agent::factory()->create();

    $url = Source::factory()->for($agent)->create([
        'type' => 'url',
        'status' => 'indexed',
        'config' => ['url' => 'https://example.com'],
    ]);
    $text = Source::factory()->for($agent)->create([
        'type' => 'text',
        'status' => 'failed',
        'config' => ['title' => 'Contact', 'body' => 'Industrieweg 6, 7944 HS Meppel'],
    ]);
    $emptyText = Source::factory()->for($agent)->create([
        'type' => 'text',
        'status' => 'failed',
        'config' => ['title' => 'Legacy pasted source', 'body' => ''],
    ]);

    // Assert the destructive index recreate happens at the resolved dim.
    $vector = Mockery::spy(QdrantClient::class);
    $this->app->instance(QdrantClient::class, $vector);

    Queue::fake();

    $this->artisan('vector:rebuild-index', ['--force' => true, '--dim' => 1024])
        ->assertExitCode(0);

    $vector->shouldHaveReceived('dropCollection');
    $vector->shouldHaveReceived('ensureCollection')
        ->withArgs(fn (string $name, int $dim) => $dim === 1024);

    // URL source re-crawled (the case the old command silently dropped),
    // and the text source with a persisted body re-embedded from it.
    Queue::assertPushed(CrawlSourceJob::class, 1);
    Queue::assertPushed(IndexTextSourceJob::class, 1);

    // Dispatched sources flip to pending; the empty-body text source is
    // skipped (text_body_missing) and keeps its failed status untouched.
    expect(Source::withoutGlobalScopes()->find($url->id)->status)->toBe('pending');
    expect(Source::withoutGlobalScopes()->find($text->id)->status)->toBe('pending');
    expect(Source::withoutGlobalScopes()->find($emptyText->id)->status)->toBe('failed');
});
