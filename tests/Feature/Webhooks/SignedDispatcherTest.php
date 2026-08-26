<?php

use App\Services\Webhooks\SignedDispatcher;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

test('dispatcher emits signature + id + event headers', function () {
    $history = [];
    $mock = new MockHandler([new Response(200)]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $dispatcher = new SignedDispatcher(new Client(['handler' => $stack]));

    $ok = $dispatcher->send('https://example.test/hook', 'whsec_abc', [
        'event' => 'lead.captured',
        'lead' => ['id' => 'L1'],
    ]);

    expect($ok)->toBeTrue();
    expect($history)->toHaveCount(1);

    $request = $history[0]['request'];

    expect($request->getHeaderLine('X-Pitchbar-Event'))->toBe('lead.captured');
    expect($request->getHeaderLine('X-Pitchbar-Webhook-Id'))
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');

    $sig = $request->getHeaderLine('X-Pitchbar-Signature');
    expect($sig)->toMatch('/^t=\d+,v1=[0-9a-f]{64}$/');
});

test('dispatcher signs body with HMAC-SHA256 using timestamp prefix', function () {
    $history = [];
    $mock = new MockHandler([new Response(200)]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $dispatcher = new SignedDispatcher(new Client(['handler' => $stack]));
    $secret = 'whsec_xyz';

    $dispatcher->send('https://example.test/hook', $secret, [
        'event' => 'lead.captured',
        'lead' => ['id' => 'L2'],
    ]);

    $request = $history[0]['request'];
    $body = (string) $request->getBody();

    $parts = collect(explode(',', $request->getHeaderLine('X-Pitchbar-Signature')))
        ->mapWithKeys(function ($kv) {
            [$k, $v] = explode('=', $kv, 2);

            return [$k => $v];
        })
        ->all();

    $expected = hash_hmac('sha256', "{$parts['t']}.{$body}", $secret);
    expect($parts['v1'])->toBe($expected);
});

test('dispatcher emits unknown for missing event field', function () {
    $history = [];
    $mock = new MockHandler([new Response(200)]);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));
    $dispatcher = new SignedDispatcher(new Client(['handler' => $stack]));

    $dispatcher->send('https://example.test/hook', 'whsec_abc', [
        'lead' => ['id' => 'L3'],
    ]);

    expect($history[0]['request']->getHeaderLine('X-Pitchbar-Event'))->toBe('unknown');
});

test('dispatcher returns false on 5xx without throwing', function () {
    $mock = new MockHandler([new Response(503)]);
    $stack = HandlerStack::create($mock);
    $client = new Client(['handler' => $stack]);
    $dispatcher = new SignedDispatcher($client);

    expect($dispatcher->send('https://example.test/hook', 'whsec_abc', [
        'event' => 'lead.captured',
    ]))->toBeFalse();
});
