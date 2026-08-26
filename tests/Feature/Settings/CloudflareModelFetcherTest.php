<?php

use App\Services\Llm\CloudflareModelFetcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config([
        'services.cloudflare.account_id' => 'acct_test',
        'services.cloudflare.api_token' => 'cf-token-test',
    ]);
});

it('parses the cloudflare ai/models/search response into ids', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'success' => true,
            'result' => [
                ['name' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast'],
                ['name' => '@cf/meta/llama-4-scout-17b-16e-instruct'],
                ['name' => '@cf/qwen/qwen2.5-72b-instruct'],
            ],
        ]),
    ]);

    $fetcher = new CloudflareModelFetcher;
    $result = $fetcher->fetch(force: true);

    expect($result['ok'])->toBeTrue()
        ->and($result['ids'])->toContain('@cf/meta/llama-3.3-70b-instruct-fp8-fast')
        ->and($result['ids'])->toContain('@cf/meta/llama-4-scout-17b-16e-instruct')
        ->and($result['ids'])->toContain('@cf/qwen/qwen2.5-72b-instruct')
        ->and($result['error'])->toBeNull()
        ->and($result['fetched_at'])->toBeString();
});

it('caches the result and reuses it without force=true', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'success' => true,
            'result' => [['name' => '@cf/cached/one']],
        ]),
    ]);

    $fetcher = new CloudflareModelFetcher;
    $fetcher->fetch(force: true);

    // Second call with force=false should hit the cache.
    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'success' => true,
            'result' => [['name' => '@cf/different/two']],
        ]),
    ]);

    $cached = $fetcher->fetch(force: false);
    expect($cached['ids'])->toContain('@cf/cached/one')
        ->and($cached['ids'])->not->toContain('@cf/different/two');
});

it('records an error when cloudflare credentials are missing', function () {
    config([
        'services.cloudflare.account_id' => '',
        'services.cloudflare.api_token' => '',
    ]);

    $fetcher = new CloudflareModelFetcher;
    $result = $fetcher->fetch(force: true);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('missing');
});

it('records an error when cloudflare returns non-2xx', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response(
            ['errors' => [['message' => 'bad token']]],
            401,
        ),
    ]);

    $fetcher = new CloudflareModelFetcher;
    $result = $fetcher->fetch(force: true);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('401');
});

it('records an error when the http call throws', function () {
    Http::fake(function () {
        throw new RuntimeException('dns failure');
    });

    $fetcher = new CloudflareModelFetcher;
    $result = $fetcher->fetch(force: true);

    expect($result['ok'])->toBeFalse()
        ->and($result['error'])->toContain('dns failure');
});

it('drops duplicate ids from the response', function () {
    Http::fake([
        'api.cloudflare.com/*' => Http::response([
            'success' => true,
            'result' => [
                ['name' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast'],
                ['name' => '@cf/meta/llama-3.3-70b-instruct-fp8-fast'],
                ['name' => '@cf/qwen/qwen2.5-7b-instruct'],
            ],
        ]),
    ]);

    $fetcher = new CloudflareModelFetcher;
    $result = $fetcher->fetch(force: true);

    expect($result['ids'])->toHaveCount(2);
});
