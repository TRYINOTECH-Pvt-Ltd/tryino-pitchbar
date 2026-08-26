<?php

use App\Services\Crawl\ChainedCrawler;
use App\Services\Crawl\Contracts\Crawler;

/**
 * Pin ChainedCrawler's per-request tier fallback behaviour.
 */
function stubCrawler(callable $handler): Crawler
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

test('first tier returning non-empty HTML wins; lower tiers never called', function () {
    $callCount = ['cf' => 0, 'browserless' => 0, 'plain' => 0];

    $chain = new ChainedCrawler([
        ['name' => 'cf', 'client' => stubCrawler(function () use (&$callCount) {
            $callCount['cf']++;

            return str_repeat('a', 500); // healthy response
        })],
        ['name' => 'browserless', 'client' => stubCrawler(function () use (&$callCount) {
            $callCount['browserless']++;

            return 'should-never-run';
        })],
        ['name' => 'plain', 'client' => stubCrawler(function () use (&$callCount) {
            $callCount['plain']++;

            return 'should-never-run';
        })],
    ]);

    $html = $chain->content('https://example.com');

    expect(mb_strlen($html))->toBe(500);
    expect($callCount)->toBe(['cf' => 1, 'browserless' => 0, 'plain' => 0]);
});

test('falls forward when the first tier throws', function () {
    $callCount = ['cf' => 0, 'browserless' => 0];

    $chain = new ChainedCrawler([
        ['name' => 'cf', 'client' => stubCrawler(function () use (&$callCount) {
            $callCount['cf']++;
            throw new RuntimeException('CF 401 Unauthorized');
        })],
        ['name' => 'browserless', 'client' => stubCrawler(function () use (&$callCount) {
            $callCount['browserless']++;

            return str_repeat('b', 500);
        })],
    ]);

    $html = $chain->content('https://example.com');

    expect(mb_strlen($html))->toBe(500);
    expect($callCount)->toBe(['cf' => 1, 'browserless' => 1]);
});

test('treats short body as failure and rolls forward', function () {
    $chain = new ChainedCrawler([
        ['name' => 'cf', 'client' => stubCrawler(fn () => '<html></html>')], // 13 chars
        ['name' => 'plain', 'client' => stubCrawler(fn () => str_repeat('c', 500))],
    ]);

    $html = $chain->content('https://example.com');

    expect(mb_strlen($html))->toBe(500);
});

test('exhausting the chain composes errors into a final exception', function () {
    $chain = new ChainedCrawler([
        ['name' => 'cf', 'client' => stubCrawler(function () {
            throw new RuntimeException('CF down');
        })],
        ['name' => 'browserless', 'client' => stubCrawler(function () {
            throw new RuntimeException('Browserless 401');
        })],
        ['name' => 'plain', 'client' => stubCrawler(fn () => '')], // empty body
    ]);

    expect(fn () => $chain->content('https://example.com'))
        ->toThrow(RuntimeException::class, 'Every crawler tier failed');
});

test('empty chain throws immediately', function () {
    expect(fn () => (new ChainedCrawler([]))->content('https://example.com'))
        ->toThrow(RuntimeException::class, 'Crawler chain is empty');
});
