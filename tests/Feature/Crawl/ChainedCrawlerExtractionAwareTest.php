<?php

use App\Services\Crawl\ChainedCrawler;
use App\Services\Crawl\Contracts\Crawler;
use App\Services\Crawl\HtmlExtractor;
use App\Services\Crawl\ReadabilityExtractor;

/**
 * Regression coverage for the pre-2026-06 bug: ChainedCrawler measured
 * raw HTML length. CF Browser Rendering would return 50KB of empty
 * `<div>` shells (JS-only SPAs that never hydrated server-side) →
 * chain accepted as "winner" → HtmlExtractor stripped it all → 30
 * chars text → CrawlPageJob bailed and the fallback tiers
 * (especially vision OCR) never got a chance.
 *
 * Refactored chain takes a ReadabilityExtractor and measures EXTRACTED
 * TEXT length instead of raw HTML. These tests pin that behaviour so
 * a future refactor can't regress us back to the raw-length check.
 */
function chainStub(callable $handler): Crawler
{
    return new class($handler) implements Crawler
    {
        public function __construct(private $handler) {}

        public function content(string $url, array $opts = []): string
        {
            return ($this->handler)($url, $opts);
        }
    };
}

function chainExtractor(): ReadabilityExtractor
{
    return new ReadabilityExtractor(new HtmlExtractor);
}

test('regression: huge raw HTML with empty body still demotes to next tier', function () {
    // This is the actual real-world failure mode. CF Browser Rendering
    // returns a fully-formed HTML document with all the chrome, scripts,
    // CSS, manifest links — but the body is just an Inertia shell with
    // a 200-char `<script data-page>` JSON blob inside. After
    // ReadabilityExtractor + HtmlExtractor, the extracted text is < 50
    // chars. Without the extraction-aware check, the chain stops here
    // and CrawlPageJob silently fails. The fix promotes us to the next
    // tier (vision OCR).
    $emptyShell = '<!DOCTYPE html><html lang="en"><head>'
        .str_repeat('<meta name="x" content="'.str_repeat('p', 200).'">', 20)
        .'<title>App</title><link rel="stylesheet" href="/app.css">'
        .'</head><body class="font-sans antialiased">'
        .'<script data-page="app" type="application/json">{"component":"home","props":{}}</script>'
        .'</body></html>';

    expect(mb_strlen($emptyShell))->toBeGreaterThan(4000);

    $rescueHtml = '<!DOCTYPE html><html><body><article>'
        .str_repeat('<p>Real content paragraph with enough words to clear the readability threshold for sure. </p>', 5)
        .'</article></body></html>';

    $chain = new ChainedCrawler(
        [
            ['name' => 'cf_browser', 'client' => chainStub(fn () => $emptyShell)],
            ['name' => 'cf_vision', 'client' => chainStub(fn () => $rescueHtml)],
        ],
        chainExtractor(),
    );

    $html = $chain->content('https://example.com');

    expect($html)->toBe($rescueHtml);
});

test('with extractor: raw HTML length is irrelevant — only extracted text counts', function () {
    $noisyShell = '<html>'.str_repeat('<div class="layout-shim"></div>', 500).'<body><p>tiny</p></body></html>';
    $rescueHtml = '<html><body>'
        .str_repeat('<p>Healthy paragraph with several words that survives stripping. </p>', 5)
        .'</body></html>';

    $chain = new ChainedCrawler(
        [
            ['name' => 'shim', 'client' => chainStub(fn () => $noisyShell)],
            ['name' => 'rescue', 'client' => chainStub(fn () => $rescueHtml)],
        ],
        chainExtractor(),
    );

    $html = $chain->content('https://example.com');

    expect($html)->toBe($rescueHtml);
});

test('without extractor: legacy raw-length behaviour preserved', function () {
    $chain = new ChainedCrawler([
        ['name' => 'legacy_winner', 'client' => chainStub(fn () => str_repeat('a', 500))],
        ['name' => 'never_called', 'client' => chainStub(fn () => str_repeat('b', 500))],
    ]);

    expect(mb_strlen($chain->content('https://example.com')))->toBe(500);
});

test('every tier failure surfaces composite error message', function () {
    $chain = new ChainedCrawler(
        [
            ['name' => 'cf_markdown', 'client' => chainStub(fn () => throw new RuntimeException('markdown empty'))],
            ['name' => 'cf_browser', 'client' => chainStub(fn () => '<html></html>')],
            ['name' => 'cf_vision', 'client' => chainStub(fn () => throw new RuntimeException('vision 5xx'))],
        ],
        chainExtractor(),
    );

    try {
        $chain->content('https://example.com');
        expect(true)->toBe(false, 'expected chain to throw');
    } catch (RuntimeException $e) {
        $msg = $e->getMessage();
        expect($msg)
            ->toContain('Every crawler tier failed')
            ->toContain('cf_markdown')
            ->toContain('markdown empty')
            ->toContain('cf_browser')
            ->toContain('cf_vision')
            ->toContain('vision 5xx');
    }
});
