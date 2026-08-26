<?php

use App\Services\Cloudflare\ToMarkdownClient;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;

/**
 * Pins the Cloudflare `toMarkdown` REST contract:
 *
 *   - POST /accounts/{ACCOUNT}/ai/tomarkdown with bearer token
 *   - body is multipart/form-data with a `files` part
 *   - on success the response wraps a per-file result; we lift
 *     `result[0].data` out as the markdown string
 *   - any logical (success=false) or transport (5xx) failure throws
 *     a RuntimeException carrying the filename so callers can record
 *     a useful error
 *
 * No real HTTP — every test uses GuzzleHttp\Handler\MockHandler.
 */
function makeToMarkdownClient(array $responses, array &$historySink = []): ToMarkdownClient
{
    $mock = new MockHandler($responses);
    $stack = HandlerStack::create($mock);
    $stack->push(Middleware::history($historySink));

    return new ToMarkdownClient(
        new Guzzle(['handler' => $stack]),
        'acct-test',
        'tok-test',
    );
}

test('convert posts multipart to /ai/tomarkdown with bearer auth + returns markdown', function () {
    $history = [];
    $client = makeToMarkdownClient([
        new Response(200, [], json_encode([
            'success' => true,
            'result' => [[
                'id' => 'r1',
                'name' => 'paper.pdf',
                'mimeType' => 'application/pdf',
                'format' => 'markdown',
                'data' => "# Title\n\nBody text.",
                'tokens' => 12,
            ]],
        ])),
    ], $history);

    $markdown = $client->convert('PDF-BYTES', 'paper.pdf', 'application/pdf');

    expect($markdown)->toBe("# Title\n\nBody text.");

    /** @var Request $sent */
    $sent = $history[0]['request'];
    expect($sent->getMethod())->toBe('POST');
    expect((string) $sent->getUri())->toBe('https://api.cloudflare.com/client/v4/accounts/acct-test/ai/tomarkdown');
    expect($sent->getHeaderLine('Authorization'))->toBe('Bearer tok-test');
    expect($sent->getHeaderLine('Content-Type'))->toStartWith('multipart/form-data');
    // The PDF bytes must be in the request body wrapped in a `files` part.
    $body = (string) $sent->getBody();
    expect($body)->toContain('name="files"');
    expect($body)->toContain('filename="paper.pdf"');
    expect($body)->toContain('PDF-BYTES');
});

test('convert surfaces per-file conversion error with filename', function () {
    $client = makeToMarkdownClient([
        new Response(200, [], json_encode([
            'success' => true,
            'result' => [[
                'name' => 'broken.pdf',
                'format' => 'error',
                'error' => 'corrupted PDF stream',
            ]],
        ])),
    ]);

    try {
        $client->convert('garbage', 'broken.pdf');
        $this->fail('Expected RuntimeException not thrown.');
    } catch (RuntimeException $e) {
        expect($e->getMessage())
            ->toContain('broken.pdf')
            ->toContain('corrupted PDF stream');
    }
});

test('convert throws on Cloudflare envelope success=false', function () {
    $client = makeToMarkdownClient([
        new Response(200, [], json_encode([
            'success' => false,
            'errors' => [['code' => 10000, 'message' => 'Authentication error']],
            'result' => [],
        ])),
    ]);

    expect(fn () => $client->convert('bytes', 'doc.pdf'))
        ->toThrow(RuntimeException::class, 'Authentication error');
});

test('convert throws on transport failure (HTTP 5xx)', function () {
    $client = makeToMarkdownClient([
        new Response(503, [], 'Service Unavailable'),
    ]);

    expect(fn () => $client->convert('bytes', 'doc.pdf'))
        ->toThrow(RuntimeException::class, 'doc.pdf');
});

test('convert tolerates missing data field (empty string)', function () {
    // A toMarkdown call that succeeded but yielded no text (e.g.,
    // an empty PDF) returns format=markdown with no data field.
    // We should surface that as an empty string, NOT throw — the
    // caller (CloudflareMarkdownParser) returns [] which the
    // controller already treats as "no extractable text".
    $client = makeToMarkdownClient([
        new Response(200, [], json_encode([
            'success' => true,
            'result' => [[
                'name' => 'blank.pdf',
                'format' => 'markdown',
            ]],
        ])),
    ]);

    expect($client->convert('bytes', 'blank.pdf'))->toBe('');
});
