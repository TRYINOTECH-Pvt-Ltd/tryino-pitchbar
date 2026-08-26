<?php

use App\Services\Vector\VectorizeClient;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

/**
 * Regression for the whispbar production crash (2026-05-15):
 *   "expected 768 dimensions, and got 1024 dimensions"
 * Every CrawlPageJob → IndexDocumentJob hammered Cloudflare with
 * mismatched-length vectors until MaxAttemptsExceeded. We now reject
 * the payload locally before any network call.
 */
function vectorizeWithMock(MockHandler $mock): VectorizeClient
{
    $http = new Guzzle(['handler' => HandlerStack::create($mock)]);

    return new VectorizeClient($http, 'fake-account', 'fake-token');
}

test('upsertPoints blocks vectors whose length differs from the configured dim', function () {
    config()->set('services.vector_dim', 768);
    config()->set('services.cloudflare.embed_model', '@cf/baai/bge-base-en-v1.5');

    $mock = new MockHandler([]); // any HTTP call would error — we should never hit network
    $client = vectorizeWithMock($mock);

    $points = [
        ['id' => 'a', 'vector' => array_fill(0, 1024, 0.1), 'payload' => []],
    ];

    expect(fn () => $client->upsertPoints('idx', $points))
        ->toThrow(RuntimeException::class, 'does not match index dimension 768');
});

test('upsertPoints allows correctly-sized vectors through', function () {
    config()->set('services.vector_dim', 768);
    config()->set('services.cloudflare.embed_model', '@cf/baai/bge-base-en-v1.5');

    $mock = new MockHandler([new Response(200, [], '{"success":true}')]);
    $client = vectorizeWithMock($mock);

    $points = [
        ['id' => 'a', 'vector' => array_fill(0, 768, 0.1), 'payload' => []],
    ];

    $client->upsertPoints('idx', $points);
    expect(true)->toBeTrue();
});

test('ensureCollection surfaces an actionable mismatch when the index exists at a different dim', function () {
    config()->set('services.vector_dim', 1024);
    config()->set('services.cloudflare.embed_model', '@cf/baai/bge-m3');

    // GET returns an existing index at 768 — but caller wants 1024.
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'result' => ['config' => ['dimensions' => 768, 'metric' => 'cosine']],
            'success' => true,
        ])),
    ]);
    $client = vectorizeWithMock($mock);

    expect(fn () => $client->ensureCollection('idx', 1024))
        ->toThrow(RuntimeException::class, 'exists at 768 dimensions');
});

test('search() rejects a query vector whose length does not match the index dim', function () {
    config()->set('services.vector_dim', 768);
    config()->set('services.cloudflare.embed_model', '@cf/baai/bge-base-en-v1.5');

    $mock = new MockHandler([]); // no HTTP — guard fires before request
    $client = vectorizeWithMock($mock);

    expect(fn () => $client->search('idx', array_fill(0, 1024, 0.1), [], 6))
        ->toThrow(RuntimeException::class, 'does not match index dimension 768');
});

test('search() allows correctly-sized query vectors through', function () {
    config()->set('services.vector_dim', 768);
    config()->set('services.cloudflare.embed_model', '@cf/baai/bge-base-en-v1.5');

    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'result' => ['matches' => []],
            'success' => true,
        ])),
    ]);
    $client = vectorizeWithMock($mock);

    $matches = $client->search('idx', array_fill(0, 768, 0.1), [], 6);
    expect($matches)->toBe([]);
});

test('ensureCollection no-ops when the existing index dim matches', function () {
    $mock = new MockHandler([
        new Response(200, [], json_encode([
            'result' => ['config' => ['dimensions' => 768, 'metric' => 'cosine']],
            'success' => true,
        ])),
        // metadata index creates — three properties, idempotent
        new Response(200, [], '{"success":true}'),
        new Response(200, [], '{"success":true}'),
        new Response(200, [], '{"success":true}'),
    ]);
    $client = vectorizeWithMock($mock);
    $client->ensureCollection('idx', 768);
    expect(true)->toBeTrue();
});
