<?php

use App\Services\Cloudflare\ToMarkdownClient;
use App\Services\Parsers\CloudflareMarkdownParser;
use Mockery as M;

/**
 * Unit tests for the CloudflareMarkdownParser wrapper. We're not
 * exercising the HTTP layer here (ToMarkdownClientTest does that) —
 * we're testing the parse-to-segments contract:
 *
 *   - Markdown returned by Cloudflare is split on H1/H2 so each
 *     section becomes its own Document, matching TextParser behaviour.
 *   - Empty markdown yields zero segments (UploadController records
 *     "no extractable text" instead of creating a zero-chunk doc).
 *   - Client exceptions bubble (UploadController records per-file
 *     errors on the source).
 *   - supports() only opts in for binary office formats — .csv / .md /
 *     .txt stay on the local parsers.
 */
afterEach(fn () => M::close());

function fakeToMarkdownReturning(string $markdown): ToMarkdownClient
{
    $client = M::mock(ToMarkdownClient::class);
    $client->shouldReceive('convert')->andReturn($markdown);

    return $client;
}

test('parse splits Cloudflare markdown by H1/H2 headings into segments', function () {
    $client = fakeToMarkdownReturning(<<<'MD'
    # Section One

    Body of one.

    ## Subsection 1.1

    Detail.

    # Section Two

    Body of two.
    MD);

    $parser = new CloudflareMarkdownParser($client);
    $segments = $parser->parse('any-bytes', 'doc.pdf');

    expect($segments)->toHaveCount(3);
    expect($segments[0])->toStartWith('# Section One');
    expect($segments[1])->toStartWith('## Subsection 1.1');
    expect($segments[2])->toStartWith('# Section Two');
});

test('parse returns [] when Cloudflare returns empty markdown', function () {
    $parser = new CloudflareMarkdownParser(fakeToMarkdownReturning(''));

    expect($parser->parse('any', 'blank.pdf'))->toBe([]);
});

test('parse propagates client failures so the controller can record per-file errors', function () {
    $client = M::mock(ToMarkdownClient::class);
    $client->shouldReceive('convert')->andThrow(new RuntimeException('toMarkdown 503'));

    $parser = new CloudflareMarkdownParser($client);

    expect(fn () => $parser->parse('any', 'doc.pdf'))
        ->toThrow(RuntimeException::class, 'toMarkdown 503');
});

test('supports binary office formats but NOT .csv / .md / .txt', function () {
    $parser = new CloudflareMarkdownParser(fakeToMarkdownReturning(''));

    expect($parser->supports('pdf'))->toBeTrue();
    expect($parser->supports('PDF'))->toBeTrue();
    expect($parser->supports('docx'))->toBeTrue();
    expect($parser->supports('doc'))->toBeTrue();
    expect($parser->supports('xlsx'))->toBeTrue();
    expect($parser->supports('odt'))->toBeTrue();
    expect($parser->supports('ods'))->toBeTrue();

    // CSV stays on League — row-by-row output reads better.
    expect($parser->supports('csv'))->toBeFalse();
    // Plain text stays on TextParser — no point round-tripping.
    expect($parser->supports('md'))->toBeFalse();
    expect($parser->supports('txt'))->toBeFalse();
    expect($parser->supports('html'))->toBeFalse();
});

test('parse with no headings emits a single segment (whole document)', function () {
    $parser = new CloudflareMarkdownParser(fakeToMarkdownReturning(
        'Just one paragraph of body text with no markdown headings.'
    ));

    $segments = $parser->parse('any', 'doc.pdf');
    expect($segments)->toHaveCount(1);
    expect($segments[0])->toBe('Just one paragraph of body text with no markdown headings.');
});
