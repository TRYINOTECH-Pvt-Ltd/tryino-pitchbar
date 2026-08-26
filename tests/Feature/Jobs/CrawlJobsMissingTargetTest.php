<?php

use App\Jobs\Crawl\CrawlSourceJob;
use App\Jobs\Crawl\IndexDocumentJob;
use App\Jobs\Rag\PersistTurnJob;
use App\Services\Crawl\SitemapDiscoverer;
use App\Services\Experiments\ExperimentResolver;
use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Rag\Chunker;
use App\Services\Vector\Contracts\QdrantClient;
use App\Support\UrlSafetyGuard;
use Illuminate\Support\Str;

/*
 * Race regression: a buyer can delete a Source / Document / Conversation
 * between job dispatch and worker pickup. Before the noop guard, the
 * worker findOrFailed and the job went to failed_jobs with a noisy stack
 * trace on the buyer's prod install. These tests assert the jobs silently
 * exit instead of throwing.
 */

test('CrawlSourceJob no-ops when the source was deleted before pickup', function () {
    $job = new CrawlSourceJob((string) Str::uuid());

    $job->handle(app(SitemapDiscoverer::class), app(UrlSafetyGuard::class));

    expect(true)->toBeTrue();
});

test('IndexDocumentJob no-ops when the document was deleted before pickup', function () {
    $job = new IndexDocumentJob((string) Str::uuid(), 'body that never gets read');

    // Should return BEFORE touching the LLM / vector clients, so we
    // can resolve them from the container without binding fakes.
    $job->handle(
        app(Chunker::class),
        app(OpenAiClient::class),
        app(QdrantClient::class),
    );

    expect(true)->toBeTrue();
});

test('PersistTurnJob no-ops when the conversation was deleted before pickup', function () {
    $job = new PersistTurnJob(
        conversationId: (string) Str::uuid(),
        userMessageId: (string) Str::uuid(),
        userMessageText: 'hello',
        assistantMessageId: (string) Str::uuid(),
        assistantMessageText: 'hi',
        citations: [],
        confidence: 0.9,
        latencyMs: 100,
        model: 'fake',
    );

    $job->handle(app(ExperimentResolver::class));

    expect(true)->toBeTrue();
});
