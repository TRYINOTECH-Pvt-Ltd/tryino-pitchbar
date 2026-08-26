<?php

use App\Services\Crawl\CloudflareBrowserClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

function browserClient(MockHandler $mock, ?array &$container = null): CloudflareBrowserClient
{
    $container = [];
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($container));

    return CloudflareBrowserClient::default('acct123', 'tok123', new Client(['handler' => $stack]));
}

test('content() unwraps the {result: html} envelope from Cloudflare', function () {
    $mock = new MockHandler([new Response(200, [], json_encode([
        'success' => true,
        'result' => '<html><body><h1>Hello</h1></body></html>',
    ]))]);

    $client = browserClient($mock, $history);
    $html = $client->content('https://example.com');

    expect($html)->toContain('<h1>Hello</h1>');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect((string) $req->getUri())->toContain('/accounts/acct123/browser-rendering/content');
    expect($req->getHeaderLine('Authorization'))->toBe('Bearer tok123');
    $body = json_decode((string) $req->getBody(), true);
    expect($body['url'])->toBe('https://example.com');
});

test('content() returns the body verbatim when CF returns raw HTML', function () {
    $mock = new MockHandler([new Response(200, [], '<html>raw</html>')]);
    $client = browserClient($mock);

    expect($client->content('https://example.com'))->toBe('<html>raw</html>');
});

test('content() throws on 429 rate-limit', function () {
    $mock = new MockHandler([new Response(429, [], '{"errors":[{"code":10013}]}')]);
    $client = browserClient($mock);

    $client->content('https://example.com');
})->throws(RuntimeException::class, 'rate-limited');

test('content() throws on non-2xx', function () {
    $mock = new MockHandler([new Response(500, [], '{"errors":[{"message":"boom"}]}')]);
    $client = browserClient($mock);

    $client->content('https://example.com');
})->throws(RuntimeException::class, 'HTTP 500');
