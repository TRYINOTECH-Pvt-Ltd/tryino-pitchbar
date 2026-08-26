<?php

use App\Support\CanonicalUrl;

test('strips fragment so /page#section equals /page', function () {
    expect(CanonicalUrl::for('https://shop.com/page#description'))
        ->toBe('https://shop.com/page');
    expect(CanonicalUrl::for('https://shop.com/products/red-pen#reviews'))
        ->toBe('https://shop.com/products/red-pen');
});

test('strips query strings so utm tracking does not split identity', function () {
    expect(CanonicalUrl::for('https://shop.com/page?utm_source=newsletter&utm_campaign=fall'))
        ->toBe('https://shop.com/page');
});

test('strips trailing slash on non-root paths', function () {
    expect(CanonicalUrl::for('https://shop.com/products/'))
        ->toBe('https://shop.com/products');
});

test('preserves the root slash', function () {
    expect(CanonicalUrl::for('https://shop.com/'))->toBe('https://shop.com/');
    expect(CanonicalUrl::for('https://shop.com'))->toBe('https://shop.com/');
});

test('lowercases scheme and host', function () {
    expect(CanonicalUrl::for('HTTPS://Shop.COM/Page'))
        ->toBe('https://shop.com/Page');
});

test('drops userinfo so credentials never become an identity', function () {
    expect(CanonicalUrl::for('https://alice:secret@shop.com/page'))
        ->toBe('https://shop.com/page');
});

test('preserves explicit port', function () {
    expect(CanonicalUrl::for('http://shop.com:8080/page'))
        ->toBe('http://shop.com:8080/page');
});

test('refuses non-http(s) schemes', function () {
    expect(CanonicalUrl::for('javascript:alert(1)'))->toBeNull();
    expect(CanonicalUrl::for('file:///etc/passwd'))->toBeNull();
    expect(CanonicalUrl::for('ftp://shop.com/x'))->toBeNull();
});

test('refuses malformed input', function () {
    expect(CanonicalUrl::for(''))->toBeNull();
    expect(CanonicalUrl::for('not-a-url'))->toBeNull();
    expect(CanonicalUrl::for('https://'))->toBeNull();
});

test('combinations of fragment + query + trailing slash all collapse', function () {
    $variants = [
        'https://shop.com/products/red-pen',
        'https://shop.com/products/red-pen/',
        'https://shop.com/products/red-pen?utm_source=foo',
        'https://shop.com/products/red-pen#reviews',
        'https://shop.com/products/red-pen/?utm=bar#tab=specs',
        'HTTPS://SHOP.COM/products/red-pen',
    ];
    foreach ($variants as $v) {
        expect(CanonicalUrl::for($v))->toBe('https://shop.com/products/red-pen');
    }
});
