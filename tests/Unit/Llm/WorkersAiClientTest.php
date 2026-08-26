<?php

use App\Services\Llm\Contracts\OpenAiClient;
use App\Services\Llm\Exceptions\OpenAiRateLimitException;
use App\Services\Llm\WorkersAiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

test('WorkersAiClient implements the OpenAiClient contract', function () {
    $client = new WorkersAiClient(
        accountId: 'acct123',
        apiToken: 'tok123',
    );

    expect($client)->toBeInstanceOf(OpenAiClient::class);
});

test('embed() POSTs to the Cloudflare embeddings endpoint and returns vectors', function () {
    $mock = new MockHandler([new Response(200, [], json_encode([
        'object' => 'list',
        'data' => [
            ['object' => 'embedding', 'embedding' => [0.1, 0.2, 0.3]],
            ['object' => 'embedding', 'embedding' => [0.4, 0.5, 0.6]],
        ],
    ]))]);
    $history = [];
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $client = new WorkersAiClient(
        accountId: 'acct123',
        apiToken: 'tok123',
        http: new Client(['handler' => $stack]),
    );

    $vectors = $client->embed(['hello', 'world']);

    expect($vectors)->toHaveCount(2);
    expect($vectors[0])->toBe([0.1, 0.2, 0.3]);

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect((string) $req->getUri())->toContain('/accounts/acct123/ai/v1/embeddings');
    expect($req->getHeaderLine('Authorization'))->toBe('Bearer tok123');
    $body = json_decode((string) $req->getBody(), true);
    expect($body['model'])->toBe('@cf/baai/bge-base-en-v1.5');
    expect($body['input'])->toBe(['hello', 'world']);
});

test('streamChat() parses SSE and yields only string content, ignoring int 0 and missing keys', function () {
    $sse = 'data: '.json_encode(['choices' => [['delta' => ['content' => 'Hello']]]])."\n\n"
         .'data: '.json_encode(['choices' => [['delta' => ['content' => ' world']]]])."\n\n"
         .'data: '.json_encode(['choices' => [['delta' => ['content' => 0]]]])."\n\n"
         .'data: '.json_encode(['choices' => [['delta' => []]]])."\n\n"
         ."data: [DONE]\n\n";

    $mock = new MockHandler([new Response(200, [], $sse)]);
    $client = new WorkersAiClient(
        accountId: 'acct123',
        apiToken: 'tok123',
        http: new Client(['handler' => HandlerStack::create($mock)]),
    );

    $tokens = iterator_to_array($client->streamChat([['role' => 'user', 'content' => 'hi']]));
    expect($tokens)->toBe(['Hello', ' world']);
});

test('streamChat() flattens array-shaped delta.content into a string token', function () {
    // Cloudflare Workers AI on Llama 3.3 multi-turn chats can return
    // `delta.content` as an array of OpenAI multi-modal content parts:
    // `[{type:'text', text:'Hello'}, {type:'text', text:' world'}]`.
    // Pre-fix `(string) $delta` triggered PHP's "Array to string
    // conversion" warning which our handler converted to ErrorException
    // → the SSE stream died on the second turn of every conversation
    // (buyer reported 2026-05-21). Flatten array parts into one string.
    $sse = 'data: '.json_encode([
        'choices' => [['delta' => ['content' => [
            ['type' => 'text', 'text' => 'Hello'],
            ['type' => 'text', 'text' => ' world'],
        ]]]],
    ])."\n\n"
       .'data: '.json_encode([
           'choices' => [['delta' => ['content' => ['plain', ' string', ' parts']]]],
       ])."\n\n"
       ."data: [DONE]\n\n";

    $mock = new MockHandler([new Response(200, [], $sse)]);
    $client = new WorkersAiClient(
        accountId: 'acct123',
        apiToken: 'tok123',
        http: new Client(['handler' => HandlerStack::create($mock)]),
    );

    $tokens = iterator_to_array($client->streamChat([['role' => 'user', 'content' => 'hi']]));
    expect($tokens)->toBe(['Hello world', 'plain string parts']);
});

test('streamChat() throws OpenAiRateLimitException on 429', function () {
    $mock = new MockHandler([new Response(429, [], '{"errors":[{"message":"rate limited"}]}')]);
    $client = new WorkersAiClient(
        accountId: 'acct123',
        apiToken: 'tok123',
        http: new Client(['handler' => HandlerStack::create($mock)]),
    );

    iterator_to_array($client->streamChat([['role' => 'user', 'content' => 'hi']]));
})->throws(OpenAiRateLimitException::class);

test('streamChat() parses Cloudflare native NDJSON shape (no data: prefix, response key)', function () {
    // Buyer report 2026-05-29: setting CLOUDFLARE_CHAT_MODEL to a
    // non-Llama slug returned zero tokens through the OpenAI-compat
    // `/v1/chat/completions` endpoint. Cause: those models stream the
    // native CF AI shape `{"response":"...","p":"..."}` line-by-line
    // WITHOUT the `data:` SSE prefix. Pre-fix the parser skipped
    // every such line.
    $ndjson = json_encode(['response' => 'Hello', 'p' => ''])."\n"
            .json_encode(['response' => ' from', 'p' => ''])."\n"
            .json_encode(['response' => ' Mistral', 'p' => ''])."\n";

    $mock = new MockHandler([new Response(200, [], $ndjson)]);
    $client = new WorkersAiClient(
        accountId: 'acct123',
        apiToken: 'tok123',
        http: new Client(['handler' => HandlerStack::create($mock)]),
    );

    $tokens = iterator_to_array($client->streamChat([['role' => 'user', 'content' => 'hi']]));
    expect($tokens)->toBe(['Hello', ' from', ' Mistral']);
});

test('streamChat() falls back to choices[0].message.content when delta is missing', function () {
    // Some Workers AI models do not support real streaming through the
    // OpenAI-compat surface and instead reply with a single chunk
    // shaped like the non-streaming response: `choices[0].message`
    // instead of `choices[0].delta`. Yield it as one token.
    $sse = 'data: '.json_encode([
        'choices' => [['message' => ['content' => 'Hello from a non-streaming model.']]],
    ])."\n\n"
       ."data: [DONE]\n\n";

    $mock = new MockHandler([new Response(200, [], $sse)]);
    $client = new WorkersAiClient(
        accountId: 'acct123',
        apiToken: 'tok123',
        http: new Client(['handler' => HandlerStack::create($mock)]),
    );

    $tokens = iterator_to_array($client->streamChat([['role' => 'user', 'content' => 'hi']]));
    expect($tokens)->toBe(['Hello from a non-streaming model.']);
});

test('streamChat() reads response key inside data:-prefixed payloads', function () {
    // CF native shape can leak through the `data:` SSE wrapper too.
    $sse = 'data: '.json_encode(['response' => 'Hello'])."\n\n"
         .'data: '.json_encode(['response' => ' world'])."\n\n"
         ."data: [DONE]\n\n";

    $mock = new MockHandler([new Response(200, [], $sse)]);
    $client = new WorkersAiClient(
        accountId: 'acct123',
        apiToken: 'tok123',
        http: new Client(['handler' => HandlerStack::create($mock)]),
    );

    $tokens = iterator_to_array($client->streamChat([['role' => 'user', 'content' => 'hi']]));
    expect($tokens)->toBe(['Hello', ' world']);
});

test('honors a custom AI Gateway URL when provided', function () {
    $mock = new MockHandler([new Response(200, [], json_encode(['data' => [['embedding' => [1.0]]]]))]);
    $history = [];
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    $client = new WorkersAiClient(
        accountId: 'acct123',
        apiToken: 'tok123',
        aiGatewayUrl: 'https://gateway.ai.cloudflare.com/v1/acct123/pitchbar/workers-ai/v1',
        http: new Client(['handler' => $stack]),
    );

    $client->embed(['hello']);

    expect((string) $history[0]['request']->getUri())
        ->toContain('gateway.ai.cloudflare.com/v1/acct123/pitchbar/workers-ai/v1/embeddings');
});
