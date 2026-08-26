<?php

use App\Services\Crawl\ChainedCrawler;
use App\Services\Crawl\Contracts\Crawler;

/**
 * Card #435 — a TLS certificate that covers only the apex domain fails every
 * crawler tier for a www.<domain> URL with cURL error 60 ("no alternative
 * certificate subject name matches target host name 'www.…'"). ChainedCrawler
 * retries the apex host ONCE for exactly that signature, so a common
 * misconfiguration (blengi.com's cert not covering www.blengi.com) stops
 * black-holing the page and the pricing content keeps re-indexing.
 */
function fallbackStub(callable $handler): Crawler
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

$cert60 = 'cURL error 60: SSL: no alternative certificate subject name matches target host name \'www.example.com\'';

test('retries the apex host when every tier fails with a cert host mismatch on a www URL', function () use ($cert60) {
    $calls = [];
    $chain = new ChainedCrawler([
        ['name' => 'plain', 'client' => fallbackStub(function (string $url) use (&$calls, $cert60) {
            $calls[] = $url;
            if (str_starts_with((string) parse_url($url, PHP_URL_HOST), 'www.')) {
                throw new RuntimeException($cert60);
            }

            return str_repeat('a', 500); // the apex serves the real content
        })],
    ]);

    $html = $chain->content('https://www.example.com/pricing');

    expect(mb_strlen($html))->toBe(500)
        ->and($calls)->toBe([
            'https://www.example.com/pricing',
            'https://example.com/pricing',
        ]);
});

test('apex fallback preserves scheme, port, path and query', function () use ($cert60) {
    $calls = [];
    $chain = new ChainedCrawler([
        ['name' => 'plain', 'client' => fallbackStub(function (string $url) use (&$calls, $cert60) {
            $calls[] = $url;
            if (str_starts_with((string) parse_url($url, PHP_URL_HOST), 'www.')) {
                throw new RuntimeException($cert60);
            }

            return str_repeat('b', 500);
        })],
    ]);

    $chain->content('https://www.example.com:8443/plans?tier=growth');

    expect($calls[1])->toBe('https://example.com:8443/plans?tier=growth');
});

test('does NOT retry the apex for a non-cert failure on a www host', function () {
    $calls = [];
    $chain = new ChainedCrawler([
        ['name' => 'plain', 'client' => fallbackStub(function (string $url) use (&$calls) {
            $calls[] = $url;
            throw new RuntimeException('HTTP 500 Server Error');
        })],
    ]);

    expect(fn () => $chain->content('https://www.example.com/pricing'))
        ->toThrow(RuntimeException::class, 'Every crawler tier failed');
    expect($calls)->toBe(['https://www.example.com/pricing']);
});

test('does NOT retry the apex for a cert mismatch on a non-www host', function () use ($cert60) {
    $calls = [];
    $chain = new ChainedCrawler([
        ['name' => 'plain', 'client' => fallbackStub(function (string $url) use (&$calls, $cert60) {
            $calls[] = $url;
            throw new RuntimeException($cert60);
        })],
    ]);

    expect(fn () => $chain->content('https://app.example.com/pricing'))
        ->toThrow(RuntimeException::class, 'Every crawler tier failed');
    expect($calls)->toBe(['https://app.example.com/pricing']);
});
