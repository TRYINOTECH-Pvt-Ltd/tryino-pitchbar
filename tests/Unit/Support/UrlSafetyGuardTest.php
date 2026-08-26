<?php

use App\Support\Exceptions\UnsafeUrlException;
use App\Support\UrlSafetyGuard;

/**
 * Pins every SSRF vector the security audit flagged. Adding new
 * patterns is fine; removing one is a security regression and these
 * tests are the only thing standing between you and shipping it.
 *
 * Defaults to pattern-only (no DNS) for callers that mention
 * `resolveHostnames: false` — that's the path AutoIndexPageVisit
 * uses on /widget/init where every millisecond shows up in the p95.
 * Crawl-job callers flip resolution on and pay the DNS cost.
 */
function guard(): UrlSafetyGuard
{
    return new UrlSafetyGuard;
}

test('rejects every private/loopback/link-local literal IPv4', function (string $url) {
    expect(fn () => guard()->assertSafe($url))
        ->toThrow(UnsafeUrlException::class);
})->with([
    'localhost' => ['http://localhost/'],
    '127.0.0.1' => ['http://127.0.0.1/foo'],
    '127.0.0.7' => ['http://127.0.0.7/'],
    '10/8' => ['http://10.0.0.5/admin'],
    '192.168/16' => ['http://192.168.1.1/'],
    '172.16/12 (low edge)' => ['http://172.16.0.1/'],
    '172.16/12 (high edge)' => ['http://172.31.255.254/'],
    '169.254 link-local + AWS metadata' => ['http://169.254.169.254/latest/meta-data/'],
    '0.0.0.0' => ['http://0.0.0.0/'],
    '*.local mDNS' => ['http://foo.local/'],
    '*.internal' => ['http://service.internal/'],
]);

test('rejects IPv6 loopback + private + link-local', function (string $url) {
    expect(fn () => guard()->assertSafe($url))
        ->toThrow(UnsafeUrlException::class);
})->with([
    '::1 IPv6 loopback' => ['http://[::1]/'],
    'fe80:: link-local' => ['http://[fe80::1]/'],
    'fc00::/7 ULA (fc-prefix)' => ['http://[fc00::1]/'],
    'fc00::/7 ULA (fd-prefix)' => ['http://[fd00::1]/'],
]);

test('rejects non-http(s) schemes', function (string $url) {
    expect(fn () => guard()->assertSafe($url))
        ->toThrow(UnsafeUrlException::class);
})->with([
    'file://' => ['file:///etc/passwd'],
    'gopher://' => ['gopher://localhost:6379/'],
    'ftp://' => ['ftp://example.com/'],
    'data:' => ['data:text/plain,hi'],
    'javascript:' => ['javascript:alert(1)'],
]);

test('rejects unparseable URLs', function (string $url) {
    expect(fn () => guard()->assertSafe($url))
        ->toThrow(UnsafeUrlException::class);
})->with([
    'empty' => [''],
    'no scheme' => ['/just/a/path'],
    'no host' => ['https:///path-only'],
    'garbage' => ['not a url at all'],
]);

test('accepts ordinary public URLs', function (string $url) {
    expect(guard()->isSafe($url))->toBeTrue();
})->with([
    'apex' => ['https://example.com'],
    'subdomain + path + query' => ['https://docs.example.com/intro?v=1'],
    'http (no s)' => ['http://example.com/'],
    'numeric public IPv4 (Cloudflare DNS)' => ['http://1.1.1.1/'],
    'numeric public IPv6 (Google DNS)' => ['http://[2001:4860:4860::8888]/'],
]);

test('pattern check fires before DNS — no network calls for obvious bad hosts', function () {
    // Even with resolveHostnames=true, an obvious 127.x literal must
    // be rejected by the cheap pattern check first. We don't have a
    // direct way to count DNS calls, but this verifies the throw is
    // immediate (not hanging on resolution).
    $start = microtime(true);
    try {
        guard()->assertSafe('http://127.0.0.1/', resolveHostnames: true);
        fail('Expected throw');
    } catch (UnsafeUrlException) {
        // Should be a sub-millisecond pattern check, NOT a 5+ms DNS.
        expect(microtime(true) - $start)->toBeLessThan(0.1);
    }
});

test('isSafe with resolveHostnames=false skips DNS even for non-numeric hosts', function () {
    // example.com is RFC2606-reserved — depending on the resolver it
    // may or may not have public A records. Without resolveHostnames
    // we should ALWAYS accept it (pattern check passes; no DNS run).
    expect(guard()->isSafe('https://example.com/path', resolveHostnames: false))->toBeTrue();
});
