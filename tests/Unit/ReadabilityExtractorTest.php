<?php

use App\Services\Crawl\HtmlExtractor;
use App\Services\Crawl\ReadabilityExtractor;

beforeEach(function () {
    $this->extractor = new ReadabilityExtractor(new HtmlExtractor);
});

test('returns empty for empty input', function () {
    $out = $this->extractor->extract('');
    expect($out)->toBe(['title' => null, 'text' => '']);
});

test('extracts the article body and ignores chrome around it', function () {
    $body = str_repeat('The quick brown fox jumps over the lazy dog. ', 30);
    $html = <<<HTML
    <html>
      <head><title>Hello</title></head>
      <body>
        <header><nav><a href="#">Home</a><a href="#">About</a></nav></header>
        <aside class="sidebar"><ul><li>Sidebar link 1</li><li>Sidebar link 2</li></ul></aside>
        <article>
          <h1>Hello</h1>
          <p>{$body}</p>
        </article>
        <footer>Copyright 2026</footer>
      </body>
    </html>
    HTML;

    $out = $this->extractor->extract($html);

    expect($out['text'])->toContain('quick brown fox');
    expect($out['text'])->not->toContain('Sidebar link 1');
    expect($out['text'])->not->toContain('Copyright 2026');
});

test('falls back to legacy HtmlExtractor when Readability returns thin content', function () {
    // Page is too short for Readability to confidently identify an article;
    // the wrapper should fall through to HtmlExtractor and still return
    // something usable.
    $html = '<html><body><p>Just one short sentence.</p></body></html>';

    $out = $this->extractor->extract($html);

    // HtmlExtractor returns the bare text — no title from <title> here.
    expect($out['text'])->toContain('Just one short sentence');
});

test('handles malformed HTML gracefully (no exception bubbles up)', function () {
    $broken = '<html><body><div>unclosed div<p>still trying</body></html';

    $out = $this->extractor->extract($broken);

    expect($out)->toHaveKey('text');
    // Should not throw; fallback handles it.
});
