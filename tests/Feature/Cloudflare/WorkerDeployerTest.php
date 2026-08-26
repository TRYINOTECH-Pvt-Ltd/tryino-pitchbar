<?php

use App\Services\Cloudflare\WorkerDeployer;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

function makeMockedDeployer(array $responses, array &$captured): WorkerDeployer
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::tap(function (RequestInterface $req) use (&$captured) {
        $captured[] = [
            'method' => $req->getMethod(),
            'uri' => (string) $req->getUri(),
            'headers' => $req->getHeaders(),
            'body' => (string) $req->getBody(),
        ];
    }));

    return new WorkerDeployer(new Client(['handler' => $stack]));
}

test('deploy uploads worker, sets secret, sets cron — 3 calls in order', function () {
    $captured = [];
    $deployer = makeMockedDeployer([
        new Response(200, [], json_encode(['success' => true, 'result' => ['id' => 'w']])),
        new Response(200, [], json_encode(['success' => true])),
        new Response(200, [], json_encode(['success' => true])),
    ], $captured);

    $result = $deployer->deploy(
        accountId: 'cf-acct-id',
        apiToken: 'cf-api-token',
        workerName: 'pitchbar-tick-test',
        callbackUrl: 'https://example.com/api/v1/internal/queue-tick',
        sharedToken: 'shared-secret-xyz',
    );

    expect($result['ok'])->toBeTrue();
    expect($result['worker_url'])->toContain('pitchbar-tick-test');
    expect($captured)->toHaveCount(3);

    // 1) PUT /workers/scripts/{name} multipart
    expect($captured[0]['method'])->toBe('PUT');
    expect($captured[0]['uri'])->toContain('/workers/scripts/pitchbar-tick-test');
    expect($captured[0]['headers']['Content-Type'][0] ?? '')->toContain('multipart/form-data');
    expect($captured[0]['body'])->toContain('main_module');
    expect($captured[0]['body'])->toContain('LARAVEL_QUEUE_TICK_URL');
    expect($captured[0]['body'])->toContain('https://example.com/api/v1/internal/queue-tick');
    expect($captured[0]['body'])->toContain('export default');

    // 2) PUT /secrets — secret token
    expect($captured[1]['method'])->toBe('PUT');
    expect($captured[1]['uri'])->toContain('/secrets');
    expect($captured[1]['body'])->toContain('shared-secret-xyz');
    expect($captured[1]['body'])->toContain('INTERNAL_QUEUE_TOKEN');
    expect($captured[1]['body'])->toContain('secret_text');

    // 3) PUT /schedules — every-minute cron
    expect($captured[2]['method'])->toBe('PUT');
    expect($captured[2]['uri'])->toContain('/schedules');
    expect($captured[2]['body'])->toContain('* * * * *');
});

test('deploy bubbles up errors with HTTP status + body snippet', function () {
    $captured = [];
    $deployer = makeMockedDeployer([
        new Response(401, [], 'Unauthorized'),
    ], $captured);

    $exception = null;
    try {
        $deployer->deploy('a', 't', 'w', 'https://x', 'secret');
    } catch (RuntimeException $e) {
        $exception = $e;
    }

    expect($exception)->not->toBeNull();
    expect($exception->getMessage())->toContain('401');
    expect($exception->getMessage())->toContain('upload worker');
});

test('status returns exists=false for 404', function () {
    $captured = [];
    $deployer = makeMockedDeployer([
        new Response(404, [], json_encode(['success' => false])),
    ], $captured);

    $info = $deployer->status('a', 't', 'pitchbar-tick-not-here');
    expect($info['exists'])->toBeFalse();
    expect($info['schedules'])->toBe([]);
});

test('status returns exists=true with schedules', function () {
    $captured = [];
    $deployer = makeMockedDeployer([
        new Response(200, [], json_encode(['success' => true, 'result' => ['id' => 'w']])),
        new Response(200, [], json_encode([
            'success' => true,
            'result' => ['schedules' => [['cron' => '* * * * *']]],
        ])),
    ], $captured);

    $info = $deployer->status('a', 't', 'pitchbar-tick-here');
    expect($info['exists'])->toBeTrue();
    expect($info['schedules'])->toBe(['* * * * *']);
});

test('destroy returns true on 200 and on 404 (already gone)', function () {
    $captured = [];
    $deployer = makeMockedDeployer([
        new Response(200, [], '{}'),
        new Response(404, [], '{}'),
    ], $captured);

    expect($deployer->destroy('a', 't', 'w1'))->toBeTrue();
    expect($deployer->destroy('a', 't', 'w2'))->toBeTrue();
});

test('deploy refuses when credentials are missing', function () {
    $captured = [];
    $deployer = makeMockedDeployer([], $captured);

    expect(fn () => $deployer->deploy('', 't', 'w', 'u', 's'))->toThrow(RuntimeException::class);
    expect(fn () => $deployer->deploy('a', '', 'w', 'u', 's'))->toThrow(RuntimeException::class);
});

test('worker script contains the cron handler scaffold', function () {
    $captured = [];
    $deployer = makeMockedDeployer([
        new Response(200, [], '{}'),
        new Response(200, [], '{}'),
        new Response(200, [], '{}'),
    ], $captured);

    $deployer->deploy('a', 't', 'w', 'https://callback', 'secret');

    $body = $captured[0]['body'];
    // Every required surface of the worker script is present.
    expect($body)->toContain('async scheduled(event, env, ctx)');
    expect($body)->toContain('env.LARAVEL_QUEUE_TICK_URL');
    expect($body)->toContain('env.INTERNAL_QUEUE_TOKEN');
    expect($body)->toContain('X-Pitchbar-Token');
    expect($body)->toContain('async fetch(request, env, ctx)');
});
