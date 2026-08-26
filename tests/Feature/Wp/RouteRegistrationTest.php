<?php

use Illuminate\Support\Facades\Route;

/**
 * Battle-test: every route the WordPress plugin work introduced is
 * actually registered. Cheap regression net — if someone removes a
 * route group by accident this test breaks before the plugin ever
 * tries to call it.
 */
test('every wp + widget route the plugin integration relies on is registered', function () {
    $expected = [
        // P0 + P1 — handshake + tokens
        ['POST', 'api/v1/wp/handshake'],
        // P2 — posts
        ['POST', 'api/v1/wp/posts/sync'],
        ['POST', 'api/v1/wp/posts/changed'],
        // P3 — products
        ['POST', 'api/v1/wp/products/sync'],
        ['POST', 'api/v1/wp/products/changed'],
        // P4 — widget init (shopper_token accepted via body, no separate route)
        ['POST', 'api/v1/widget/init'],
        // P5 — coupons + apply proxy
        ['POST', 'api/v1/wp/coupons/sync'],
        ['POST', 'api/v1/widget/coupon/apply'],
    ];

    $routes = collect(Route::getRoutes())->map(fn ($r) => [
        'methods' => $r->methods(),
        'uri' => $r->uri(),
    ]);

    foreach ($expected as [$method, $uri]) {
        $match = $routes->first(fn ($r) => $r['uri'] === $uri && in_array($method, $r['methods'], true));
        expect($match)->not()->toBeNull("Route {$method} {$uri} is not registered.");
    }
});

test('every wp mutation endpoint is guarded by hmac.signature middleware', function () {
    $hmacGuarded = [
        'api/v1/wp/posts/sync',
        'api/v1/wp/posts/changed',
        'api/v1/wp/products/sync',
        'api/v1/wp/products/changed',
        'api/v1/wp/coupons/sync',
    ];

    foreach ($hmacGuarded as $uri) {
        $route = collect(Route::getRoutes())->first(fn ($r) => $r->uri() === $uri);
        expect($route)->not()->toBeNull("Route {$uri} missing.");

        $middleware = $route->gatherMiddleware();
        $hasHmac = collect($middleware)->contains(fn ($m) => str_contains((string) $m, 'hmac.signature'));
        $hasAuth = collect($middleware)->contains(fn ($m) => str_contains((string) $m, 'auth.api_token'));

        expect($hasHmac)->toBeTrue("Route {$uri} missing hmac.signature middleware.");
        expect($hasAuth)->toBeTrue("Route {$uri} missing auth.api_token middleware.");
    }
});

test('widget coupon apply uses widget JWT path (not auth.api_token)', function () {
    $route = collect(Route::getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/widget/coupon/apply');
    expect($route)->not()->toBeNull();

    $middleware = $route->gatherMiddleware();
    expect(collect($middleware)->contains(fn ($m) => str_contains((string) $m, 'auth.api_token')))
        ->toBeFalse('widget/coupon/apply should NOT be auth.api_token gated — visitor JWT only.');
});
