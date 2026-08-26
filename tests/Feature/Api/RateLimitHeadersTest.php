<?php

use App\Http\Middleware\AddRateLimitResetHeader;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

test('middleware appends X-RateLimit-Reset when missing', function () {
    $middleware = new AddRateLimitResetHeader;
    $request = Request::create('/api/v1/widget/init');

    $response = $middleware->handle($request, function () {
        $r = new Response('ok');
        $r->headers->set('X-RateLimit-Limit', '60');
        $r->headers->set('X-RateLimit-Remaining', '58');

        return $r;
    });

    expect($response->headers->has('X-RateLimit-Reset'))->toBeTrue();
    expect((int) $response->headers->get('X-RateLimit-Reset'))
        ->toBeGreaterThan(time() + 30)
        ->toBeLessThanOrEqual(time() + 65);
});

test('middleware does not override existing Reset header', function () {
    $middleware = new AddRateLimitResetHeader;
    $request = Request::create('/api/v1/widget/init');

    $response = $middleware->handle($request, function () {
        $r = new Response('ok');
        $r->headers->set('X-RateLimit-Limit', '60');
        $r->headers->set('X-RateLimit-Remaining', '0');
        $r->headers->set('X-RateLimit-Reset', '99999');

        return $r;
    });

    expect($response->headers->get('X-RateLimit-Reset'))->toBe('99999');
});

test('middleware is a no-op when no rate limit headers present', function () {
    $middleware = new AddRateLimitResetHeader;
    $request = Request::create('/api/v1/widget/init');

    $response = $middleware->handle($request, function () {
        return new Response('ok');
    });

    expect($response->headers->has('X-RateLimit-Reset'))->toBeFalse();
});
