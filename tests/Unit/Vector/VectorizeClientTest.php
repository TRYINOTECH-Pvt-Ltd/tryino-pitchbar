<?php

use App\Services\Vector\VectorizeClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

function vectorizeClient(MockHandler $mock, ?array &$container = null): VectorizeClient
{
    $container = [];
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($container));

    return new VectorizeClient(
        new Client(['handler' => $stack]),
        accountId: 'acct123',
        apiToken: 'tok123',
    );
}

test('upsertPoints sends NDJSON to /indexes/{collection}/upsert', function () {
    $mock = new MockHandler([new Response(200, [], '{"success":true,"result":{"mutationId":"abc"}}')]);
    $client = vectorizeClient($mock, $history);

    // Pad to 768 to satisfy the dim-mismatch guard added 2026-05-15.
    // Unit-context resolveVectorDim() falls back to 768 (bge-base default).
    $padded = fn ($a, $b, $c) => array_pad([$a, $b, $c], 768, 0.0);
    $client->upsertPoints('pitchbar-chunks', [
        ['id' => 'p1', 'vector' => $padded(0.1, 0.2, 0.3), 'payload' => ['agent_id' => 'a1']],
        ['id' => 'p2', 'vector' => $padded(0.4, 0.5, 0.6), 'payload' => ['agent_id' => 'a1']],
    ]);

    expect($history)->toHaveCount(1);
    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect((string) $req->getUri())->toContain('/accounts/acct123/vectorize/v2/indexes/pitchbar-chunks/upsert');
    expect($req->getHeaderLine('Authorization'))->toBe('Bearer tok123');
    expect($req->getHeaderLine('Content-Type'))->toBe('application/x-ndjson');

    $lines = explode("\n", (string) $req->getBody());
    expect($lines)->toHaveCount(2);
    $first = json_decode($lines[0], true);
    expect($first['id'])->toBe('p1');
    expect($first['values'][0])->toBe(0.1);
    expect($first['values'][1])->toBe(0.2);
    expect($first['values'][2])->toBe(0.3);
    expect(count($first['values']))->toBe(768);
    expect($first['metadata']['agent_id'])->toBe('a1');
});

test('search posts query payload and decodes matches into the QdrantClient shape', function () {
    $mock = new MockHandler([new Response(200, [], json_encode([
        'success' => true,
        'result' => [
            'matches' => [
                ['id' => 'p1', 'score' => 0.92, 'metadata' => ['agent_id' => 'a1', 'url' => 'https://x']],
                ['id' => 'p2', 'score' => 0.81, 'metadata' => ['agent_id' => 'a1']],
            ],
        ],
    ]))]);

    $client = vectorizeClient($mock, $history);

    // Pad to 768 to satisfy the dim guard added 2026-05-15 (same
    // padding pattern as upsertPoints test above).
    $queryVector = array_pad([0.1, 0.2, 0.3], 768, 0.0);
    $results = $client->search('pitchbar-chunks', $queryVector, ['agent_id' => 'a1'], 5);

    expect($results)->toHaveCount(2);
    expect($results[0])->toMatchArray([
        'id' => 'p1',
        'score' => 0.92,
    ]);
    expect($results[0]['payload']['agent_id'])->toBe('a1');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect((string) $req->getUri())->toContain('/indexes/pitchbar-chunks/query');
    $body = json_decode((string) $req->getBody(), true);
    expect(count($body['vector']))->toBe(768);
    expect($body['vector'][0])->toBe(0.1);
    expect($body['topK'])->toBe(5);
    expect($body['filter'])->toBe(['agent_id' => ['$eq' => 'a1']]);
});

test('deleteByFilter queries first, then deletes the matching ids', function () {
    $queryResponse = new Response(200, [], json_encode([
        'success' => true,
        'result' => [
            'matches' => [
                ['id' => 'p1', 'score' => 1.0, 'metadata' => []],
                ['id' => 'p2', 'score' => 1.0, 'metadata' => []],
            ],
        ],
    ]));
    $deleteResponse = new Response(200, [], '{"success":true}');

    $mock = new MockHandler([$queryResponse, $deleteResponse]);
    $client = vectorizeClient($mock, $history);

    $client->deleteByFilter('pitchbar-chunks', ['document_id' => 'doc1']);

    expect($history)->toHaveCount(2);
    /** @var RequestInterface $deleteReq */
    $deleteReq = $history[1]['request'];
    expect((string) $deleteReq->getUri())->toContain('/delete_by_ids');
    $body = json_decode((string) $deleteReq->getBody(), true);
    expect($body['ids'])->toBe(['p1', 'p2']);
});

test('ensureCollection creates an index when GET returns 404', function () {
    $mock = new MockHandler([
        new Response(404, [], '{"errors":[{"code":1000,"message":"not found"}]}'),
        new Response(200, [], '{"success":true}'),
    ]);
    $client = vectorizeClient($mock, $history);

    $client->ensureCollection('pitchbar-chunks', 1536, 'Cosine');

    expect(count($history))->toBeGreaterThanOrEqual(2);
    /** @var RequestInterface $createReq */
    $createReq = $history[1]['request'];
    expect($createReq->getMethod())->toBe('POST');
    $body = json_decode((string) $createReq->getBody(), true);
    expect($body['name'])->toBe('pitchbar-chunks');
    expect($body['config']['dimensions'])->toBe(1536);
    expect($body['config']['metric'])->toBe('cosine');
});

test('ensureCollection swallows duplicate_name when GET hits a transient and POST races a create', function () {
    // Reproduces the production failure: GET hits a transient hiccup
    // (timeout / 5xx) so we miss the "exists" signal, then POST returns
    // Cloudflare's `vectorize.index.duplicate_name` (code 3002). Pre-fix
    // the IndexDocumentJob exception bubbled out and the buyer's Source
    // landed in `failed` status — even though the index actually existed
    // and the upsert would have succeeded.
    $mock = new MockHandler([
        // GET — simulated 5xx that throws inside Guzzle
        new Response(503, [], 'service unavailable'),
        // POST — duplicate_name response
        new Response(409, [], json_encode([
            'success' => false,
            'errors' => [['code' => 3002, 'message' => 'vectorize.index.duplicate_name - Index name "pitchbar-chunks"']],
        ])),
        // Metadata indexes (3) — already exist, swallowed
        new Response(409, [], '{"errors":[{"code":3003,"message":"already exists"}]}'),
        new Response(409, [], '{"errors":[{"code":3003,"message":"already exists"}]}'),
        new Response(409, [], '{"errors":[{"code":3003,"message":"already exists"}]}'),
    ]);
    $client = vectorizeClient($mock, $history);

    // Must NOT throw — that was the production-killing bug.
    $client->ensureCollection('pitchbar-chunks', 768, 'Cosine');

    expect(true)->toBeTrue();
});

test('ensureCollection still throws on a non-duplicate POST failure (auth, quota, etc.)', function () {
    $mock = new MockHandler([
        new Response(503, [], 'service unavailable'),
        // POST — auth failure, not the duplicate_name case
        new Response(401, [], json_encode([
            'success' => false,
            'errors' => [['code' => 10000, 'message' => 'unauthorized']],
        ])),
    ]);
    $client = vectorizeClient($mock, $history);

    expect(fn () => $client->ensureCollection('pitchbar-chunks', 768))
        ->toThrow(RuntimeException::class);
});
