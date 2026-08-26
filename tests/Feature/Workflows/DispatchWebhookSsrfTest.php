<?php

use App\Jobs\Workflows\DispatchWebhookJob;
use App\Support\UrlSafetyGuard;
use Illuminate\Support\Facades\Http;

test('DispatchWebhookJob blocks SSRF targets (audit 2026-05-16 iter 13)', function (string $url) {
    // Pre-fix workflow admin could configure a webhook node targeting
    // `http://localhost:6379` (Redis), `http://169.254.169.254/...`
    // (AWS metadata), `http://10.0.0.1/admin`, etc. Now blocked.
    Http::fake();

    (new DispatchWebhookJob(
        url: $url,
        method: 'POST',
        payload: ['hello' => 'world'],
    ))->handle(app(UrlSafetyGuard::class));

    // No outbound request should fire when the URL fails the safety
    // check.
    Http::assertNothingSent();
})->with([
    'localhost' => ['http://localhost/admin'],
    'loopback IPv4' => ['http://127.0.0.1:6379'],
    'AWS metadata' => ['http://169.254.169.254/latest/meta-data/'],
    'RFC1918 10/8' => ['http://10.0.0.1/'],
    'RFC1918 192.168/16' => ['http://192.168.1.1/'],
    'IPv6 loopback' => ['http://[::1]/'],
    '.internal TLD' => ['http://api.internal/'],
]);

test('DispatchWebhookJob permits public URLs', function () {
    Http::fake([
        'example.com/*' => Http::response(['ok' => true], 200),
    ]);

    (new DispatchWebhookJob(
        url: 'https://example.com/webhook',
        method: 'POST',
        payload: ['hello' => 'world'],
    ))->handle(app(UrlSafetyGuard::class));

    Http::assertSent(fn ($req) => $req->url() === 'https://example.com/webhook');
});
