<?php

use App\Services\Crawl\PlainHttpCrawler;
use App\Support\UrlSafetyGuard;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;

function plainCrawlerWith(MockHandler $mock): PlainHttpCrawler
{
    return new PlainHttpCrawler(
        new UrlSafetyGuard,
        new Client(['handler' => HandlerStack::create($mock)]),
    );
}

test('PlainHttpCrawler returns response body for 200', function () {
    $crawler = plainCrawlerWith(new MockHandler([
        new Response(200, [], '<html>hi</html>'),
    ]));

    expect($crawler->content('https://example.com'))->toBe('<html>hi</html>');
});

test('PlainHttpCrawler throws on 4xx/5xx', function () {
    $crawler = plainCrawlerWith(new MockHandler([
        new Response(503, [], 'down'),
    ]));

    $crawler->content('https://example.com');
})->throws(RuntimeException::class, '503');

test('PlainHttpCrawler follows safe redirects', function () {
    $crawler = plainCrawlerWith(new MockHandler([
        new Response(302, ['Location' => 'https://example.com/final']),
        new Response(200, [], '<html>final</html>'),
    ]));

    expect($crawler->content('https://example.com'))->toBe('<html>final</html>');
});

test('PlainHttpCrawler refuses a redirect to an unsafe host', function () {
    $crawler = plainCrawlerWith(new MockHandler([
        new Response(302, ['Location' => 'http://169.254.169.254/']),
    ]));

    expect(fn () => $crawler->content('https://example.com'))
        ->toThrow(RuntimeException::class, 'unsafe URL');
});
