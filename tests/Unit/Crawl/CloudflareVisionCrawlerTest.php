<?php

use App\Services\Crawl\CloudflareBrowserScreenshotClient;
use App\Services\Crawl\CloudflareVisionClient;
use App\Services\Crawl\CloudflareVisionCrawler;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

function visionStack(MockHandler $shotMock, MockHandler $visionMock, ?array &$shotHistory = null, ?array &$visionHistory = null): CloudflareVisionCrawler
{
    $shotHistory = [];
    $visionHistory = [];

    $shotStack = HandlerStack::create($shotMock);
    $shotStack->push(Middleware::history($shotHistory));

    $visionStack = HandlerStack::create($visionMock);
    $visionStack->push(Middleware::history($visionHistory));

    return new CloudflareVisionCrawler(
        CloudflareBrowserScreenshotClient::default('acct123', 'tok123', new Client(['handler' => $shotStack])),
        CloudflareVisionClient::default('acct123', 'tok123', null, null, new Client(['handler' => $visionStack])),
    );
}

test('end-to-end: screenshot → vision OCR → wrapped HTML', function () {
    $pngBytes = "\x89PNG\r\n\x1a\n".str_repeat('x', 200);
    $extractedText = "Welcome to Acme\n\nPipe leads to Slack.\n\nNative Stripe checkout.";

    $shotMock = new MockHandler([new Response(200, ['Content-Type' => 'image/png'], $pngBytes)]);
    $visionMock = new MockHandler([new Response(200, [], json_encode([
        'choices' => [['message' => ['content' => $extractedText]]],
    ]))]);

    $crawler = visionStack($shotMock, $visionMock, $shotHistory, $visionHistory);
    $html = $crawler->content('https://example.com');

    expect($html)
        ->toContain('<article data-vision-ocr="true">')
        ->toContain('<p>Welcome to Acme</p>')
        ->toContain('<p>Pipe leads to Slack.</p>')
        ->toContain('<p>Native Stripe checkout.</p>');

    /** @var RequestInterface $shotReq */
    $shotReq = $shotHistory[0]['request'];
    expect((string) $shotReq->getUri())->toContain('/browser-rendering/screenshot');

    /** @var RequestInterface $visionReq */
    $visionReq = $visionHistory[0]['request'];
    expect((string) $visionReq->getUri())->toContain('/ai/v1/chat/completions');
    $visionPayload = json_decode((string) $visionReq->getBody(), true);
    expect($visionPayload['model'])->toBe('@cf/meta/llama-3.2-11b-vision-instruct');
    expect($visionPayload['messages'][1]['content'][1]['image_url']['url'])->toStartWith('data:image/png;base64,');
});

test('vision OCR text is HTML-escaped to prevent injection', function () {
    $pngBytes = "\x89PNG\r\n\x1a\n".str_repeat('y', 200);
    $extractedText = '<script>steal()</script> & "quoted"';

    $shotMock = new MockHandler([new Response(200, ['Content-Type' => 'image/png'], $pngBytes)]);
    $visionMock = new MockHandler([new Response(200, [], json_encode([
        'choices' => [['message' => ['content' => $extractedText]]],
    ]))]);

    $crawler = visionStack($shotMock, $visionMock);
    $html = $crawler->content('https://example.com');

    expect($html)
        ->not->toContain('<script>steal()</script>')
        ->toContain('&lt;script&gt;steal()&lt;/script&gt;')
        ->toContain('&amp;')
        ->toContain('&quot;quoted&quot;');
});

test('vision returning empty text triggers fallback exception', function () {
    $shotMock = new MockHandler([new Response(200, ['Content-Type' => 'image/png'], 'pngbytes')]);
    $visionMock = new MockHandler([new Response(200, [], json_encode([
        'choices' => [['message' => ['content' => '']]],
    ]))]);

    $crawler = visionStack($shotMock, $visionMock);

    expect(fn () => $crawler->content('https://example.com'))
        ->toThrow(RuntimeException::class, 'extracted no text');
});

test('screenshot endpoint 429 bubbles up so chain promotes', function () {
    $shotMock = new MockHandler([new Response(429, [], '{"errors":[]}')]);
    $visionMock = new MockHandler;

    $crawler = visionStack($shotMock, $visionMock);

    expect(fn () => $crawler->content('https://example.com'))
        ->toThrow(RuntimeException::class, 'rate-limited');
});

test('vision endpoint 5xx bubbles up so chain promotes', function () {
    $shotMock = new MockHandler([new Response(200, ['Content-Type' => 'image/png'], 'pngbytes')]);
    $visionMock = new MockHandler([new Response(500, [], '{"errors":[{"message":"boom"}]}')]);

    $crawler = visionStack($shotMock, $visionMock);

    expect(fn () => $crawler->content('https://example.com'))
        ->toThrow(RuntimeException::class, 'HTTP 500');
});

test('vision client falls back to result.response shape when proxied via AI Gateway', function () {
    $pngBytes = 'pngbytes';
    $shotMock = new MockHandler([new Response(200, ['Content-Type' => 'image/png'], $pngBytes)]);
    $visionMock = new MockHandler([new Response(200, [], json_encode([
        'result' => ['response' => "Gateway-shaped response.\n\nSecond paragraph."],
    ]))]);

    $crawler = visionStack($shotMock, $visionMock);
    $html = $crawler->content('https://example.com');

    expect($html)
        ->toContain('<p>Gateway-shaped response.</p>')
        ->toContain('<p>Second paragraph.</p>');
});
