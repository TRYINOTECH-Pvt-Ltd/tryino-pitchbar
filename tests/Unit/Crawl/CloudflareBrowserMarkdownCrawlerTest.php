<?php

use App\Services\Crawl\CloudflareBrowserMarkdownCrawler;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

function markdownCrawler(MockHandler $mock, ?array &$history = null): CloudflareBrowserMarkdownCrawler
{
    $history = [];
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($history));

    return CloudflareBrowserMarkdownCrawler::default('acct123', 'tok123', new Client(['handler' => $stack]));
}

test('content() unwraps {result: markdown} envelope and wraps as HTML article', function () {
    $markdown = "# Welcome\n\nNative sync where it matters.\n\n- Notion\n- Slack\n- Stripe";
    $mock = new MockHandler([new Response(200, [], json_encode([
        'success' => true,
        'result' => $markdown,
    ]))]);

    $crawler = markdownCrawler($mock, $history);
    $html = $crawler->content('https://example.com');

    expect($html)
        ->toContain('<article>')
        ->toContain('<h1>Welcome</h1>')
        ->toContain('Native sync where it matters')
        ->toContain('<li>Notion</li>');

    /** @var RequestInterface $req */
    $req = $history[0]['request'];
    expect($req->getMethod())->toBe('POST');
    expect((string) $req->getUri())->toContain('/accounts/acct123/browser-rendering/markdown');
    expect($req->getHeaderLine('Authorization'))->toBe('Bearer tok123');

    $body = json_decode((string) $req->getBody(), true);
    expect($body['url'])->toBe('https://example.com');
    expect($body['gotoOptions']['waitUntil'])->toBe('networkidle0');
});

test('content() accepts raw markdown body when CF skips the {result} wrapper', function () {
    $mock = new MockHandler([new Response(200, [], '# raw markdown body')]);
    $crawler = markdownCrawler($mock);

    expect($crawler->content('https://example.com'))->toContain('<h1>raw markdown body</h1>');
});

test('content() throws on empty markdown so the chain promotes', function () {
    $mock = new MockHandler([new Response(200, [], json_encode([
        'success' => true,
        'result' => '',
    ]))]);
    $crawler = markdownCrawler($mock);

    expect(fn () => $crawler->content('https://example.com'))
        ->toThrow(RuntimeException::class, 'empty body');
});

test('content() throws on 429 rate-limit', function () {
    $mock = new MockHandler([new Response(429, [], '{"errors":[{"code":10013}]}')]);
    $crawler = markdownCrawler($mock);

    expect(fn () => $crawler->content('https://example.com'))
        ->toThrow(RuntimeException::class, 'rate-limited');
});

test('content() throws on 5xx', function () {
    $mock = new MockHandler([new Response(500, [], '{"errors":[{"message":"boom"}]}')]);
    $crawler = markdownCrawler($mock);

    expect(fn () => $crawler->content('https://example.com'))
        ->toThrow(RuntimeException::class, 'HTTP 500');
});

test('embedded HTML in markdown is stripped (SafeMarkdown XSS defence)', function () {
    $mock = new MockHandler([new Response(200, [], json_encode([
        'success' => true,
        'result' => "Hello\n\n<script>alert(1)</script>\n\nAfter",
    ]))]);

    $crawler = markdownCrawler($mock);
    $html = $crawler->content('https://example.com');

    expect($html)
        ->not->toContain('<script>')
        ->toContain('Hello')
        ->toContain('After');
});
